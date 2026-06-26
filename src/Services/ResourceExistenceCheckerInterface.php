<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * Contract for checking whether the backing resource for an OpenFGA object
 * still exists on disk (or is always considered present, e.g. GRC fixed objects).
 *
 * Extracted from ResourceExistenceChecker to allow test-doubles in unit tests
 * without requiring PHPUnit to mock a final class.
 */
interface ResourceExistenceCheckerInterface
{
    /**
     * Returns true when $objectType is a known resource type that the checker
     * can evaluate (e.g. national_calendar, diocesan_calendar). Returns false
     * for relation-side types like "user".
     */
    public function isResourceType(string $objectType): bool;

    /**
     * Returns true when the backing resource (file or directory) for the given
     * object type + id actually exists. GRC and scoped test objects always return
     * true; unknown types always return false.
     */
    public function exists(string $objectType, string $objectId): bool;
}
