<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

/**
 * Post-success cascade-evaluation contract.
 *
 * Extracted so ConsumerLoop and BackstopRunner can depend on an
 * abstraction rather than the final CascadeReconciler concrete class,
 * matching the established pattern with OutboxProcessor /
 * OutboxProcessorInterface and RedisStreamConsumer / StreamConsumerInterface.
 */
interface CascadeReconcilerInterface
{
    public function evaluate(int $rowId): void;
}
