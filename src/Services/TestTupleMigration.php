<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * Pure mapper that remaps a `test_definition` OpenFGA tuple to the
 * appropriate scoped type (diocesan_calendar_test, national_calendar_test,
 * or general_roman_calendar_test) via a TestScopeResolver.
 *
 * This class carries no I/O dependencies beyond the injected resolver and
 * is safe to unit-test without a live OpenFGA store or filesystem.
 *
 * Usage in the migration CLI:
 *
 * ```php
 * $migration = new TestTupleMigration();
 * $newTuple  = $migration->mapTuple($oldTuple, $resolver);
 * // null means the test file was not found — do NOT delete the old tuple.
 * ```
 */
final class TestTupleMigration
{
    /**
     * Map a `test_definition` tuple to its scoped replacement.
     *
     * Extracts the test name from the object field (everything after the
     * first ':'), delegates scope resolution to the provided resolver, and
     * returns a new tuple using the resolved (type, id) pair.
     *
     * Returns `null` when:
     * - the object field contains no ':' separator
     * - the test name segment is empty
     * - the resolver cannot locate the corresponding test JSON file
     *
     * The caller MUST NOT delete the old tuple when this method returns null.
     *
     * @param array{user: string, relation: string, object: string} $tuple
     * @return array{user: string, relation: string, object: string}|null
     */
    public function mapTuple(array $tuple, TestScopeResolver $resolver): ?array
    {
        $object   = $tuple['object'];
        $colonPos = strpos($object, ':');

        if ($colonPos === false) {
            return null;
        }

        $testName = substr($object, $colonPos + 1);

        if ($testName === '') {
            return null;
        }

        $resolved = $resolver->resolve($testName);

        if ($resolved === null) {
            return null;
        }

        [$type, $id] = $resolved;

        return [
            'user'     => $tuple['user'],
            'relation' => $tuple['relation'],
            'object'   => "{$type}:{$id}",
        ];
    }
}
