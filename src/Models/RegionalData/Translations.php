<?php

namespace LiturgicalCalendar\Api\Models\RegionalData;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;

/**
 * A class representing a collection of translations for different locales.
 *
 * @implements \IteratorAggregate<string,TranslationMap>
 * @implements \ArrayAccess<string,TranslationMap>
 */
final class Translations extends AbstractJsonSrcData implements \IteratorAggregate, \Countable, \ArrayAccess
{
    /** @var array<string,TranslationMap> */
    private array $i18nData;

    /** @var string[] */
    private array $keys;

    /**
     * Constructor for the Translations class.
     *
     * Initializes the object with the provided i18nData, mapping each element
     * to a TranslationMap object and storing the keys.
     *
     * @param array<string,array<string,string>> $i18nData The i18n data to initialize the translations with,
     * where each key corresponds to a locale and each value is an array of translations.
     */
    private function __construct(array $i18nData)
    {
        $this->keys     = array_keys($i18nData);
        $this->i18nData = array_map(fn (array $translations) => TranslationMap::fromArray($translations), $i18nData);
    }

    /**
     * Returns an iterator for the translations in the data.
     *
     * @return \Traversable<string, TranslationMap> An iterator for the translations in the data.
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->i18nData);
    }

    /**
     * Returns the number of translation keys.
     *
     * @return int The count of translation keys.
     */
    public function count(): int
    {
        return count($this->keys);
    }


    /**
     * Set a translation value.
     *
     * If the offset is not provided or is null, the value will be appended to the end of the i18nData array.
     * If the offset is provided, the value will be set at that offset.
     *
     * @param mixed $offset The offset to set the value to. Can be null.
     * @param TranslationMap $value The value to set.
     */
    public function offsetSet($offset, $value): void
    {
        $this->i18nData[$offset] = $value;
        if (!in_array($offset, $this->keys)) {
            $this->keys[] = $offset;
        }
    }

    /**
     * Checks if a translation exists at the given offset.
     *
     * @param mixed $offset The offset to check.
     * @return bool True if the translation exists, false otherwise.
     */
    public function offsetExists($offset): bool
    {
        return isset($this->i18nData[$offset]);
    }

    /**
     * Unsets the translation at the specified offset.
     *
     * This will remove the translation entry and its corresponding key from the
     * i18nData and keys arrays.
     *
     * @param mixed $offset The offset of the translation to unset.
     */
    public function offsetUnset($offset): void
    {
        unset($this->i18nData[$offset]);
        unset($this->keys[array_search($offset, $this->keys)]);
        $this->keys = array_values($this->keys);
    }

    /**
     * Retrieves the translation value at the given offset.
     *
     * @param mixed $offset The offset to retrieve the translation value from.
     * @return TranslationMap|null The translation value at the given offset. Null if the offset does not exist.
     */
    public function offsetGet($offset): ?TranslationMap
    {
        return isset($this->i18nData[$offset]) ? $this->i18nData[$offset] : null;
    }

    /**
     * Creates an instance of this class from an object.
     *
     * The object should have the same structure as the i18nData property.
     *
     * @param \stdClass $i18nData The object to create the instance from.
     * @return static A new instance of this class.
     */
    protected static function fromObjectInternal(\stdClass $i18nData): static
    {
        return new static(get_object_vars($i18nData));
    }

    /**
     * Creates an instance of this class from an associative array.
     *
     * The array should have the following structure:
     * <code>
     * [
     *     'locale_1' => [
     *         'event_key_1' => 'translation_1',
     *         'event_key_2' => 'translation_2',
     *         ...
     *     ],
     *     'locale_2' => [
     *         'event_key_1' => 'translation_1',
     *         'event_key_2' => 'translation_2',
     *         ...
     *     ],
     *     ...
     * ]
     * </code>
     *
     * @param array<string,array<string,string>> $i18nData The associative array to create the instance from.
     * @return static A new instance of this class.
     */
    protected static function fromArrayInternal(array $i18nData): static
    {
        return new static($i18nData);
    }

    /**
     * Retrieves the translation for a specific event key in the given locale.
     *
     * @param string $event_key The key of the event to retrieve the translation for.
     * @param string $locale The locale to retrieve the translation in.
     * @return string|null The translated string for the given event key and locale, or null if not found.
     */
    public function getTranslation(string $event_key, string $locale): ?string
    {
        return $this->i18nData[$locale]->offsetGet($event_key);
    }
}
