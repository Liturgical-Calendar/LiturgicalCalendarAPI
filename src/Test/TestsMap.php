<?php

namespace LiturgicalCalendar\Api\Test;

use LiturgicalCalendar\Api\Test\TestItem;

class TestsMap
{
    /** @var array<string,TestItem> */ private array $testInstructions = [];
    /** @var array<string,int[]>    */ private array $yearsSupported   = [];

    /**
     * Adds a test to the map.
     *
     * @param string $testName the name of the test
     * @param \stdClass $testData the test data, which must be an object with the properties
     *                            expected by the {@see TestItem} constructor.
     */
    public function add(string $testName, \stdClass $testData): void
    {
        $this->testInstructions[$testName] = new TestItem($testData);

        $years = [];
        foreach ($this->testInstructions[$testName]->assertions as $assertion) {
            $years[] = $assertion->year;
        }
        $this->yearsSupported[$testName] = $years;
    }

    /**
     * Returns true if the given test name is already defined in the tests map.
     * @param string $testName the name of the test
     * @return bool true if the test exists, false otherwise
     */
    public function has(string $testName): bool
    {
        return array_key_exists($testName, $this->testInstructions);
    }

    /**
     * Indicates whether the given test name is ready to run.
     *
     * For a test to be ready, it must have its test instructions and supported years
     * stored in the map, and the supported years must not be empty.
     *
     * @param string $testName the name of the test
     * @return bool true if the test is ready, false otherwise
     */
    public function isReady(string $testName): bool
    {
        return (
            array_key_exists($testName, $this->yearsSupported)
            && array_key_exists($testName, $this->testInstructions)
            && count($this->yearsSupported[$testName]) > 0
        );
    }

    /**
     * Retrieves the assertion for a given year, if it exists.
     *
     * @param string $testName The name of the test.
     * @param int    $year     The year for which to retrieve the assertion.
     * @return null|AssertionItem The assertion, or null if no assertion exists for the given year.
     */
    public function retrieveAssertionForYear(string $testName, int $year): ?AssertionItem
    {
        if ($this->isReady($testName)) {
            foreach ($this->testInstructions[$testName]->assertions as $assertion) {
                if ($assertion->year === $year) {
                    return $assertion;
                }
            }
        }
        return null;
    }

    /**
     * Retrieves the test item for the given test name.
     *
     * @param string $testName The name of the test.
     * @return TestItem The test item for the given test name.
     */
    public function get(string $testName): TestItem
    {
        return $this->testInstructions[$testName];
    }

    /**
     * Retrieves the years supported for the given test name.
     *
     * @param string $testName The name of the test.
     * @return int[] The years supported for the given test name.
     */
    public function getYearsSupported(string $testName): array
    {
        return $this->yearsSupported[$testName];
    }
}
