<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * How a wider region layer's names are applied from its i18n file.
 *
 * `Strict` is `/calendar`'s behaviour: every name-bearing item must have a translation
 * ({@see \LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData\WiderRegionData::setNames()} throws otherwise).
 * `Lenient` is `/events`' behaviour: an item is renamed when a translation exists, and left alone when it does not.
 * Both are preserved exactly, so that sharing the loader changes neither endpoint's output.
 */
enum WiderRegionNaming
{
    case Strict;
    case Lenient;
}
