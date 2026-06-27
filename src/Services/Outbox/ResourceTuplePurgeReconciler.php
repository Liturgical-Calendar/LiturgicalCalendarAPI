<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceExistenceCheckerInterface;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeServiceInterface;

/**
 * Defense-in-depth sweep: finds OPERATIONAL tuples whose backing resource no
 * longer exists and purges them via ResourceTuplePurgeService. `admin` tuples
 * on deleted resources are intentional governance and are never purged here.
 *
 * Cron-able; intentionally off the hot ConsumerLoop/Backstop path (full scan).
 */
final class ResourceTuplePurgeReconciler
{
    public function __construct(
        private readonly OpenFgaClient $client,
        private readonly ResourceExistenceCheckerInterface $checker,
        private readonly ResourceTuplePurgeServiceInterface $purge,
    ) {
    }

    /**
     * Enumerate all OpenFGA tuples, collect objects that carry ≥1 operational
     * tuple (editor/viewer), and for each whose backing resource is gone call
     * purgeForObject once. `admin` tuples on deleted resources are intentionally
     * ignored — they represent governance access for recreating the resource.
     *
     * @return array{scanned: int, purgedObjects: int, enqueued: int}
     */
    public function sweep(): array
    {
        // Stream pages instead of buffering every tuple in memory: keep only the
        // running scanned count and the distinct objects that carry at least one
        // operational tuple. admin/other relations are ignored — they never
        // trigger a purge.
        $scanned = 0;
        $token   = null;
        /** @var array<string, true> $objectsWithOperational */
        $objectsWithOperational = [];
        do {
            $page = $this->client->readTuples('', '', null, null, $token);
            foreach ($page['tuples'] as $t) {
                ++$scanned;
                if (in_array($t['relation'], AccessRequestRepository::OPERATIONAL_RELATIONS, true)) {
                    $objectsWithOperational[$t['object']] = true;
                }
            }
            $token = $page['next_continuation_token'] !== '' ? $page['next_continuation_token'] : null;
        } while ($token !== null);

        $purgedObjects = 0;
        $enqueued      = 0;
        foreach (array_keys($objectsWithOperational) as $object) {
            $colon = strpos($object, ':');
            if ($colon === false) {
                continue;
            }
            $type = substr($object, 0, $colon);
            $id   = substr($object, $colon + 1);
            if (!$this->checker->isResourceType($type)) {
                continue;
            }
            if ($this->checker->exists($type, $id)) {
                continue; // resource still present — operational tuples are live
            }
            $enqueued += $this->purge->purgeForObject($object);
            ++$purgedObjects;
        }

        return ['scanned' => $scanned, 'purgedObjects' => $purgedObjects, 'enqueued' => $enqueued];
    }
}
