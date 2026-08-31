<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode;

/**
 * A precondition for suites whose safety depends on queue mode actually engaging (#945).
 *
 * The queue-mode write tests assert that a real tracked calendar — `HR.json`, `Europe.json` — is
 * still on disk after the handler has been asked to delete or amend it. That is the correct
 * assertion for queue mode, where nothing is written to disk at all. But it is also the *only*
 * thing standing between those requests and the tracked source data, and it is checked AFTER the
 * request: if the write ever went to disk instead, the suite would report the deletion rather
 * than prevent it. Restoring from git is easy; noticing is not, which is the shape #921 and #935
 * were.
 *
 * What this actually guards. Each of those suites forces `SOURCEDATA_CHANGE_REQUESTS` and the
 * `OPENFGA_*` variables in its own `setUp()`, and declares `$requiresDatabase`, which skips the
 * class outright when `DB_*` is unset. So under today's `SourceDataWriteMode` the mode cannot help
 * but engage, and this assertion passes by construction. It is not therefore idle: it pins the
 * invariant rather than the environment. The realistic way queue mode "silently fails to engage"
 * is a change to what it *requires* — a new mandatory variable, a reachability probe added to
 * `stackAvailable()`, a reordering of the fail-safe fallback — and any of those turns a suite of
 * green assertions into a suite that deletes tracked files. Stubbing `stackAvailable()` to return
 * false makes this assertion fail in `setUp()`, with `HR.json` untouched; without it, the same
 * stub reaches the DELETE.
 *
 * Cheap, and it fails closed. That is the whole argument for it.
 */
trait RequiresQueueMode
{
    /**
     * Refuse to continue unless source-data writes are being queued rather than written to disk.
     *
     * Call at the END of `setUp()` — after the environment the mode is read from has been put in
     * place, and before any test body can issue a write.
     */
    private static function assertQueueModeIsActive(): void
    {
        if (SourceDataWriteMode::changeRequestsEnabled()) {
            return;
        }

        self::fail(
            'Queue mode is not active, so this suite would write to disk and mutate tracked source '
            . 'data under jsondata/sourcedata (#945). '
            . sprintf('%s is "%s"; ', SourceDataWriteMode::FLAG, is_string($_ENV[SourceDataWriteMode::FLAG] ?? null) ? $_ENV[SourceDataWriteMode::FLAG] : '<unset>')
            . 'queue mode additionally requires DB_HOST/DB_NAME/DB_USER/DB_PASSWORD and '
            . 'OPENFGA_API_URL/OPENFGA_STORE_ID/OPENFGA_MODEL_ID to be set, since it falls back to '
            . 'disk mode rather than accepting edits nobody could approve.'
        );
    }
}
