<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

/**
 * Long-lived consumer loop body.
 *
 * tick() does one readOnce + process cycle (the unit-testable part).
 * run() is the outer while (true), excluded from coverage. Splitting
 * keeps the test pyramid honest.
 *
 * Optionally wires a CascadeReconcilerInterface that's invoked after
 * every BENIGN_SUCCESS to bridge the outbox row's success into the
 * Zitadel role-cascade decision. The reconciler is constructor-optional
 * so existing tests and tooling that don't need cascade can still
 * construct a ConsumerLoop with the original 3-arg signature.
 */
final class ConsumerLoop
{
    private bool $groupEnsured = false;

    public function __construct(
        private readonly StreamConsumerInterface $consumer,
        private readonly OutboxProcessorInterface $processor,
        private readonly int $blockMs = 5000,
        private readonly ?CascadeReconcilerInterface $cascade = null,
    ) {
    }

    public function tick(): void
    {
        if (!$this->groupEnsured) {
            $this->consumer->ensureGroup();
            $this->groupEnsured = true;
        }
        $this->consumer->readOnce(
            $this->blockMs,
            function (int $rowId): void {
                $disposition = $this->processor->processOne($rowId);
                if ($disposition === OutboxDisposition::BENIGN_SUCCESS && $this->cascade !== null) {
                    try {
                        $this->cascade->evaluate($rowId);
                    } catch (\Throwable) {
                        // Never fail the consumer over a cascade decision — the row
                        // is already in succeeded state and a future sibling success
                        // (or admin re-revoke) will trigger evaluate() again.
                    }
                }
            },
        );
    }

    /**
     * Forever. systemd restarts on crash.
     *
     * @codeCoverageIgnore
     */
    public function run(): never
    {
        while (true) {
            $this->tick();
        }
    }
}
