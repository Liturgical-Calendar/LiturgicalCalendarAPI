<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceExistenceCheckerInterface;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeServiceInterface;
use LiturgicalCalendar\Api\Services\SourceTreeGuard;

/**
 * Defense-in-depth sweep: finds OPERATIONAL tuples whose backing resource no
 * longer exists and purges them via ResourceTuplePurgeService. `admin` tuples
 * on deleted resources are intentional governance and are never purged here.
 *
 * A full scan, intentionally off the hot ConsumerLoop/Backstop path. It refuses to run against a
 * missing or partial source tree ({@see SourceTreeGuard}): that tree would read as "every resource
 * was deleted" and revoke every editor and viewer grant (#1015). `sweep(apply: false)` lists what a
 * real run would purge and purges nothing.
 */
final class ResourceTuplePurgeReconciler
{
    public function __construct(
        private readonly OpenFgaClient $client,
        private readonly ResourceExistenceCheckerInterface $checker,
        private readonly ResourceTuplePurgeServiceInterface $purge,
        private readonly SourceTreeGuard $guard = new SourceTreeGuard(),
    ) {
    }

    /**
     * Enumerate all OpenFGA tuples, collect objects that carry ≥1 operational
     * tuple (editor/viewer), and for each whose backing resource is gone call
     * purgeForObject once. `admin` tuples on deleted resources are intentionally
     * ignored — they represent governance access for recreating the resource.
     *
     * `objects` maps each object purged — or, when `$apply` is false, each object a real run would
     * purge — to the number of operational tuples it carries.
     *
     * @return array{scanned: int, purgedObjects: int, enqueued: int, objects: array<string, int>}
     * @throws \RuntimeException When the source tree is missing or partial; no tuple is read.
     */
    public function sweep(bool $apply = true): array
    {
        $refusal = $this->guard->refusalReason();
        if ($refusal !== null) {
            throw new \RuntimeException($refusal);
        }

        // Stream pages instead of buffering every tuple in memory: keep only the
        // running scanned count and the distinct objects that carry at least one
        // operational tuple. admin/other relations are ignored — they never
        // trigger a purge.
        $scanned = 0;
        $token   = null;
        /** @var array<string, int> $objectsWithOperational object => operational tuple count */
        $objectsWithOperational = [];
        do {
            $page = $this->client->readTuples('', '', null, null, $token);
            foreach ($page['tuples'] as $t) {
                ++$scanned;
                if (in_array($t['relation'], AccessRequestRepository::OPERATIONAL_RELATIONS, true)) {
                    $objectsWithOperational[$t['object']] = ( $objectsWithOperational[$t['object']] ?? 0 ) + 1;
                }
            }
            $token = $page['next_continuation_token'] !== '' ? $page['next_continuation_token'] : null;
        } while ($token !== null);

        $purgedObjects = 0;
        $enqueued      = 0;
        $objects       = [];
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
            $objects[$object] = $objectsWithOperational[$object];
            ++$purgedObjects;
            if ($apply) {
                $enqueued += $this->purge->purgeForObject($object);
            }
        }

        return ['scanned' => $scanned, 'purgedObjects' => $purgedObjects, 'enqueued' => $enqueued, 'objects' => $objects];
    }
}
