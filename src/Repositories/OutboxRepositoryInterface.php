<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Services\Outbox\OutboxRow;

/**
 * Minimal read interface for the openfga_outbox repository.
 *
 * Extracted so that consumers that only need getById (e.g. CascadeReconciler)
 * can be tested with plain PHPUnit mocks without depending on the final
 * OutboxRepository class.
 */
interface OutboxRepositoryInterface
{
    public function getById(int $id): ?OutboxRow;

    public function countSiblingNonTerminalDeletes(string $accessRequestId): int;
}
