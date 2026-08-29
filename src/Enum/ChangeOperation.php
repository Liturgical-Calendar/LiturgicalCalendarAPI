<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * What a proposed change does to a single file under source data.
 *
 * `DELETE` replaces the `unlink()` calls the write handlers previously made
 * directly; the file is removed from the repository when the pull request
 * merges, never from the deployed tree.
 */
enum ChangeOperation: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';
}
