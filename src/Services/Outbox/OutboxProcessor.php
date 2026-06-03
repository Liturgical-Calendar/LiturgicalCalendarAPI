<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\Exception\OpenFgaApiException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;

/**
 * Single point of contact between the outbox and OpenFGA.
 *
 * Called from three places: the handler's sync fast path (after the
 * surrounding tx has committed), the consumer's XREADGROUP loop, and
 * the cron backstop's pickupPending scan. They all use the same
 * processOne() so classification, retry scheduling, and status
 * transitions live in exactly one file.
 *
 * processOne() is idempotent on terminal rows — re-running on a
 * succeeded or failed_terminal row is a no-op.
 */
final class OutboxProcessor implements OutboxProcessorInterface
{
    private readonly int $maxAttempts;

    public function __construct(
        private readonly OutboxRepository $repo,
        private readonly OpenFgaClient $client,
        int $maxAttempts = 10,
    ) {
        $this->maxAttempts = $maxAttempts;
    }

    public function processOne(int $rowId): OutboxDisposition
    {
        $row = $this->repo->getById($rowId);
        if ($row === null) {
            // Row was deleted between pickup and processOne — nothing to do.
            return OutboxDisposition::BENIGN_SUCCESS;
        }

        if ($row->status === OutboxStatus::SUCCEEDED || $row->status === OutboxStatus::FAILED_TERMINAL) {
            // Idempotency anchor — re-running on a terminal row no-ops.
            return OutboxDisposition::BENIGN_SUCCESS;
        }

        // If the row is in RETRYING and its scheduled next attempt is still
        // in the future, honor the backoff window. The backstop's
        // pickupPending() already filters on next_attempt_at, but the
        // consumer's XCLAIM-from-PEL path can hand us a row whose retry
        // was just rescheduled by another runner; don't bypass the schedule.
        // Vatican TZ to match the repo's storage convention.
        if ($row->status === OutboxStatus::RETRYING) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Vatican'));
            if ($row->nextAttemptAt > $now) {
                return OutboxDisposition::BENIGN_SUCCESS;
            }
        }

        try {
            $this->invoke($row);
            $this->repo->markSucceeded($row->id);
            return OutboxDisposition::BENIGN_SUCCESS;
        } catch (\Throwable $e) {
            $disposition = OutboxClassifier::classify($e);
            $code        = $e instanceof OpenFgaApiException ? $e->getErrorCode() : null;
            $message     = $e->getMessage();

            switch ($disposition) {
                case OutboxDisposition::BENIGN_SUCCESS:
                    $this->repo->markSucceeded($row->id);
                    return OutboxDisposition::BENIGN_SUCCESS;

                case OutboxDisposition::TERMINAL:
                    $this->repo->markFailedTerminal($row->id, $message, $code);
                    return OutboxDisposition::TERMINAL;

                case OutboxDisposition::RETRY:
                    $newAttempts = $row->attempts + 1;
                    if ($newAttempts >= $this->maxAttempts) {
                        $this->repo->markFailedTerminal($row->id, $message, $code);
                        return OutboxDisposition::TERMINAL;
                    }
                    $delay = OutboxBackoff::secondsForAttempt($newAttempts);
                    $next  = ( new \DateTimeImmutable('now', new \DateTimeZone('Europe/Vatican')) )
                        ->modify("+{$delay} seconds");
                    $this->repo->markRetryable($row->id, $newAttempts, $next, $message, $code);
                    return OutboxDisposition::RETRY;
            }
        }
    }

    /**
     * Convenience alias used by handlers in the sync fast path.
     *
     * Same semantics as processOne — separate method to make the call
     * site read as "sync attempt" rather than "any-context attempt".
     */
    public function processSync(int $rowId): OutboxDisposition
    {
        return $this->processOne($rowId);
    }

    private function invoke(OutboxRow $row): void
    {
        match ($row->operation) {
            OutboxOperation::WRITE_TUPLE  => $this->client->writeTuple($row->fgaUser, $row->fgaRelation, $row->fgaObject),
            OutboxOperation::DELETE_TUPLE => $this->client->deleteTuple($row->fgaUser, $row->fgaRelation, $row->fgaObject),
        };
    }
}
