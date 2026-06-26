<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Repositories\OutboxBatchInsertInterface;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessorInterface;
use PDO;

/**
 * Purges OPERATIONAL OpenFGA tuples (editor/viewer) for a deleted resource by
 * enqueuing one DELETE_TUPLE outbox row per tuple, then processing them.
 *
 * Governance (`admin`) tuples are NEVER enumerated, so admin survives data
 * deletion (it authorizes recreating the resource — see #669). Shared by the
 * resource delete handlers and the reconciler sweep.
 */
final class ResourceTuplePurgeService implements ResourceTuplePurgeServiceInterface
{
    public function __construct(
        private readonly OpenFgaClient $client,
        private readonly OutboxBatchInsertInterface $repo,
        private readonly OutboxProcessorInterface $processor,
        private readonly PDO $db,
    ) {
    }

    /**
     * Enqueue + process DELETE_TUPLE rows for every operational tuple on $fgaObject.
     *
     * @param string $fgaObject Full FGA object, e.g. "national_calendar:IT".
     * @return int Number of operational tuples enqueued.
     */
    public function purgeForObject(string $fgaObject): int
    {
        /** @var list<array{user: string, relation: string, object: string}> $tuples */
        $tuples = [];
        $token  = null;
        do {
            $page   = $this->client->readTuples('', $fgaObject, null, null, $token);
            $tuples = array_merge($tuples, $page['tuples']);
            $token  = $page['next_continuation_token'] !== '' ? $page['next_continuation_token'] : null;
        } while ($token !== null);

        $rows = [];
        foreach ($tuples as $t) {
            if (!in_array($t['relation'], AccessRequestRepository::OPERATIONAL_RELATIONS, true)) {
                continue; // skip admin (governance) and anything non-operational
            }
            $rows[] = [
                'operation'       => OutboxOperation::DELETE_TUPLE,
                'fga_user'        => $t['user'],
                'fga_relation'    => $t['relation'],
                'fga_object'      => $t['object'],
                'idempotency_key' => "resource_purge:{$t['object']}:{$t['user']}:{$t['relation']}",
                'metadata'        => ['resource_purge' => true],
            ];
        }

        if ($rows === []) {
            return 0;
        }

        $this->db->beginTransaction();
        try {
            $ids = $this->repo->insertBatch($rows);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        foreach ($ids as $id) {
            $this->processor->processSync($id);
        }

        return count($ids);
    }
}
