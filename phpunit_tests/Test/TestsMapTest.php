<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Test;

use LiturgicalCalendar\Api\Test\TestsMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Companion unit tests for TestsMap that exercise the lookup branches that
 * the WS-driven integration tests skip — `get()` (covered indirectly via
 * the WS path) plus the negative paths of `has()`, `isReady()`,
 * `retrieveAssertionForYear()`, and `getYearsSupported()`.
 */
#[CoversClass(TestsMap::class)]
final class TestsMapTest extends TestCase
{
    private function buildAssertion(int $year): \stdClass
    {
        return (object) [
            'year'           => $year,
            'expected_value' => null,
            'assert'         => 'eventNotExists',
            'assertion'      => 'note',
        ];
    }

    private function buildTestObject(string $name, int ...$years): \stdClass
    {
        $assertions = [];
        foreach ($years as $y) {
            $assertions[] = $this->buildAssertion($y);
        }
        return (object) [
            'name'        => $name,
            'event_key'   => 'evt',
            'description' => 'd',
            'test_type'   => 'exactCorrespondenceSince',
            'assertions'  => $assertions,
        ];
    }

    public function testHasReturnsFalseForUnknownTest(): void
    {
        $map = new TestsMap();

        $this->assertFalse($map->has('Nope'));
        $this->assertFalse($map->isReady('Nope'));
    }

    public function testAddPopulatesHasIsReadyAndGetYearsSupported(): void
    {
        $map = new TestsMap();
        $map->add('SampleTest', $this->buildTestObject('SampleTest', 2018, 2019, 2020));

        $this->assertTrue($map->has('SampleTest'));
        $this->assertTrue($map->isReady('SampleTest'));
        $this->assertSame([2018, 2019, 2020], $map->getYearsSupported('SampleTest'));
    }

    public function testGetReturnsTheUnderlyingTestItem(): void
    {
        $map = new TestsMap();
        $map->add('SampleTest', $this->buildTestObject('SampleTest', 2020));

        $item = $map->get('SampleTest');
        $this->assertSame('SampleTest', $item->name);
    }

    public function testRetrieveAssertionForKnownYearReturnsTheAssertion(): void
    {
        $map = new TestsMap();
        $map->add('SampleTest', $this->buildTestObject('SampleTest', 2018, 2019, 2020));

        $assertion = $map->retrieveAssertionForYear('SampleTest', 2019);
        $this->assertNotNull($assertion);
        $this->assertSame(2019, $assertion->year);
    }

    public function testRetrieveAssertionForUnknownYearReturnsNull(): void
    {
        $map = new TestsMap();
        $map->add('SampleTest', $this->buildTestObject('SampleTest', 2018));

        $this->assertNull($map->retrieveAssertionForYear('SampleTest', 1999));
    }

    public function testRetrieveAssertionForUnknownTestReturnsNull(): void
    {
        $map = new TestsMap();

        $this->assertNull($map->retrieveAssertionForYear('Nope', 2020));
    }

    public function testIsReadyReturnsFalseForEmptyAssertions(): void
    {
        $map = new TestsMap();
        $map->add('NoAssertions', $this->buildTestObject('NoAssertions'));

        $this->assertTrue($map->has('NoAssertions'));
        $this->assertFalse($map->isReady('NoAssertions'), 'A test with no assertions should not be considered ready');
    }
}
