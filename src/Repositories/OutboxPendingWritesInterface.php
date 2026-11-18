<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

/**
 * Read-only questions about the tuple writes still queued in the openfga_outbox.
 *
 * Kept apart from {@see OutboxRepository} (which is final) so a caller such as the viewer-retirement sweep can be
 * tested without a database.
 */
interface OutboxPendingWritesInterface
{
    /**
     * Is a WRITE_TUPLE for the user, on one of the relations and object types, still pending or retrying?
     *
     * @param list<string> $relations
     * @param list<string> $objectTypes
     */
    public function hasPendingWrite(string $fgaUser, array $relations, array $objectTypes): bool;

    /**
     * Every `viewer` WRITE_TUPLE for a person (`user:`, not the `user:*` wildcard) still pending or retrying.
     *
     * @return list<array{id: int, user: string, object: string, status: string}>
     */
    public function listPendingPeopleViewerWrites(): array;
}
