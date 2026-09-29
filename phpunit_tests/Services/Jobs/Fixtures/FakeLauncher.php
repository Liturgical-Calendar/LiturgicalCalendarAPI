<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs\Fixtures;

use LiturgicalCalendar\Api\Services\Jobs\ChildLauncher;
use LiturgicalCalendar\Api\Services\Jobs\ChildProcess;

/** Records every start and hands back a FakeChild the test can drive. */
final class FakeLauncher implements ChildLauncher
{
    /** @var list<FakeChild> */
    public array $started = [];

    public bool $exitOnTerm = false;

    public function start(string $jobName): ChildProcess
    {
        $child           = new FakeChild($jobName, $this->exitOnTerm);
        $this->started[] = $child;

        return $child;
    }

    /** @return list<string> */
    public function startedNames(): array
    {
        return array_map(static fn (FakeChild $c): string => $c->jobName, $this->started);
    }

    public function last(string $jobName): FakeChild
    {
        foreach (array_reverse($this->started) as $child) {
            if ($child->jobName === $jobName) {
                return $child;
            }
        }
        throw new \LogicException("{$jobName} was never started");
    }
}
