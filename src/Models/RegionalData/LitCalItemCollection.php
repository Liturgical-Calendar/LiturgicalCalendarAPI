<?php

namespace LiturgicalCalendar\Api\Models\RegionalData;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;

/**
 * Represents a collection of liturgical calendar items.
 *
 * This class extends AbstractJsonSrcData and implements IteratorAggregate to provide iteration over the items in the collection.
 *
 * @implements \IteratorAggregate<LitCalItem>
 * @phpstan-import-type LiturgicalEventItem from \LiturgicalCalendar\Api\Paths\EventsPath
 */
final class LitCalItemCollection extends AbstractJsonSrcData implements \IteratorAggregate
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


    protected static function fromArrayInternal(array $data): static
    {
        if (0 === count($data)) {
            throw new \TypeError('litcal parameter must be an array and must not be empty');
        }
        if (reset($data) instanceof \stdClass) {
            /** @var array<\stdClass> $data */
            $items = array_values(array_map(fn (\stdClass $litcalItem): LitCalItem => LitCalItem::fromObject($litcalItem), $data));
        } else {
            /** @var array<LiturgicalEventItem> $data */
            $items = array_values(array_map(fn (array $litcalItem): LitCalItem => LitCalItem::fromArray($litcalItem), $data));
        }
        return new static($items);
    }

    protected static function fromObjectInternal(object $data): static
    {
        // Cannot use fromObjectInternal because $data will always be an array
        throw new \InvalidArgumentException('Cannot use fromObjectInternal because $data will always be an array');
    }
}
