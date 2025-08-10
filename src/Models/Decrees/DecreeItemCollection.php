<?php

namespace LiturgicalCalendar\Api\Models\Decrees;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\AbstractJsonSrcDataArray;

/**
 * Represents a collection of liturgical calendar items from a JSON source.
 *
 * This class extends AbstractJsonSrcData and implements IteratorAggregate to provide iteration over the items in the collection.
 * @phpstan-import-type DecreeItemFromArray from DecreeItem
 * @phpstan-import-type DecreeItemFromObject from DecreeItem
 * @implements \IteratorAggregate<DecreeItem>
 */
final class DecreeItemCollection extends AbstractJsonSrcDataArray implements \IteratorAggregate, \Countable
{
    /** @var array<DecreeItem> */
    public readonly array $decreeItems;

    /**
     * Constructs a new DecreeItemCollection instance.
     *
     * This constructor initializes the collection with an array of liturgical calendar items.
     * Each item in the array is converted into an instance of DecreeItem.
     *
     * @param array<DecreeItem> $decreeItems An array of liturgical calendar items.
     */
    private function __construct(array $decreeItems)
    {
        $this->decreeItems = $decreeItems;
    }

    /**
     * @return \Traversable<DecreeItem> An iterator for the items in the collection.
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->decreeItems);
    }

    /**
     * Returns the number of DecreeItems in the collection.
     *
     * @return int The number of DecreeItems in the collection.
     */
    public function count(): int
    {
        return count($this->decreeItems);
    }

    /**
     * Sets the names for DecreeItems of CreateNew or SetPropertyName.
     *
     * This method takes an associative array of translations as its parameter,
     * where the keys are the event keys of the liturgical items and the values
     * are the translated names. The method then iterates over the liturgical
     * calendar items and sets their name based on the translations available
     * for the specified event key.
     *
     * @param DecreeItemFromObject[] $items An array of potential DecreeItem objects.
     * @param array<string, string> $names The translations to use for setting the names.
     */
    public static function setNames(array $items, array $names): void
    {
        foreach ($items as $decree) {
            if (
                $decree->metadata->action === CalEventAction::CreateNew->value
                || $decree->metadata->action === CalEventAction::MakeDoctor->value
                || $decree->metadata->action === CalEventAction::SetProperty->value && $decree->metadata->property === 'name'
            ) {
                $decree->liturgical_event->name = $names[$decree->liturgical_event->event_key];
            }
        }
    }

    /**
     * @return array<DecreeItem> An array of liturgical calendar items, filtered so that only the decrees
     * that make a liturgical event a doctor of the Church are included.
     */
    public function getDoctorDecrees(): array
    {
        return array_filter(
            $this->decreeItems,
            fn (DecreeItem $decree): bool => $decree->metadata instanceof DecreeItemMakeDoctorMetadata
        );
    }

    /**
     * Filters the collection to include only decrees that set the grade of a liturgical event
     * to the specified grade.
     *
     * @param LitGrade $grade The grade to filter the decrees by.
     * @return array<DecreeItem> An array of decrees where the liturgical event's grade matches the specified grade.
     */
    public function filterByGrade(LitGrade $grade): array
    {
        return array_filter(
            $this->decreeItems,
            fn (DecreeItem $decree): bool => (
                $decree->liturgical_event instanceof DecreeItemCreateNewFixed
                || $decree->liturgical_event instanceof DecreeItemCreateNewMobile
                || $decree->liturgical_event instanceof DecreeItemSetPropertyGrade
            ) && $decree->liturgical_event->grade === $grade
        );
    }

    /**
     * Creates a new instance of DecreeItemCollection from an array of decree items.
     *
     * The array must not be empty and must contain either an array of stdClass objects
     * or an array of associative arrays with the same keys as DecreeItem.
     *
     * @param array<DecreeItemFromObject|DecreeItemFromArray> $data
     * @return static
     * @throws \TypeError If the array is empty or does not contain the expected types.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (0 === count($data)) {
            throw new \TypeError('litcal parameter must be an array and must not be empty');
        }
        if (reset($data) instanceof \stdClass) {
            /** @var DecreeItemFromObject[] $data */
            $items = array_values(array_map(fn (\stdClass $decreeItem): DecreeItem => DecreeItem::fromObject($decreeItem), $data));
        } else {
            /** @var DecreeItemFromArray[] $data */
            $items = array_values(array_map(fn (array $decreeItem): DecreeItem => DecreeItem::fromArray($decreeItem), $data));
        }
        return new static($items);
    }

    /**
     * Creates a new instance or instances of DecreeItemCollection from an array of stdClass objects.
     *
     * This method requires an array that is not empty and contains stdClass objects
     * that can be mapped to DecreeItem instances.
     *
     * @param \stdClass[] $data The input data for creating the instance(s).
     * @return static The newly created instance(s).
     * @throws \TypeError If the provided array is empty.
     * @throws \InvalidArgumentException If the input data is not an array of stdClass objects.
     */
    protected static function fromObjectInternal(array $data): static
    {
        if (0 === count($data)) {
            throw new \TypeError('expected a non empty array');
        }
        if (reset($data) instanceof \stdClass) {
            /** @var array<\stdClass> $data */
            return new static(array_map(fn (\stdClass $decreeItem): DecreeItem => DecreeItem::fromObject($decreeItem), $data));
        } else {
            throw new \InvalidArgumentException('Perhaps you should be calling fromArray?');
        }
    }
}
