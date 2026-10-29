<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use Psr\Log\LoggerInterface;

/**
 * Separates the permissions of an access request that are worth asking for from those that are redundant.
 *
 * A requested permission is redundant when the same request already asks for the object at an equal or higher
 * relation (`duplicate`), when the user already has a pending request for it at an equal or higher relation
 * (`pending`), or when OpenFGA already grants it (`granted`).
 *
 * The OpenFGA lookup fails open: an error, an unconfigured client or a spent time budget keeps the permission,
 * because asking for access that turns out to be redundant is harmless, whereas silently dropping a legitimate
 * request is not.
 *
 * @phpstan-type Permission array{object_type: string, object_id: string, relation: string}
 * @phpstan-type SkippedPermission array{object_type: string, object_id: string, relation: string, reason: string}
 */
final class AccessRequestRedundancyFilter
{
    /**
     * Relation strength. `admin` implies `editor` implies `viewer` in the authorization model.
     */
    public const RELATION_RANK = ['viewer' => 1, 'editor' => 2, 'admin' => 3];

    private ?LoggerInterface $logger;

    private readonly float $budgetSeconds;

    private ?float $deadline = null;

    private int $skippedForBudget = 0;

    public function __construct(
        private readonly ?OpenFgaClient $fgaClient,
        ?LoggerInterface $logger = null,
        ?float $budgetSeconds = null
    ) {
        $this->logger        = $logger;
        $this->budgetSeconds = $budgetSeconds ?? ResourceAdminService::FANOUT_BUDGET_SECONDS;
    }

    /**
     * @param string $sub Zitadel user ID (without "user:" prefix)
     * @param list<Permission> $permissions Requested permissions
     * @param list<Permission> $pendingPermissions Permissions of the user's pending requests
     * @return array{kept: list<Permission>, skipped: list<SkippedPermission>}
     */
    public function filter(string $sub, array $permissions, array $pendingPermissions): array
    {
        $kept    = [];
        $skipped = [];

        // 1. duplicate: per object, only the first entry with the highest relation survives.
        $best = [];
        foreach ($permissions as $i => $perm) {
            $key  = $perm['object_type'] . "\0" . $perm['object_id'];
            $rank = self::rank($perm['relation']);
            if (!isset($best[$key]) || $rank > $best[$key]['rank']) {
                $best[$key] = ['index' => $i, 'rank' => $rank];
            }
        }

        // 2. pending: highest pending rank per canonical object.
        $pendingRank = [];
        foreach ($pendingPermissions as $perm) {
            $key               = $perm['object_type'] . "\0" . AccessRequestRepository::canonicalObjectId($perm['object_type'], $perm['object_id']);
            $pendingRank[$key] = max($pendingRank[$key] ?? 0, self::rank($perm['relation']));
        }

        $this->skippedForBudget = 0;
        try {
            foreach ($permissions as $i => $perm) {
                $key = $perm['object_type'] . "\0" . $perm['object_id'];

                if ($best[$key]['index'] !== $i) {
                    $skipped[] = $perm + ['reason' => 'duplicate'];
                    continue;
                }
                if (( $pendingRank[$key] ?? 0 ) >= self::rank($perm['relation'])) {
                    $skipped[] = $perm + ['reason' => 'pending'];
                    continue;
                }
                if ($this->isGranted($sub, $perm)) {
                    $skipped[] = $perm + ['reason' => 'granted'];
                    continue;
                }
                $kept[] = $perm;
            }
        } finally {
            $this->reportBudgetSkips();
        }

        return ['kept' => $kept, 'skipped' => $skipped];
    }

    private static function rank(string $relation): int
    {
        return self::RELATION_RANK[$relation] ?? 0;
    }

    /**
     * @param Permission $perm
     */
    private function isGranted(string $sub, array $perm): bool
    {
        if ($this->fgaClient === null) {
            return false;
        }
        if (!$this->withinBudget()) {
            return false;
        }

        try {
            return $this->fgaClient->check("user:{$sub}", $perm['relation'], $perm['object_type'] . ':' . $perm['object_id']);
        } catch (\RuntimeException $e) {
            $this->logFailure(
                sprintf(
                    'OpenFGA check failed while filtering redundant access request permissions for %s:%s: %s',
                    $perm['object_type'],
                    $perm['object_id'],
                    $e->getMessage()
                ),
                [
                    'object_type' => $perm['object_type'],
                    'object_id'   => $perm['object_id'],
                    'relation'    => $perm['relation'],
                    'error'       => $e->getMessage(),
                ]
            );
            return false;
        }
    }

    private function withinBudget(): bool
    {
        $now              = hrtime(true) / 1e9;
        $this->deadline ??= $now + $this->budgetSeconds;

        if ($now < $this->deadline) {
            return true;
        }

        ++$this->skippedForBudget;

        return false;
    }

    private function reportBudgetSkips(): void
    {
        $skipped                = $this->skippedForBudget;
        $this->skippedForBudget = 0;

        if ($skipped === 0) {
            return;
        }

        $this->logFailure(
            sprintf(
                'OpenFGA budget of %.1fs exhausted while filtering redundant access request permissions: %d check(s) skipped and kept',
                $this->budgetSeconds,
                $skipped
            ),
            ['skipped' => $skipped, 'budget_seconds' => $this->budgetSeconds]
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logFailure(string $message, array $context): void
    {
        try {
            $this->logger ??= LoggerFactory::create('auth', null, 30, false, true, false);
            $this->logger->error($message, $context);
        } catch (\Throwable) {
            // Best-effort diagnostics: the fail-open result matters more.
        }
    }
}
