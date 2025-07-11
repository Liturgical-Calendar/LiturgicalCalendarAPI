<?php

namespace LiturgicalCalendar\Api\Test;

use LiturgicalCalendar\Api\Test\AssertionItem;

/**
 * A collection of {@see AssertionItem} objects, representing AssertionItem assertions for a TestItem.
 *
 * @phpstan-implements \IteratorAggregate<AssertionItem>
 */
class AssertionCollection implements \IteratorAggregate
{
    /** @var AssertionItem[] */
    private array $assertions = [];

    /**
     * Constructs an AssertionCollection from an array of assertions.
     *
     * Each assertion must be an object with the required properties for an AssertionItem.
     *
     * @param array<object{year:int,expected_value:int,assert:string,assertion:string,comment:string}> $assertions The assertions to include in the collection.
     *
     * @see AssertionItem::__construct() for the required properties of each assertion.
     */
    public function __construct(array $assertions)
    {
        foreach ($assertions as $assertion) {
            $this->assertions[] = new AssertionItem($assertion);
        }
    }

    /**
     * Returns an iterator for the assertions in the collection.
     *
     * @return \Traversable<AssertionItem> An iterator for the assertions in the collection.
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->assertions);
    }
}
