<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

use LiturgicalCalendar\Api\Health;

/**
 * Stops a test's queued `Health` requests from being fetched for real once the test is over.
 *
 * `Health::cachedGet()` does two things: it appends the request to the private `$queue`, and it
 * registers a ReactPHP `futureTick`. Neither runs during the test — nothing drives the loop — so a
 * queued entry is an inert record, which is exactly what makes it a good thing to assert against.
 * The catch is what happens afterwards: `React\EventLoop\Loop` installs a **shutdown function that
 * runs the loop when the process ends**, so anything still queued when PHPUnit finishes is
 * dispatched for real, outside any test, with no assertions watching and no failure if it fails.
 *
 * For `Health` those requests are full calendar computations against the API server named in
 * `.env.local` — hundreds of kilobytes each, and a suite that quietly stops being runnable when
 * that server is down. Emptying the queue is sufficient: `drainHandler()` stops ticking as soon as
 * it finds `inFlight === 0` and an empty queue.
 *
 * The protection is a trait, and not a `tearDown()` copied into each test class, because the trap
 * is invisible from the test that falls into it — the test passes, and the damage happens after the
 * run. Anything that builds a `Health` should get this for free rather than by remembering.
 *
 * Build instances with {@see newHealth()}; a `new Health()` written by hand is not tracked and not
 * protected.
 */
trait HealthQueueIsolationTrait
{
    /**
     * Every Health this test built, so tearDown can empty its queue.
     *
     * @var list<Health>
     */
    private array $trackedHealths = [];

    /**
     * A Health whose queue will be defused when the test ends.
     */
    protected function newHealth(): Health
    {
        $health                 = new Health();
        $this->trackedHealths[] = $health;

        return $health;
    }

    /**
     * Empty the request queue of every tracked Health.
     *
     * `$queue` is a private *instance* property, so this writes the real one rather than a copy.
     */
    protected function defuseHealthQueues(): void
    {
        $queue = new \ReflectionProperty(Health::class, 'queue');
        foreach ($this->trackedHealths as $health) {
            $queue->setValue($health, []);
        }
        $this->trackedHealths = [];
    }

    protected function tearDown(): void
    {
        $this->defuseHealthQueues();
        parent::tearDown();
    }
}
