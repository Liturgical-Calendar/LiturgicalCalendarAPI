<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

interface StreamConsumerInterface
{
    public function ensureGroup(): void;

    /**
     * @param callable(int): void $process
     */
    public function readOnce(int $blockMs, callable $process): void;
}
