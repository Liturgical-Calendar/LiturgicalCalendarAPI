<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Models\Auth\TestTarget;
use LiturgicalCalendar\Api\Models\Auth\WsCaller;

/**
 * Whether a caller may start a validation run.
 *
 * One object, asked by both the `hello` frame and the action gate in {@see \LiturgicalCalendar\Api\Health},
 * so the advertisement and the enforcement cannot disagree. That is the whole point: the gap #894
 * closes is not that UnitTestInterface asked the wrong question, but that it answered its own — a
 * role test in the client can drift from the one the server would apply, and a disabled button is
 * not a gate at all.
 *
 * Not `final`, deliberately. A fine-grained implementation subclasses this to resolve `$target`
 * through {@see TestScopeResolver} to a `*_test` object and ask OpenFGA; the seam exists so that
 * arriving changes one class rather than a protocol.
 */
class TestRunPolicy
{
    /**
     * The roles permitted to start a run.
     *
     * The same pair UnitTestInterface enforces in `JwtAuth::RUN_TESTS_ROLES` when storing a run's
     * result, so that "may start a run" and "may store its result" stay the same question. They are
     * not shared through a package and must not be: a constant copied into two repositories is
     * precisely how the two answers would drift. This server answers, and the client is told — see
     * the `caller` object on the `hello` frame.
     *
     * @var array<int, string>
     */
    public const RUN_TESTS_ROLES = ['admin', 'test_editor'];

    /**
     * @param TestTarget|null $target What the message wants validated, when it names anything.
     *                                Accepted and ignored by this coarse implementation.
     */
    public function mayRun(WsCaller $caller, ?TestTarget $target = null): bool
    {
        if (false === $caller->authenticated) {
            return false;
        }

        return $caller->hasAnyRole(...self::RUN_TESTS_ROLES);
    }
}
