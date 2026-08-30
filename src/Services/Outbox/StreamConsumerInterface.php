<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

interface StreamConsumerInterface
{
    public function ensureGroup(): void;

    /**
     * Read one message (or batch) and invoke `$process` with the payload field's RAW STRING value.
     *
     * String, not int, because the two streams that use this carry different id types: the OpenFGA
     * outbox's unit of work is an integer row id, while the source-data publisher's is a batch id,
     * which is a UUID. Validating and narrowing the value is the caller's job — this layer no
     * longer knows what a valid id looks like.
     *
     * @param callable(string): void $process
     */
    public function readOnce(int $blockMs, callable $process): void;
}
