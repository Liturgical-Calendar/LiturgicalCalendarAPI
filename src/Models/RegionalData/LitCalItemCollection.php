<?php

namespace LiturgicalCalendar\Api\Models\RegionalData;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;

class LitCalItemCollection extends AbstractJsonSrcData implements \IteratorAggregate
{
    public readonly array $litcalItems;

    /**
     * Constructs a new LitCalItemCollection instance.
     *
     * This constructor initializes the collection with an array of liturgical calendar items.
     * Each item in the array is converted into an instance of LitCalItem.
     *
     * @param array<LitCalItem> $litcalItems An array of liturgical calendar items.
     */
    public function __construct(array $litcalItems)
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


    protected static function fromArrayInternal(array $data): static
    {
        if (false === is_array($data) || 0 === count($data)) {
            throw new \TypeError('litcal parameter must be an array and must not be empty');
        }
        if ($data[0] instanceof \stdClass) {
            $items = array_map(fn ($litcalItem) => LitCalItem::fromObject($litcalItem), $data);
        } else {
            $items = array_map(fn ($litcalItem) => LitCalItem::fromArray($litcalItem), $data);
        }
        return new static($items);
    }

    protected static function fromObjectInternal(object $data): static
    {
        // Cannot use fromObjectInternal because $data will always be an array
        throw new \InvalidArgumentException('Cannot use fromObjectInternal because $data will always be an array');
    }
}
