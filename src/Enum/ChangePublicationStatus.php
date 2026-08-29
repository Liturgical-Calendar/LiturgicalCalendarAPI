<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * Where a change request sits on GitHub.
 *
 * Phase 1 only ever writes `NONE`; the publisher (Phase 2) and merge polling
 * (Phase 3) drive the rest. The values exist now so the column and its CHECK
 * constraint do not need a migration later.
 */
enum ChangePublicationStatus: string
{
    case NONE   = 'none';
    case QUEUED = 'queued';
    case OPEN   = 'open';
    case MERGED = 'merged';
    case CLOSED = 'closed';
}
