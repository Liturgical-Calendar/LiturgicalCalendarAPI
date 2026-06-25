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
     * @return array{0: string, 1: string}|null
     */
    public function resolve(string $testName): ?array
    {
        // Reject any name that could enable path traversal or filesystem injection.
        // Only allow characters that are safe for use as a bare file-stem: letters,
        // digits, hyphens, and underscores. This rejects '..', '/', '\', null bytes,
        // spaces, and every other special character before touching the filesystem.
        if (!preg_match('/\A[A-Za-z0-9_-]+\z/', $testName)) {
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
