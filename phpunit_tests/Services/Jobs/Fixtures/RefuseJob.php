<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs\Fixtures;

use LiturgicalCalendar\Api\Services\Jobs\DestructiveJob;
use LiturgicalCalendar\Api\Services\Jobs\JobContext;

/** A destructive job whose preflight always refuses; running it anyway is a bug the test must see. */
final class RefuseJob implements DestructiveJob
{
    public static int $runs = 0;

    public function preflight(): ?string
    {
        return 'tree missing';
    }

    public function run(JobContext $context): void
    {
        ++self::$runs;
    }
}
