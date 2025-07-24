<?php

namespace LiturgicalCalendar\Api\Models\RegionalData;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;

/**
 * Represents a map from ISO 639-1 language codes to custom Vatican website language codes
 * as used in the Vatican URLs.
 *
 * @implements \ArrayAccess<string,string>
 */
final class UrlLangMap extends AbstractJsonSrcData implements \ArrayAccess
{
    /** @var array<string,string> */
    public array $url_lang_map;

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
        $this->url_lang_map = $url_lang_map;
    }

    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->url_lang_map);
    }

    public function offsetGet($offset): string
    {
        return $this->url_lang_map[$offset];
    }

    public function offsetSet($offset, $value): void
    {
        $this->url_lang_map[$offset] = $value;
    }

    public function offsetUnset($offset): void
    {
        unset($this->url_lang_map[$offset]);
    }

    protected static function fromArrayInternal(array $data): static
    {
        return new static($data);
    }

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
        if (array_key_exists($baseLocale, $this->url_lang_map)) {
            return $this->url_lang_map[$lang];
        } elseif (array_key_exists('la', $this->url_lang_map)) {
            return $this->url_lang_map['la'];
        } elseif (array_key_exists('en', $this->url_lang_map)) {
            return $this->url_lang_map['en'];
        } else {
            $firstLang = reset($this->url_lang_map);
            return is_string($firstLang) ? $firstLang : $baseLocale;
        }
    }
}
