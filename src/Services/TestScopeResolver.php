<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;

/**
 * Maps a test file name to the OpenFGA (object type, object id) pair that
 * scopes the test within the authorization model.
 *
 * Reads `{testsDir}/{testName}.json` and inspects the top-level `applies_to`
 * key:
 *
 *   - `{"diocesan_calendar": "<id>"}` → `['diocesan_calendar_test', '<id>']`
 *   - `{"national_calendar": "<id>"}` → `['national_calendar_test', '<id>']`
 *   - `{"rite": "<rite>"}`            → `['rite_calendar_test', '<rite>']`
 *   - absent / empty / other          → `['rite_calendar_test', 'roman']`
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
    private string $testsDir;

    public function __construct(?string $testsDir = null)
    {
        $this->testsDir = $testsDir ?? JsonData::TESTS_FOLDER->path();
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
        if (is_array($appliesTo) && isset($appliesTo['diocesan_calendar']) && is_string($appliesTo['diocesan_calendar'])) {
            return ['diocesan_calendar_test', $appliesTo['diocesan_calendar']];
        }

        if (is_array($appliesTo) && isset($appliesTo['national_calendar']) && is_string($appliesTo['national_calendar'])) {
            return ['national_calendar_test', $appliesTo['national_calendar']];
        }

        // No national or diocesan calendar named: the test is scoped to a
        // rite-level calendar. `applies_to.rite` is required by the schema, but
        // resolve() also runs over files written before that requirement and
        // resolveFromPayload() over payloads the handler has not yet validated,
        // so an absent or unknown rite falls back to the default (Roman) rather
        // than failing to resolve a scope at all.
        $rite = null;
        if (is_array($appliesTo) && isset($appliesTo['rite']) && is_string($appliesTo['rite'])) {
            $rite = Rite::tryFrom($appliesTo['rite']);
        }

        return ['rite_calendar_test', ( $rite ?? Rite::default() )->value];
    }

    /**
     * Resolve the FGA scope pair for a test that does not yet exist on disk,
     * using the decoded request payload's `applies_to` key (create flow).
     *
     * @param mixed $decoded The json_decode'd (assoc mode) request body
     * @return array{0: string, 1: string}|null
     */
    public function resolveFromPayload(mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        return self::mapAppliesTo($decoded['applies_to'] ?? null);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public function resolve(string $testName): ?array
    {
        // Reject any name that could enable path traversal or filesystem injection.
        // Only allow characters that are safe for use as a bare file-stem: letters,
        // digits, hyphens, and underscores. This rejects '..', '/', '\', null bytes,
        // spaces, and every other special character before touching the filesystem.
        if (!self::isSafeName($testName)) {
            return null;
        }

        $filePath = $this->testsDir . DIRECTORY_SEPARATOR . $testName . '.json';

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
