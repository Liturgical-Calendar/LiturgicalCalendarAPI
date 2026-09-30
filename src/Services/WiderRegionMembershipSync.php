<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;

/**
 * The `member_nation` tuples a nation's wider regions imply, as a diff (#1005).
 *
 * Pure: it knows nothing about files, the outbox or OpenFGA. The `/data` write paths turn its rows into outbox rows;
 * {@see WiderRegionMembershipReconciler} applies the same diff to OpenFGA directly.
 *
 * Every key carries an episode token. The outbox drops a row whose key already exists (ON CONFLICT DO NOTHING), so a
 * stable key would silently discard the write that re-adds a region after an earlier add and remove; this is the
 * pattern {@see ResourceTuplePurgeService} uses for the same reason.
 */
final class WiderRegionMembershipSync
{
    public const RELATION = 'member_nation';

    public static function user(string $nation): string
    {
        return 'national_calendar:' . RiteScopedObjectId::qualify(Rite::ROMAN, $nation);
    }

    public static function object(string $region): string
    {
        return 'wider_region:' . RiteScopedObjectId::qualify(Rite::ROMAN, $region);
    }

    public static function newEpisode(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * @param list<string> $before The nation's regions before the write ([] on create).
     * @param list<string> $after  The nation's regions after the write ([] on delete).
     * @return list<array{operation: OutboxOperation, fga_user: string, fga_relation: string, fga_object: string, idempotency_key: string, metadata: array<string, bool>}>
     */
    public static function rowsFor(string $nation, array $before, array $after, string $episode): array
    {
        $rows = [];
        foreach (array_values(array_diff($after, $before)) as $region) {
            $rows[] = self::row(OutboxOperation::WRITE_TUPLE, 'write', $nation, $region, $episode);
        }
        foreach (array_values(array_diff($before, $after)) as $region) {
            $rows[] = self::row(OutboxOperation::DELETE_TUPLE, 'delete', $nation, $region, $episode);
        }

        return $rows;
    }

    /**
     * @return array{operation: OutboxOperation, fga_user: string, fga_relation: string, fga_object: string, idempotency_key: string, metadata: array<string, bool>}
     */
    private static function row(OutboxOperation $operation, string $verb, string $nation, string $region, string $episode): array
    {
        return [
            'operation'       => $operation,
            'fga_user'        => self::user($nation),
            'fga_relation'    => self::RELATION,
            'fga_object'      => self::object($region),
            'idempotency_key' => "member_nation:{$episode}:{$verb}:wider_region:{$region}:national_calendar:{$nation}",
            'metadata'        => ['member_nation_sync' => true],
        ];
    }
}
