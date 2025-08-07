<?php

namespace LiturgicalCalendar\Api\Models\RegionalData;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;

/**
 * Represents a map from ISO 639-1 language codes to custom Vatican website language codes
 * as used in the Vatican URLs.
 */
final class UrlLangMap extends AbstractJsonSrcData
{
    /** @var array<string,string> */
    public readonly array $url_lang_map;

    /**
     * A locale code mapping for dealing with Vatican URLs that use non standard
     * language codes.
     *
     * The keys are the ISO 639-1 language codes and the values are the custom Vatican website language codes
     * as used in the Vatican URLs.
     *
     * @param array<string,string> $url_lang_map The ISO 639-1 language code to custom Vatican website language code map
     */
    private function __construct(array $url_lang_map)
    {
        if (empty($url_lang_map)) {
            throw new \InvalidArgumentException('UrlLangMap must not be empty.');
        }
        $this->url_lang_map = $url_lang_map;
    }

    /**
     * Creates a new instance from an associative array.
     *
     * @param array<string,string> $data The associative array containing the map of ISO 639-1 language codes to custom Vatican website language codes.
     * @return static A new instance of the class.
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static($data);
    }

    /**
     * Creates a new instance from an object.
     *
     * @param \stdClass $data The object to create an instance from.
     * @return static A new instance of the class.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static((array) $data);
    }

    /**
     * Retrieves the best custom Vatican website language code from the UrlLangMap object
     * given an ISO 639-1 language code.
     *
     * The best custom Vatican website language code is determined as follows:
     *
     * 1. If the UrlLangMap has a property with the value of
     *    $baseLocale, that value is returned.
     * 2. If the object has a "la" property, that value is returned.
     * 3. If the object has an "en" property, that value is returned.
     * 4. Otherwise, the key of the first property in the object is returned.
     *
     * @param string $lang The ISO 639-1 language code
     *
     * @return string The string associated with the best language code
     */
    public function getBestLangFromMap(string $lang): string
    {
        $baseLocale = \Locale::getPrimaryLanguage($lang);
        if (null === $baseLocale) {
            throw new \InvalidArgumentException('Invalid language code: ' . $lang);
        }

        if (array_key_exists($baseLocale, $this->url_lang_map)) {
            return $this->url_lang_map[$lang];
        } elseif (array_key_exists('la', $this->url_lang_map)) {
            return $this->url_lang_map['la'];
        } elseif (array_key_exists('en', $this->url_lang_map)) {
            return $this->url_lang_map['en'];
        } else {
            $firstLang = array_values($this->url_lang_map)[0];
            return $firstLang;
        }
    }
}
