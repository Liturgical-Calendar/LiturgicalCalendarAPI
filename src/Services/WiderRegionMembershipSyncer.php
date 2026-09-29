<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/** Brings a nation's `member_nation` tuples to a given list of wider regions (#1005). */
interface WiderRegionMembershipSyncer
{
    /**
     * @param list<string> $regions
     * @return array{writes: list<string>, deletes: list<string>}
     */
    public function syncNation(string $nation, array $regions, bool $apply = true): array;
}
