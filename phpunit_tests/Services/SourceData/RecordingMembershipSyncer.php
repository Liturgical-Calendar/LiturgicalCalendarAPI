<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Services\WiderRegionMembershipSyncer;

/** Records each nation's synced region list, so a test can assert on exactly what the runner asked for. */
final class RecordingMembershipSyncer implements WiderRegionMembershipSyncer
{
    /** @var array<string, list<string>> nation => regions */
    public array $synced = [];

    public function syncNation(string $nation, array $regions, bool $apply = true): array
    {
        $this->synced[$nation] = $regions;

        return ['writes' => [], 'deletes' => []];
    }
}
