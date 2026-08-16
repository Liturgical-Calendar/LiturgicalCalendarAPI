<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\Rite;

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
 * $newTuple  = $migration->mapTuple($oldTuple, $resolver, $reason);
 * // null means the test name did not resolve to exactly one rite partition
 * // (not found, or — post #787 — ambiguous across two) — do NOT delete the
 * // old tuple. $reason distinguishes the two for CLI reporting.
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
     * - the resolver cannot locate the corresponding test JSON file in exactly
     *   one rite partition (zero matches: not found; two matches: ambiguous —
     *   see below)
     *
     * The caller MUST NOT delete the old tuple when this method returns null.
     *
     * @param array{user: string, relation: string, object: string} $tuple
     * @param ?string $unresolvedReason Out parameter (by reference), set to
     *   'not_found' or 'ambiguous' when this method returns null for a
     *   name-resolution reason (left null on success, and on the two
     *   malformed-object-field cases above).
     * @return array{user: string, relation: string, object: string}|null
     */
    public function mapTuple(array $tuple, TestScopeResolver $resolver, ?string &$unresolvedReason = null): ?array
    {
        $unresolvedReason = null;

        $object   = $tuple['object'];
        $colonPos = strpos($object, ':');

        if ($colonPos === false) {
            return null;
        }

        $testName = substr($object, $colonPos + 1);

        if ($testName === '') {
            return null;
        }

        // These legacy `test_definition:` tuples predate rite partitioning (#787)
        // and carry no rite of their own, so every partition is searched. A name
        // defined in exactly one partition is unambiguous; a name defined under
        // two rites now names two DIFFERENT tests with two different scopes, and
        // guessing which one the tuple meant would silently grant the wrong one —
        // a privilege shift in both directions — so that case is refused, exactly
        // as `migrate-rite-data-tuples.php` refuses a diocese id defined under two
        // rites rather than guessing which grant was meant.
        $matches = [];
        foreach (Rite::cases() as $rite) {
            $resolved = $resolver->resolve($rite, $testName);
            if ($resolved !== null) {
                $matches[] = $resolved;
            }
        }

        if (count($matches) !== 1) {
            $unresolvedReason = $matches === [] ? 'not_found' : 'ambiguous';
            return null;
        }

        [$type, $id] = $matches[0];

        return [
            'user'     => $tuple['user'],
            'relation' => $tuple['relation'],
            'object'   => "{$type}:{$id}",
        ];
    }
}
