<?php

namespace LiturgicalCalendar\Api\Models\RegionalData;

final class Translations implements \IteratorAggregate, \Countable, \ArrayAccess
{
    /** @var array<string, TranslationMap> */
    private readonly array $i18nData;

    /** @var array<string> */
    private readonly array $keys;

    /**
     * Constructor for the Translations class.
     *
     * Initializes the object with the provided i18nData, mapping each element
     * to a TranslationMap object and storing the keys.
     *
     * @param array<string, array<string, string>> $i18nData The i18n data to initialize the translations with,
     * where each key corresponds to a locale and each value is an array of translations.
     */
    public function __construct(array $i18nData)
    {
        $this->keys     = array_keys($i18nData);
        $this->i18nData = array_map(fn ($translations) => new TranslationMap($translations), $i18nData);
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
     * @param string $value The value to set.
     */
    public function offsetSet($offset, $value): void
    {
        if (is_null($offset)) {
            $this->i18nData[] = $value;
            $this->keys[]     = array_key_last($this->i18nData);
        } else {
            $this->i18nData[$offset] = $value;
            if (!in_array($offset, $this->keys)) {
                $this->keys[] = $offset;
            }
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
     * @return string|null The translation value at the given offset. Null if the offset does not exist.
     */
    public function offsetGet($offset): ?string
    {
        return isset($this->i18nData[$offset]) ? $this->i18nData[$offset] : null;
    }

    public static function fromObject(\stdClass $i18nData): static
    {
        $i18nDataArray = json_decode(json_encode($i18nData), true);
        return new static($i18nDataArray);
    }

    public function getTranslation(string $event_key, string $locale): ?string
    {
        return $this->i18nData[$locale]->offsetGet($event_key);
    }
}
