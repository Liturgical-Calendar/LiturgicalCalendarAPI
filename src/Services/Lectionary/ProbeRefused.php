<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Lectionary;

/**
 * A probe stopped and kept the dates: the site refused a request (403, 429, or another
 * unexpected status), or no longer publishes any day near the recorded range.
 */
final class ProbeRefused extends \RuntimeException
{
}
