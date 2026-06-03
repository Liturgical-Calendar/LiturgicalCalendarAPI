<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

interface OutboxProcessorInterface
{
    public function processOne(int $rowId): OutboxDisposition;
}
