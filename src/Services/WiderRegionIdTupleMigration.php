<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionId;
use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;
use LiturgicalCalendar\Api\Services\Exception\TupleNotFoundException;

/**
 * Moves OpenFGA tuples from legacy wider region objects to their ids (#1018): `wider_region:roman/Europe` →
 * `wider_region:roman/europe`, on the object side (grants, `member_nation`) and the user side, should a model ever
 * put a region there. Copy first, then, only when asked, delete: a tuple is never removed before its replacement
 * exists. Writing an existing tuple and deleting a missing one are both benign, so a re-run is safe.
 */
final class WiderRegionIdTupleMigration
{
    private const TYPE_PREFIX = 'wider_region:';

    public function __construct(private readonly OpenFgaClient $client)
    {
    }

    /**
     * The reference with a legacy wider region name replaced by its id; any other reference comes back unchanged.
     *
     * A tuple written before the #786 rite qualification may carry a bare `wider_region:Europe`. Wider regions exist
     * only in the Roman rite, so such a reference is qualified as it is mapped.
     */
    public static function mapReference(string $reference): string
    {
        if (!str_starts_with($reference, self::TYPE_PREFIX)) {
            return $reference;
        }
        $objectId = substr($reference, strlen(self::TYPE_PREFIX));
        $parsed   = RiteScopedObjectId::parse($objectId);
        if ($parsed === null) {
            $rite = Rite::ROMAN;
            $id   = $objectId;
        } else {
            [$rite, $id] = $parsed;
        }
        $normalized = WiderRegionId::normalize($id);
        if ($normalized === null || $normalized[1] === false) {
            return $reference;
        }

        return self::TYPE_PREFIX . RiteScopedObjectId::qualify($rite, $normalized[0]);
    }

    /**
     * @return list<array{from: array{user:string,relation:string,object:string}, to: array{user:string,relation:string,object:string}}>
     */
    public function plan(): array
    {
        $plan  = [];
        $token = null;
        do {
            $page = $this->client->readTuples('', '', null, null, $token);
            foreach ($page['tuples'] as $t) {
                $to = [
                    'user'     => self::mapReference($t['user']),
                    'relation' => $t['relation'],
                    'object'   => self::mapReference($t['object']),
                ];
                if ($to !== $t) {
                    $plan[] = ['from' => $t, 'to' => $to];
                }
            }
            $token = $page['next_continuation_token'] !== '' ? $page['next_continuation_token'] : null;
        } while ($token !== null);

        return $plan;
    }

    /**
     * @return array{copied: int, pruned: int}
     */
    public function apply(bool $prune): array
    {
        $copied = 0;
        $pruned = 0;
        foreach ($this->plan() as $step) {
            try {
                $this->client->writeTuple($step['to']['user'], $step['to']['relation'], $step['to']['object']);
            } catch (TupleAlreadyExistsException) {
                // Already copied by an earlier run.
            }
            ++$copied;
            if ($prune) {
                try {
                    $this->client->deleteTuple($step['from']['user'], $step['from']['relation'], $step['from']['object']);
                } catch (TupleNotFoundException) {
                    // Already pruned by an earlier run.
                }
                ++$pruned;
            }
        }

        return ['copied' => $copied, 'pruned' => $pruned];
    }
}
