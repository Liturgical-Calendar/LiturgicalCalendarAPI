<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Lectionary;

/**
 * A readings site refused a probe request (403, 429, or another unexpected status).
 */
final class ProbeRefused extends \RuntimeException
{
}
