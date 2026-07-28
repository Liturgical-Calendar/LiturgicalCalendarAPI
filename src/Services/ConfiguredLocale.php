<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * Immutable result of {@see LocaleConfigurator::configure()}: the process-global
 * locale state that was applied for a request.
 */
final class ConfiguredLocale
{
    /**
     * @param string $primaryLanguage Primary language subtag (e.g. 'it', 'en', 'la').
     * @param string $runtimeLocale   Normalized runtime locale in effect (e.g. 'it_IT',
     *                                 'en_US', or 'la' for the Latin reset branch).
     * @param bool   $isLatin         True when the Latin/reset branch was taken.
     */
    public function __construct(
        public readonly string $primaryLanguage,
        public readonly string $runtimeLocale,
        public readonly bool $isLatin
    ) {
    }
}
