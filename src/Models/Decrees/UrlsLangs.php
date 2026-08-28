<?php

namespace LiturgicalCalendar\Api\Models\Decrees;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;

/**
 * Explicit per-language decree URLs, overriding what `url` + `url_lang_map` would produce.
 *
 * The `url` + `url_lang_map` pair covers the common case, where every language shares one
 * URL template differing only by a language token substituted into the `%s` placeholder.
 * Some decrees break that rule: the Latin text of the Newman decree, for instance, is
 * published as an annex to the Italian page, so both its path segment and its filename
 * differ from every other language.
 *
 * This map is the escape hatch for those languages. It is a sparse override, not a cache:
 * a language present here takes its URL verbatim, and any language absent from it still
 * derives its URL from the template. A decree may therefore carry an override for a single
 * language while all the others continue to resolve through `url_lang_map`.
 *
 * @see DecreeEventMetadata::getUrl() for the resolution order
 */
final class UrlsLangs extends AbstractJsonSrcData
{
    /** @var array<string,string> */
    public readonly array $urls_langs;

    /**
     * @param array<string,string> $urls_langs Map of ISO 639-1 language code to the full decree URL for that language
     */
    private function __construct(array $urls_langs)
    {
        if (empty($urls_langs)) {
            throw new \InvalidArgumentException('UrlsLangs must not be empty.');
        }

        $sanitized = [];
        foreach ($urls_langs as $lang => $url) {
            // Validate, not merely sanitize. An override is a finished URL that replaces the
            // template outright, and this class is constructible directly — a caller that has
            // not been through the schema (or has pasted a language token, or the template
            // itself) must be refused here rather than silently stored.
            $filtered = filter_var($url, FILTER_SANITIZE_URL);
            if (false === $filtered || false === filter_var($filtered, FILTER_VALIDATE_URL)) {
                throw new \ValueError("`urls_langs.{$lang}` must be a valid URL");
            }
            $sanitized[$lang] = htmlspecialchars($filtered, ENT_QUOTES);
        }

        $this->urls_langs = $sanitized;
    }

    /**
     * Creates a new instance from an associative array.
     *
     * @param array<string,string> $data Map of ISO 639-1 language codes to full decree URLs.
     * @return static A new instance of the class.
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static($data);
    }

    /**
     * Creates a new instance from an object.
     *
     * @param \stdClass&object<string,string> $data The object to create an instance from.
     * @return static A new instance of the class.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        /** @var array<string,string> $dataArray */
        $dataArray = (array) $data;
        return new static($dataArray);
    }

    /**
     * Retrieves the overriding URL for a language, or null when that language has no override.
     *
     * Unlike UrlLangMap::getBestLangFromMap(), this deliberately does NOT fall back to another
     * language: an absent language means "no override", which sends the caller back to the
     * `url` + `url_lang_map` template. Falling back here would hand one language another
     * language's document.
     *
     * @param string $lang The ISO 639-1 language code (a regional tag such as `en-US` is reduced to its primary subtag)
     * @return string|null The full URL for that language, or null when none is defined
     */
    public function get(string $lang): ?string
    {
        $baseLocale = \Locale::getPrimaryLanguage($lang);
        if (null === $baseLocale) {
            throw new \InvalidArgumentException('Invalid language code: ' . $lang);
        }

        return $this->urls_langs[$baseLocale] ?? null;
    }
}
