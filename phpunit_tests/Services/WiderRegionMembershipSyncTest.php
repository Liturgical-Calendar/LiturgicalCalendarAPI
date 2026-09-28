<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\WiderRegionMembershipSync;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WiderRegionMembershipSync::class)]
final class WiderRegionMembershipSyncTest extends TestCase
{
    /** @param list<array<string,mixed>> $rows @return list<string> */
    private static function summary(array $rows): array
    {
        return array_map(static fn (array $r): string => $r['operation']->value . ' ' . $r['fga_object'], $rows);
    }

    public function testCreatingWritesEveryRegion(): void
    {
        $rows = WiderRegionMembershipSync::rowsFor('SE', [], ['Europe', 'Nordic'], 'ep');

        self::assertSame(['write_tuple wider_region:roman/Europe', 'write_tuple wider_region:roman/Nordic'], self::summary($rows));
        self::assertSame('national_calendar:roman/SE', $rows[0]['fga_user']);
        self::assertSame('member_nation', $rows[0]['fga_relation']);
    }

    public function testAddingAndRemovingDiffs(): void
    {
        $rows = WiderRegionMembershipSync::rowsFor('SE', ['Europe', 'Scandinavia'], ['Europe', 'Nordic'], 'ep');

        self::assertSame(['write_tuple wider_region:roman/Nordic', 'delete_tuple wider_region:roman/Scandinavia'], self::summary($rows));
    }

    public function testReorderingChangesNothing(): void
    {
        self::assertSame([], WiderRegionMembershipSync::rowsFor('SE', ['Europe', 'Nordic'], ['Nordic', 'Europe'], 'ep'));
    }

    public function testDeletingRemovesEveryRegion(): void
    {
        $rows = WiderRegionMembershipSync::rowsFor('SE', ['Europe', 'Nordic'], [], 'ep');

        self::assertSame(['delete_tuple wider_region:roman/Europe', 'delete_tuple wider_region:roman/Nordic'], self::summary($rows));
    }

    public function testKeysCarryTheEpisodeSoAReAddIsNotSwallowed(): void
    {
        $first  = WiderRegionMembershipSync::rowsFor('SE', [], ['Nordic'], WiderRegionMembershipSync::newEpisode());
        $second = WiderRegionMembershipSync::rowsFor('SE', [], ['Nordic'], WiderRegionMembershipSync::newEpisode());

        self::assertNotSame($first[0]['idempotency_key'], $second[0]['idempotency_key']);
        self::assertMatchesRegularExpression('/^member_nation:[0-9a-f]{16}:write:wider_region:Nordic:national_calendar:SE$/', $first[0]['idempotency_key']);
    }
}
