<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;

/**
 * Maps a test file name to the OpenFGA (object type, object id) pair that
 * scopes the test within the authorization model.
 *
 * {@see resolve()} reads `{testsRoot}/{rite}/{testName}.json` and inspects the top-level
 * `applies_to` key, leniently:
 *
 *   - `{"rite": "<r>", "diocesan_calendar": "<id>"}` → `['diocesan_calendar_test', '<r>/<id>']`
 *   - `{"rite": "<r>", "national_calendar": "<id>"}` → `['national_calendar_test', '<r>/<id>']`
 *   - `{"rite": "<r>"}`                              → `['rite_calendar_test', '<r>']`
 *   - absent / empty / other                         → `['rite_calendar_test', 'roman']`
 *
 * {@see resolveFromPayload()} maps the same shapes, EXCEPT the last row: a payload with no
 * (or non-array) `applies_to` returns `null` rather than the lenient rite-level default,
 * since a payload authorizes a write and must name its own scope (#790 follow-up).
 *
 * **Every scope carries its rite.** A calendar id alone does not identify a
 * calendar: `lugano_ch` could name an Ambrosian calendar or a Roman one, and the
 * source tree is already partitioned that way
 * (`jsondata/sourcedata/rite/{rite}/calendars/...`), so nothing stops the same
 * diocese from being defined under both. Granting `diocesan_calendar_test` on a
 * bare `lugano_ch` would therefore be an ambiguous grant. National scopes are
 * qualified too, for one uniform rule — even though only the Roman rite has a
 * national tier today.
 *
 * `rite_calendar_test` generalises the older `general_roman_calendar_test`,
 * whose single fixed id `general_roman_calendar` denoted exactly the Roman
 * rite-level calendar; `rite_calendar_test:roman` is its successor and
 * `rite_calendar_test:ambrosian` the scope this generalisation unlocks
 * (issue #767). The old type is still accepted everywhere so pre-migration
 * tuples keep authorizing — see `scripts/migrate-rite-test-tuples.php`.
 *
 * Returns `null` when the test file is missing or unreadable.
 */
final class TestScopeResolver
{
    /** Absolute path to the corpus ROOT (`jsondata/tests`), not to a rite partition. */
    private string $testsRootDir;

    public function __construct(?string $testsRootDir = null)
    {
        $this->testsRootDir = $testsRootDir ?? JsonData::TESTS_FOLDER->path();
    }

    /**
     * Separates the rite from the calendar id inside a scoped-test object id.
     *
     * @deprecated Use {@see RiteScopedObjectId::SEPARATOR}. Retained so #767's public
     *             surface keeps working now that the data resource types share the format.
     */
    public const RITE_SEPARATOR = RiteScopedObjectId::SEPARATOR;

    /**
     * Compose the rite-qualified object id for a national or diocesan test scope.
     *
     * Delegates to {@see RiteScopedObjectId::qualify()}, which is the single definition
     * of the format now that the data resource types use it too (#786).
     */
    public static function qualify(Rite $rite, string $calendarId): string
    {
        return RiteScopedObjectId::qualify($rite, $calendarId);
    }

    /**
     * Split a rite-qualified object id back into its rite and calendar id.
     *
     * @return array{0: Rite, 1: string}|null
     */
    public static function parseQualifiedId(string $objectId): ?array
    {
        return RiteScopedObjectId::parse($objectId);
    }

    /**
     * True when the given name is safe to use as a bare file-stem.
     *
     * Only letters, digits, hyphens, and underscores are allowed. This rejects
     * '..', '/', '\', null bytes, spaces, and every other special character.
     */
    public static function isSafeName(string $testName): bool
    {
        return (bool) preg_match('/\A[A-Za-z0-9_-]+\z/', $testName);
    }

    /**
     * Map an `applies_to` value to the FGA (object type, object id) scope pair.
     *
     * This is the single source of truth for the applies_to → scope mapping,
     * shared by both the stored-file path (resolve()) and the create-payload
     * path (resolveFromPayload()): the scope that authorizes a create must be
     * the same scope the stored file resolves to afterwards.
     *
     * @param mixed $appliesTo The decoded `applies_to` value (assoc array or absent)
     * @return array{0: string, 1: string}
     */
    private static function mapAppliesTo(mixed $appliesTo): array
    {
        // `applies_to.rite` is required by the schema, but resolve() also runs
        // over files written before that requirement and resolveFromPayload()
        // over payloads the handler has not yet validated, so an absent or
        // unknown rite falls back to the default (Roman) rather than failing to
        // resolve a scope at all.
        $rite = null;
        if (is_array($appliesTo) && isset($appliesTo['rite']) && is_string($appliesTo['rite'])) {
            $rite = Rite::tryFrom($appliesTo['rite']);
        }
        $rite ??= Rite::default();

        if (is_array($appliesTo) && isset($appliesTo['diocesan_calendar']) && is_string($appliesTo['diocesan_calendar'])) {
            return ['diocesan_calendar_test', self::qualify($rite, $appliesTo['diocesan_calendar'])];
        }

        if (is_array($appliesTo) && isset($appliesTo['national_calendar']) && is_string($appliesTo['national_calendar'])) {
            return ['national_calendar_test', self::qualify($rite, $appliesTo['national_calendar'])];
        }

        // No national or diocesan calendar named: the test is scoped to the
        // rite-level calendar, whose id is the rite itself.
        return ['rite_calendar_test', $rite->value];
    }

    /**
     * Resolve the FGA scope pair for a test that does not yet exist on disk,
     * using the decoded request payload's `applies_to` key (create flow), or for a
     * PATCH's re-scoping target (#790).
     *
     * Unlike {@see resolve()}, this path is strict: a payload authorizes a *write*, so it
     * must name its own scope via `applies_to`, or authorization fails closed here rather
     * than defaulting to the lenient `rite_calendar_test` scope and depending on a
     * downstream semantic guard (`TestsHandler::assertPayloadRiteMatchesPath()`) to catch
     * the omission. `resolve()` keeps the lenient default: it reads *stored* files,
     * including legacy ones written before `applies_to.rite` was required, and making that
     * path strict would break authorization for existing tests.
     *
     * @param mixed $decoded The json_decode'd (assoc mode) request body
     * @return array{0: string, 1: string}|null
     */
    public function resolveFromPayload(mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        if (!isset($decoded['applies_to']) || !is_array($decoded['applies_to'])) {
            return null;
        }

        return self::mapAppliesTo($decoded['applies_to']);
    }

    /**
     * Resolve the FGA scope pair for a stored test.
     *
     * The corpus is partitioned by rite (#787), so a name alone does not identify a test:
     * `StIgnatiusOfLoyolaTest` exists — or may come to exist — under both rites, as two
     * different tests with two different scopes.
     *
     * @return array{0: string, 1: string}|null null when the name is unsafe, or no such test exists under that rite
     */
    public function resolve(Rite $rite, string $testName): ?array
    {
        // Reject any name that could enable path traversal or filesystem injection.
        // Only allow characters that are safe for use as a bare file-stem: letters,
        // digits, hyphens, and underscores. This rejects '..', '/', '\', null bytes,
        // spaces, and every other special character before touching the filesystem.
        if (!self::isSafeName($testName)) {
            return null;
        }

        $filePath = $this->testsRootDir . DIRECTORY_SEPARATOR . $rite->value . DIRECTORY_SEPARATOR . $testName . '.json';

        $raw = @file_get_contents($filePath);
        if (false === $raw) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        return self::mapAppliesTo($data['applies_to'] ?? null);
    }
}
