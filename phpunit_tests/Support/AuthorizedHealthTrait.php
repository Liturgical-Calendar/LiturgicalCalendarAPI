<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

use LiturgicalCalendar\Api\Models\Auth\TestTarget;
use LiturgicalCalendar\Api\Models\Auth\WsCaller;
use LiturgicalCalendar\Api\Services\TestRunPolicy;
use PHPUnit\Framework\Attributes\Before;

/**
 * Declares that a suite exercises behaviour *downstream* of the #894 permission gate.
 *
 * Those suites drive `Health::onMessage()` directly, without a handshake, so no `WsCaller` was ever
 * settled for the connection — and an unknown connection reads as anonymous, which is the fail-closed
 * direction the gate is built on. Every one of their messages would be refused before reaching the
 * behaviour they are actually about.
 *
 * The authorization is stated **once per suite** rather than once per `newHealth()` call, and stated
 * as a policy rather than as a seeded caller. Both choices matter:
 *
 *  - Once per suite, because "this file is not about the gate" is a property of the file. Threading a
 *    permissive argument through ~150 call sites would say the same thing 150 times and let one of
 *    them drift.
 *  - A policy rather than a caller, because a caller has to be seeded per `resourceId` and these
 *    suites mint connection ids freely; a policy is answered for whatever connection turns up.
 *
 * It is deliberately **not** the default in {@see HealthQueueIsolationTrait}. A default that
 * authorized every test Health would make the gate invisible to the suite — the precise failure mode
 * this issue exists to fix, reintroduced one layer down. A suite opts in, in writing.
 *
 * {@see \LiturgicalCalendar\Tests\HealthActionGateTest} is the suite that must never use this.
 */
trait AuthorizedHealthTrait
{
    #[Before]
    protected function authorizeHealthByDefault(): void
    {
        $this->defaultPolicy = new class extends TestRunPolicy {
            public function mayRun(WsCaller $caller, ?TestTarget $target = null): bool
            {
                return true;
            }
        };
    }
}
