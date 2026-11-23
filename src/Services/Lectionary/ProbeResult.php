<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Lectionary;

/**
 * What one probe of a readings site found (#1074). A null date means "unchanged".
 */
final readonly class ProbeResult
{
    /**
     * @param list<string> $log one line per request: the URL and what it answered
     */
    public function __construct(
        public ?string $firstDate,
        public ?string $lastDate,
        public ?string $stoppedAt,
        public array $log
    ) {
    }
}
