<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Services\ResourceTuplePurgeServiceInterface;

/**
 * Records which FGA objects were purged, so a test can assert on the exact object string — the
 * thing that goes wrong here is a double-qualified id (`roman/roman/US`), and only the string
 * shows that.
 */
final class RecordingTuplePurgeService implements ResourceTuplePurgeServiceInterface
{
    /** @var list<string> */
    public array $purged = [];

    public function __construct(private readonly ?\Throwable $throws = null)
    {
    }

    public function purgeForObject(string $fgaObject): int
    {
        $this->purged[] = $fgaObject;

        if (null !== $this->throws) {
            throw $this->throws;
        }

        return 1;
    }
}
