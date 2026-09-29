<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\JsonData;

/**
 * Refuses to let a destructive job act on a missing or half-written source tree.
 *
 * A sweep that prunes access for resources "no longer on disk" reads a missing tree — a deploy in
 * progress, a bad mount, the wrong working directory — as "every resource was deleted", and would
 * revoke every grant in the store. This guard is the one check every such job shares: at least one
 * national calendar file must exist. A folder without its `{N}.json` does not count, since that is
 * what a partial tree looks like.
 */
final class SourceTreeGuard
{
    /**
     * @param string|null $nationsDir The national calendars folder; null resolves the deployment's own
     *                                at check time, so a guard built before `Router::$apiFilePath` is set
     *                                still reads the right tree.
     */
    public function __construct(private readonly ?string $nationsDir = null)
    {
    }

    public function nationsDir(): string
    {
        return $this->nationsDir ?? JsonData::NATIONAL_CALENDARS_FOLDER->path();
    }

    /** Null when the tree is populated; otherwise why a destructive job must not run. */
    public function refusalReason(): ?string
    {
        $dir  = $this->nationsDir();
        $dirs = glob($dir . '/*', GLOB_ONLYDIR);
        foreach ($dirs === false ? [] : $dirs as $nationDir) {
            $nation = basename($nationDir);
            if (is_file("{$nationDir}/{$nation}.json")) {
                return null;
            }
        }

        return "No national calendar files found in {$dir}; refusing to run against a missing or partial source tree.";
    }
}
