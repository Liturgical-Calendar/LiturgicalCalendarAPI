<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

/**
 * Outbox row lifecycle states.
 *
 * Values match the `outbox_status` Postgres enum. State transitions:
 *
 *   pending → succeeded
 *   pending → retrying → retrying → ... → succeeded
 *   retrying → failed_terminal (attempts == 10 on transient, OR any 4xx classified TERMINAL)
 *   failed_terminal → pending (admin retry via POST /admin/outbox/{id}/retry)
 *
 * `succeeded` and `failed_terminal` are terminal unless the admin retry
 * endpoint resets the row back to `pending`.
 */
enum OutboxStatus: string
{
    case PENDING         = 'pending';
    case RETRYING        = 'retrying';
    case SUCCEEDED       = 'succeeded';
    case FAILED_TERMINAL = 'failed_terminal';
}
