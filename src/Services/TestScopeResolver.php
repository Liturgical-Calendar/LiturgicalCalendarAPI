<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\JsonData;

/**
 * Maps a test file name to the OpenFGA (object type, object id) pair that
 * scopes the test within the authorization model.
 *
 * Reads `{testsDir}/{testName}.json` and inspects the top-level `applies_to`
 * key:
 *
 *   - `{"diocesan_calendar": "<id>"}` → `['diocesan_calendar_test', '<id>']`
 *   - `{"national_calendar": "<id>"}` → `['national_calendar_test', '<id>']`
 *   - absent / empty / other          → `['general_roman_calendar_test', 'general_roman_calendar']`
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
     * Resolve the FGA scope pair for a test that does not yet exist on disk,
     * using the decoded request payload's `applies_to` key (create flow).
     *
     * Mirrors the mapping applied by resolve() to the stored file, so the scope
     * that authorizes the create is the same scope the created file will have.
     *
     * @param mixed $decoded The json_decode'd (assoc mode) request body
     * @return array{0: string, 1: string}|null
     */
    public function resolveFromPayload(mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        $appliesTo = $decoded['applies_to'] ?? null;

        if (is_array($appliesTo) && isset($appliesTo['diocesan_calendar']) && is_string($appliesTo['diocesan_calendar'])) {
            return ['diocesan_calendar_test', $appliesTo['diocesan_calendar']];
        }

        if (is_array($appliesTo) && isset($appliesTo['national_calendar']) && is_string($appliesTo['national_calendar'])) {
            return ['national_calendar_test', $appliesTo['national_calendar']];
        }

        return ['general_roman_calendar_test', 'general_roman_calendar'];
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

        $appliesTo = $data['applies_to'] ?? null;

        if (is_array($appliesTo) && isset($appliesTo['diocesan_calendar']) && is_string($appliesTo['diocesan_calendar'])) {
            return ['diocesan_calendar_test', $appliesTo['diocesan_calendar']];
        }

        if (is_array($appliesTo) && isset($appliesTo['national_calendar']) && is_string($appliesTo['national_calendar'])) {
            return ['national_calendar_test', $appliesTo['national_calendar']];
        }

        return ['general_roman_calendar_test', 'general_roman_calendar'];
    }
}
