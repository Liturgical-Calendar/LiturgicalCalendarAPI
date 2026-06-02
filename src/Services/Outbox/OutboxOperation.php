<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

/**
 * The two OpenFGA tuple operations the outbox tracks.
 *
 * Values match the `outbox_op` Postgres enum from
 * Version20260602202504 migration.
 */
enum OutboxOperation: string
{
    case WRITE_TUPLE  = 'write_tuple';
    case DELETE_TUPLE = 'delete_tuple';
}
