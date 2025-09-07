<?php

namespace LiturgicalCalendar\Api\Models;

/**
 * Represents a collection of liturgical calendar items from a JSON source.
 *
 * This class extends AbstractJsonSrcData and implements IteratorAggregate to provide iteration over the items in the collection.
 *
 * @phpstan-type LiturgicalEventArray array{
 *      event_key: string,
 *      missal: string,
 *      grade_lcl: string,
 *      common_lcl: string,
 *      name: string,
 *      common: string[],
 *      calendar: string,
 *      decree?: string,
 *      grade: int
 * }
 *
 * @phpstan-type LiturgicalEventObject \stdClass&object{
 *      event_key: string,
 *      missal: string,
 *      grade_lcl: string,
 *      common_lcl: string,
 *      name: string,
 *      common: string[],
 *      calendar: string,
 *      decree?: string,
 *      grade: int
 * }
 *
 * @implements \IteratorAggregate<LitCalItem>
 */
final class LitCalItemCollection extends AbstractJsonSrcDataArray implements \IteratorAggregate
{
    /** @var array<LitCalItem> */
    public readonly array $litcalItems;

    /**
     * Constructs a new LitCalItemCollection instance.
     *
     * This constructor initializes the collection with an array of liturgical calendar items.
     * Each item in the array is converted into an instance of LitCalItem.
     *
     * @param array<LitCalItem> $litcalItems An array of liturgical calendar items.
     */
    private function __construct(array $litcalItems)
    {
        $this->litcalItems = $litcalItems;
    }

    /**
     * @return \Traversable<LitCalItem> An iterator for the items in the collection.
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->litcalItems);
    }


    /**
     * Creates an instance of LitCalItemCollection from an associative array.
     *
     * The array must not be empty.
     * The elements of the array can be either associative arrays or objects.
     * If the elements are associative arrays, they must have the same keys as the properties of LitCalItem.
     * If the elements are objects, they must have the same properties as LitCalItem.
     *
     * @param array<LiturgicalEventArray|\stdClass> $data The associative array containing the properties of the class.
     * @return static The newly created instance.
     * @throws \TypeError if the array is empty.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (0 === count($data)) {
            throw new \TypeError('litcal parameter must be an array and must not be empty');
        }
        if (reset($data) instanceof \stdClass) {
            /** @var array<\stdClass> $data */
            $items = array_values(array_map(fn (\stdClass $litcalItem): LitCalItem => LitCalItem::fromObject($litcalItem), $data));
        } else {
            /** @var array<LiturgicalEventArray> $data */
            $items = array_values(array_map(fn (array $litcalItem): LitCalItem => LitCalItem::fromArray($litcalItem), $data));
        }
        return new static($items);
    }

    /**
     * Creates an instance of LitCalItemCollection from an array of stdClass objects.
     *
     * The input array must be non-empty and contain stdClass objects that can be
     * converted into LitCalItem instances.
     *
     * @param LiturgicalEventObject[] $data An array of stdClass objects containing the properties of the class.
     * @return static The newly created instance.
     * @throws \TypeError If the array is empty.
     * @throws \InvalidArgumentException If the elements are not stdClass objects.
     */
    protected static function fromObjectInternal(array $data): static
    {
        if (0 === count($data)) {
            throw new \TypeError('litcal parameter must be an array and must not be empty');
        }
        if (reset($data) instanceof \stdClass) {
            /** @var array<\stdClass> $data */
            return new static(array_map(fn (\stdClass $litcalItem): LitCalItem => LitCalItem::fromObject($litcalItem), $data));
        } else {
            throw new \InvalidArgumentException('Perhaps you should be calling fromArray?');
        }
    }
}
