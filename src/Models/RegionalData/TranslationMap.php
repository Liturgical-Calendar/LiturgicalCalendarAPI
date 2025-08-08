<?php

namespace LiturgicalCalendar\Api\Models\RegionalData;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;

/**
 * @implements \IteratorAggregate<string, string>
 * @implements \ArrayAccess<string, string>
 * @phpstan-type TranslationMapObject \stdClass&object<string,TranslationObject>
 */
final class TranslationMap extends AbstractJsonSrcData implements \IteratorAggregate, \ArrayAccess, \Countable
{
    /** @var array<string, string> */
    private array $translations;

    /** @var string[] */
    private array $keys;

    /**
     * Constructor.
     *
     * @param array<string, string> $translations The translations data in the format [key => translation].
     */
    private function __construct(array $translations = [])
    {
        $this->translations = $translations;

        if (false === empty($translations)) {
            $this->keys = array_keys($translations);
        } else {
            $this->keys = [];
        }
    }

    /**
     * Returns an iterator for the translations in the data.
     *
     * @return \Traversable<string, string> An iterator for the translations in the data.
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->translations);
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
     * If the offset is not provided or is null, the value will be appended to the end of the translations array.
     * If the offset is provided, the value will be set at that offset.
     *
     * @param string $offset The offset to set the value to. Can be null.
     * @param string $value The value to set.
     */
    public function offsetSet($offset, $value): void
    {
        $this->translations[$offset] = $value;
        if (!in_array($offset, $this->keys)) {
            $this->keys[] = $offset;
        }
    }

    /**
     * Checks if a translation exists at the given offset.
     *
     * @param string $offset The offset to check.
     * @return bool True if the translation exists, false otherwise.
     */
    public function offsetExists($offset): bool
    {
        return isset($this->translations[$offset]);
    }

    /**
     * Unsets the translation at the specified offset.
     *
     * This will remove the translation entry and its corresponding key from the
     * translations and keys arrays.
     *
     * @param string $offset The offset of the translation to unset.
     */
    public function offsetUnset($offset): void
    {
        unset($this->translations[$offset]);
        unset($this->keys[array_search($offset, $this->keys)]);
        $this->keys = array_values($this->keys);
    }

    /**
     * Retrieves the translation value at the given offset.
     *
     * @param string $offset The offset to retrieve the translation value from.
     * @return string|null The translation value at the given offset. Null if the offset does not exist.
     */
    public function offsetGet($offset): ?string
    {
        return isset($this->translations[$offset]) ? $this->translations[$offset] : null;
    }

    /**
     * Create a new instance from an object.
     *
     * @param \stdClass&object<string,string> $i18nData The object to create the instance from.
     * @return static A new instance of this class.
     */
    public static function fromObjectInternal(\stdClass $i18nData): static
    {
        return new static((array) $i18nData);
    }

    /**
     * Create a new instance from an array.
     *
     * @param array<string,string> $i18nData The array to create the instance from.
     * @return static A new instance of this class.
     */
    public static function fromArrayInternal(array $i18nData): static
    {
        return new static($i18nData);
    }
}
