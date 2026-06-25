<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

interface OutboxProcessorInterface
{
    public function processOne(int $rowId): OutboxDisposition;

    /**
     * Process a single outbox row synchronously (alias for processOne).
     *
     * Provided as a distinct method so that call-sites that process rows
     * immediately after insertion (rather than via the consumer loop) are
     * self-documenting and testable via this interface.
     */
    public function processSync(int $rowId): OutboxDisposition;
}
