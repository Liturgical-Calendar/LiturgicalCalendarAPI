<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\RegionalData;

/**
 * A wider region's display labels (#1018): `metadata.labels` in its source file, a map from a label key to text.
 *
 * A key is a bare language (`it`) or a language plus script (`zh_Hans`), not a full locale: `de_AT`, `de_DE` and
 * `de_CH` would only repeat one label. Allowed keys are `en` plus those derivable from the region's own declared
 * locales, so a region is only ever labelled in its member countries' languages (and English). Labels are edited
 * through the region's PUT/PATCH like the rest of its metadata; they are not in the gettext catalogue.
 */
final class WiderRegionLabels
{
    public const KEY_PATTERN = '/^[a-z]{2,3}(_[A-Z][a-z]{3})?$/D';

    /**
     * @param list<string> $locales The region's declared locales.
     * @return list<string> `en` first, then each key in order of first appearance.
     */
    public static function allowedKeys(array $locales): array
    {
        $keys = ['en'];
        foreach ($locales as $locale) {
            [$language, $script] = self::languageAndScript($locale);
            $keys[]              = $language;
            if ($script !== '') {
                $keys[] = "{$language}_{$script}";
            }
        }

        return array_values(array_unique(array_filter($keys, static fn (string $k): bool => $k !== '')));
    }

    /**
     * @param list<string> $locales
     * @return array<string, string> Trimmed labels; empty when `$labels` is null.
     * @throws \ValueError When `$labels` is not a map of allowed keys to non-empty strings.
     */
    public static function validate(mixed $labels, array $locales): array
    {
        if ($labels === null) {
            return [];
        }
        if ($labels instanceof \stdClass) {
            $labels = get_object_vars($labels);
        }
        if (!is_array($labels) || ( $labels !== [] && array_is_list($labels) )) {
            throw new \ValueError('`metadata.labels` must be an object mapping a language (e.g. `it`, `zh_Hans`) to a label');
        }

        $allowed = self::allowedKeys($locales);
        $out     = [];
        foreach ($labels as $key => $label) {
            $key = (string) $key;
            if (1 !== preg_match(self::KEY_PATTERN, $key) || !in_array($key, $allowed, true)) {
                throw new \ValueError(sprintf(
                    '`metadata.labels` key `%s` is not allowed: use `en` or a language of the region\'s locales (%s)',
                    $key,
                    implode(', ', $allowed)
                ));
            }
            if (!is_string($label) || trim($label) === '') {
                throw new \ValueError("`metadata.labels.{$key}` must be a non-empty string");
            }
            $out[$key] = trim($label);
        }

        return $out;
    }

    /**
     * The label for `$locale`: its language plus script, then its language, then English, then words from the id.
     *
     * `$locale` may be the lowercase, underscored form Negotiator::pickLanguage() returns (`zh_hans_cn`).
     *
     * @param array<string, string> $labels
     */
    public static function resolve(array $labels, ?string $locale, string $id): string
    {
        $candidates = [];
        if ($locale !== null && $locale !== '') {
            [$language, $script] = self::languageAndScript($locale);
            if ($script !== '') {
                $candidates[] = "{$language}_{$script}";
            }
            $candidates[] = $language;
        }
        $candidates[] = 'en';

        foreach ($candidates as $key) {
            if (isset($labels[$key]) && $labels[$key] !== '') {
                return $labels[$key];
            }
        }

        return WiderRegionId::humanize($id);
    }

    /** @return array{0: string, 1: string} Lowercase language and title-case script ('' when absent). */
    private static function languageAndScript(string $locale): array
    {
        $language = strtolower((string) \Locale::getPrimaryLanguage($locale));
        $script   = (string) \Locale::getScript($locale);

        return [$language, $script === '' ? '' : ucfirst(strtolower($script))];
    }
}
