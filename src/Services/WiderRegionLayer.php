<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData\WiderRegionData;

/** One wider region's calendar layer, loaded and named for a request's locale. */
final class WiderRegionLayer
{
    public function __construct(
        public readonly string $region,
        public readonly WiderRegionData $data,
        /** The region's lectionary for the locale, or null when it has none. */
        public readonly ?string $lectionaryFile
    ) {
    }
}
