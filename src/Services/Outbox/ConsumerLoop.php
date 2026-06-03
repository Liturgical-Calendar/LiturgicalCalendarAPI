<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

/**
 * Long-lived consumer loop body.
 *
 * tick() does one readOnce + process cycle (the unit-testable part).
 * run() is the outer while (true), excluded from coverage. Splitting
 * keeps the test pyramid honest.
 */
final class ConsumerLoop
{
    private bool $groupEnsured = false;

    public function __construct(
        private readonly StreamConsumerInterface $consumer,
        private readonly OutboxProcessorInterface $processor,
        private readonly int $blockMs = 5000,
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
                $this->processor->processOne($rowId);
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
