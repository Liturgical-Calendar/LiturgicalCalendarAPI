<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * Contract for purging operational OpenFGA tuples for a deleted resource.
 *
 * Enqueues DELETE_TUPLE outbox rows for every operational (editor/viewer)
 * tuple on the given FGA object, then processes them synchronously.
 * Governance (`admin`) tuples are intentionally preserved.
 */
interface ResourceTuplePurgeServiceInterface
{
    /**
     * Enqueue + process DELETE_TUPLE rows for every operational tuple on $fgaObject.
     *
     * @param string $fgaObject Full FGA object string, e.g. "national_calendar:IT".
     * @return int Number of operational tuples enqueued for deletion.
     */
    public function purgeForObject(string $fgaObject): int;
}
