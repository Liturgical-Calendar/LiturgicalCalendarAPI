<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;
use LiturgicalCalendar\Api\Services\Exception\TupleNotFoundException;

/**
 * Brings a nation's `member_nation` tuples in OpenFGA to what its source file declares (#1005).
 *
 * Unlike the `/data` write paths, which know the before and after lists and go through the outbox, this reads the
 * "before" from OpenFGA itself: it is used after a change request merges (MergePollRunner) and by the seeder, where
 * the stored tuples, not a file, are what may be stale. It also replaces unqualified tuples left by the seeder's
 * pre-#1005 versions (`national_calendar:IT`, `wider_region:Europe`) with rite-qualified ones.
 */
final class WiderRegionMembershipReconciler implements WiderRegionMembershipSyncer
{
    public function __construct(private readonly OpenFgaClient $client)
    {
    }

    /**
     * `legacy` holds every tuple read under the qualified user that is NOT Roman rite-qualified on the object side
     * too — a bare `wider_region:Europe` (pre-#1005) or a half-qualified `wider_region:ambrosian/X` (wider regions
     * are Roman-only, so that shape is unexplained either way) — plus every tuple read under the unqualified
     * (legacy) user. Neither is ever pruned by anything else, so `syncNation()` must delete them alongside the
     * genuinely legacy ones or they linger forever.
     *
     * @return array{qualified: list<string>, legacy: list<array{user: string, relation: string, object: string}>}
     */
    public function currentRegions(string $nation): array
    {
        $qualified = [];
        $legacy    = [];
        foreach ($this->read(WiderRegionMembershipSync::user($nation)) as $tuple) {
            $parsed = RiteScopedObjectId::parse(substr($tuple['object'], strlen('wider_region:')));
            if (null !== $parsed && Rite::ROMAN === $parsed[0]) {
                $qualified[] = $parsed[1];
            } else {
                $legacy[] = $tuple;
            }
        }

        return ['qualified' => $qualified, 'legacy' => array_merge($legacy, $this->read("national_calendar:{$nation}"))];
    }

    /**
     * @param list<string> $regions What the nation's file declares.
     * @return array{writes: list<string>, deletes: list<string>}
     */
    public function syncNation(string $nation, array $regions, bool $apply = true): array
    {
        $current = $this->currentRegions($nation);
        $writes  = [];
        $deletes = [];

        foreach (array_values(array_diff($regions, $current['qualified'])) as $region) {
            $writes[] = ['user' => WiderRegionMembershipSync::user($nation), 'relation' => WiderRegionMembershipSync::RELATION, 'object' => WiderRegionMembershipSync::object($region)];
        }
        foreach (array_values(array_diff($current['qualified'], $regions)) as $region) {
            $deletes[] = ['user' => WiderRegionMembershipSync::user($nation), 'relation' => WiderRegionMembershipSync::RELATION, 'object' => WiderRegionMembershipSync::object($region)];
        }
        foreach ($current['legacy'] as $tuple) {
            $deletes[] = $tuple;
        }

        if ($apply) {
            foreach ($writes as $tuple) {
                try {
                    $this->client->writeTuple($tuple['user'], $tuple['relation'], $tuple['object']);
                } catch (TupleAlreadyExistsException) {
                    // benign: already there
                }
            }
            foreach ($deletes as $tuple) {
                try {
                    $this->client->deleteTuple($tuple['user'], $tuple['relation'], $tuple['object']);
                } catch (TupleNotFoundException) {
                    // benign: already gone
                }
            }
        }

        return ['writes' => array_map(self::format(...), $writes), 'deletes' => array_map(self::format(...), $deletes)];
    }

    /**
     * @param array{user: string, relation: string, object: string} $tuple
     */
    private static function format(array $tuple): string
    {
        return "{$tuple['object']}#{$tuple['relation']}@{$tuple['user']}";
    }

    /**
     * Nations that currently hold any `member_nation` tuple, qualified or not, so the seeder can prune nations whose
     * file is gone.
     *
     * @return list<string>
     */
    public function nationsWithTuples(): array
    {
        $nations = [];
        $token   = null;
        do {
            $page = $this->client->readTuples('', '', null, null, $token);
            foreach ($page['tuples'] as $tuple) {
                if ($tuple['relation'] !== WiderRegionMembershipSync::RELATION || !str_starts_with($tuple['user'], 'national_calendar:')) {
                    continue;
                }
                $id        = substr($tuple['user'], strlen('national_calendar:'));
                $parsed    = RiteScopedObjectId::parse($id);
                $nations[] = $parsed[1] ?? $id;
            }
            $token = $page['next_continuation_token'] !== '' ? $page['next_continuation_token'] : null;
        } while ($token !== null);

        $nations = array_values(array_unique($nations));
        sort($nations);

        return $nations;
    }

    /**
     * @return list<array{user: string, relation: string, object: string}>
     */
    private function read(string $user): array
    {
        $tuples = [];
        $token  = null;
        do {
            $page   = $this->client->readTuples($user, 'wider_region:', WiderRegionMembershipSync::RELATION, null, $token);
            $tuples = array_merge($tuples, $page['tuples']);
            $token  = $page['next_continuation_token'] !== '' ? $page['next_continuation_token'] : null;
        } while ($token !== null);

        return $tuples;
    }
}
