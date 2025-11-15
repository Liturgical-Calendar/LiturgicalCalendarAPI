<?php

namespace LiturgicalCalendar\Api\Test;

use Swaggest\JsonSchema\Schema;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitEventTestAssertion;
use LiturgicalCalendar\Api\Test\TestsMap;

/**
 * @phpstan-type LiturgicalEvent object{
 *      event_key:string,
 *      event_idx:int,
 *      name:string,
 *      year:int,
 *      month:int,
 *      month_short:string,
 *      month_long:string,
 *      day:int,
 *      type:string,
 *      grade:int,
 *      grade_display:string|null,
 *      grade_abbr:string|null,
 *      grade_lcl:string|null,
 *      date:string,
 *      color:string[],
 *      color_lcl:string[],
 *      common:string[],
 *      common_lcl:string,
 *      day_of_the_week_iso8601:int,
 *      day_of_the_week_long:string,
 *      day_of_the_week_short:string,
 *      liturgical_season:string,
 *      liturgical_season_lcl:string,
 *      liturgical_year:string,
 *      psalter_week:int,
 *      is_vigil_for?:bool,
 *      is_vigil_mass?:bool,
 *      has_vigil_mass?:bool,
 *      has_vesper_i?:bool,
 *      has_vesper_ii?:bool
 * }
 * @phpstan-import-type TestDataObject from TestsMap
 */
class LitTestRunner
{
    /**
     * @var bool Indicates whether the test is ready to run.
     * When the server loads a new test, it will attempt to load the test instructions JSON file and validate it against the schema.
     * If the loading and validation are successful, the readyState is set to true.
     * All subsequent calls to isReady() will return the value of readyState.
     */
    private bool $readyState = false;

    /**
     * @var object{settings:object{year:int,national_calendar?:string,diocesan_calendar?:string},litcal:array<LiturgicalEvent>} The data to be tested
     */
    private object $dataToTest;

    /**
     * @var \stdClass|null The message to be returned by the test
     */
    private ?\stdClass $Message = null;

    /**
     * @var string|null The name of the test
     */
    private ?string $Test = null;

    /**
     * @var TestsMap|null The cache for the test instructions and supported years
     * This is a static property that is shared across all instances of LitTestRunner.
     * It is used to avoid loading the same test instructions multiple times.
     * @static
     */
    private static ?TestsMap $testCache = null;

    /**
     * Initializes the test object with the provided Test and test data.
     * Loads test instructions from a JSON file and validates them against the LitCalTest schema.
     * Populates the test cache with the test instructions and supported years.
     * Updates the ready state based on successful initialization.
     *
     * @param string $Test The name of the test.
     * @param \stdClass&object{settings:object{year:int,national_calendar?:string,diocesan_calendar?:string},litcal:LiturgicalEvent[]} $testData The test data object.
     */
    public function __construct(string $Test, \stdClass $testData)
    {
        $this->Test       = $Test;
        $this->dataToTest = $testData;
        if (self::$testCache === null) {
            self::$testCache = new TestsMap();
        }
        if (false === self::$testCache->has($Test)) {
            $testPath = rtrim(JsonData::TESTS_FOLDER->path(), '/\\') . DIRECTORY_SEPARATOR . basename($Test) . '.json';
            if (file_exists($testPath)) {
                $testInstructionsRaw = file_get_contents($testPath);
                if ($testInstructionsRaw) {
                    $testInstructions = json_decode($testInstructionsRaw);
                    $jsonLastError    = json_last_error();
                    if (JSON_ERROR_NONE === $jsonLastError && $testInstructions instanceof \stdClass) {
                        $schemaFile = rtrim(JsonData::SCHEMAS_FOLDER->path(), '/\\') . DIRECTORY_SEPARATOR . 'LitCalTest.json';
                        try {
                            $schema = Schema::import($schemaFile);
                            $schema->in($testInstructions);
                            /** @var TestDataObject $testInstructions */
                            self::$testCache->add($Test, $testInstructions);
                            $this->readyState = true;
                        } catch (\Throwable $e) {
                            $this->setError("Cannot proceed with {$Test}, the Test instructions were incorrectly validated against schema " . $schemaFile . ': ' . $e->getMessage());
                        }
                    } else {
                        $gettype          = gettype($testInstructions);
                        $jsonErrorMessage = $jsonLastError !== JSON_ERROR_NONE ? ' (' . json_last_error_msg() . ')' : '';
                        $this->setError("Test server could not decode Test instructions JSON data for {$Test}: expected stdClass but got {$gettype}{$jsonErrorMessage}");
                    }
                }
            } else {
                $this->setError("Test server could not read Test instructions for {$Test}");
            }
        } else {
            $this->readyState = self::$testCache->isReady($Test);
        }
    }

    /**
     * Indicates whether the LitTest is ready to run.
     *
     * When the server loads a new test, it will attempt to load the test instructions JSON file and validate it against the schema.
     * If the loading and validation are successful, the readyState is set to true.
     * All subsequent calls to isReady() will return the value of readyState.
     *
     * @return bool true if the test is ready to run, false otherwise
     */
    public function isReady(): bool
    {
        return $this->readyState;
    }

    /**
     * Run the test.
     *
     * If the test is not ready (i.e. has not been loaded and validated), it will
     * do nothing.
     *
     * Otherwise, it will retrieve the assertion for the year we are testing,
     * and check if it is within the bounds of the supported years. If it is,
     * it will run the test according to the assertion type.
     *
     * If the assertion is of type "eventNotExists", it will check if the event
     * does not exist in the calendar. If it does, it will set an error message.
     * Otherwise, it will set a success message.
     *
     * If the assertion is of type "eventExists AND hasExpectedDate", it
     * will check if the event exists in the calendar and has the expected
     * date value. If it does not, it will set an error message. Otherwise, it
     * will set a success message.
     *
     * If the assertion is of any other type, it will set an error message.
     */
    public function runTest(): void
    {
        if ($this->readyState) {
            if (null === self::$testCache) {
                $this->setError('Test cache is not initialized');
                return;
            }
            if (null === $this->Test) {
                $this->setError('Test name is not set');
                return;
            }
            $assertion = self::$testCache->retrieveAssertionForYear($this->Test, $this->dataToTest->settings->year);
            if (is_null($assertion)) {
                $this->setError("Out of bounds error: {$this->Test} only supports calendar years [ " . implode(', ', self::$testCache->getYearsSupported($this->Test)) . ' ]');
                return;
            }

            $calendarType     = $this->getCalendarType();
            $calendarName     = $this->getCalendarName();
            $messageIfError   = "{$this->Test} Assertion '{$assertion->assertion}' failed for Year " . $this->dataToTest->settings->year . " in {$calendarType}{$calendarName}.";
            $eventKey         = self::$testCache->get($this->Test)->event_key;
            $eventBeingTested = array_find($this->dataToTest->litcal, fn ($item) => $item->event_key === $eventKey);

            switch ($assertion->assert) {
                case LitEventTestAssertion::EVENT_NOT_EXISTS:
                    if (null === $eventBeingTested) {
                        $this->setSuccess();
                    } else {
                        $errorMessage = is_null($assertion->expected_value)
                            ? " The event {$eventKey} should not exist, instead the event has a date value of {$eventBeingTested->date}"
                            : " What is going on here? We expected the event not to exist, and in fact it doesn't. We should never get here!";
                        $this->setError($messageIfError . $errorMessage);
                    }
                    break;
                case LitEventTestAssertion::EVENT_EXISTS_AND_HAS_EXPECTED_DATE:
                    $firstErrorMessage = " The event {$eventKey} should exist, instead it was not found";
                    if (null !== $eventBeingTested) {
                        $actualValue        = $eventBeingTested->date;
                        $secondErrorMessage = " The event {$eventKey} was expected to have a date value of {$assertion->expected_value}, instead it had a date value of {$actualValue}";
                        if ($actualValue === $assertion->expected_value) {
                            $this->setSuccess("expected_value = {$assertion->expected_value}, actualValue = {$actualValue}");
                        } else {
                            $this->setError($messageIfError . $secondErrorMessage);
                        }
                    } else {
                        $this->setError($messageIfError . $firstErrorMessage);
                    }
                    break;
                default:
                    $this->setError('This should never happen. We can only test whether an event does not exist, OR (does exist AND has an expected date value)');
                    break;
            }
        }
    }

    /**
     * Get a string to describe the calendar type used in the test (national, diocesan).
     *
     * @return string
     */
    private function getCalendarType(): string
    {
        return property_exists($this->dataToTest->settings, 'national_calendar') ? 'the national calendar of ' : (
            property_exists($this->dataToTest->settings, 'diocesan_calendar') ? 'the diocesan calendar of ' : ''
        );
    }

    /**
     * Returns the name of the calendar used in the test,
     * which will be a diocesan calendar, a national calendar, or 'the Universal Roman Calendar'.
     * @return string
     */
    private function getCalendarName(): string
    {
        return property_exists($this->dataToTest->settings, 'diocesan_calendar') ? $this->dataToTest->settings->diocesan_calendar : (
            property_exists($this->dataToTest->settings, 'national_calendar') ? $this->dataToTest->settings->national_calendar : 'the Universal Roman Calendar'
        );
    }

    /**
     * Sets the message details based on the provided type and optional text. Called by {@see \LiturgicalCalendar\Api\Test\LitTestRunner::setError()} and {@see \LiturgicalCalendar\Api\Test\LitTestRunner::setSuccess()}.
     *
     * @param string $type The type of the message ('success' or 'error').
     * @param string|null $text The optional text to include in the message.
     */
    private function setMessage(string $type, ?string $text = null): void
    {
        $this->Message          = new \stdClass();
        $this->Message->type    = $type;
        $this->Message->classes = ".$this->Test.year-{$this->dataToTest->settings->year}.test-valid";
        $this->Message->test    = $this->Test;
        if ($type === 'success') {
            if (is_null($text)) {
                $this->Message->text = "$this->Test passed for the Calendar {$this->getCalendarName()} for the year {$this->dataToTest->settings->year}";
            } else {
                $this->Message->text = "$this->Test passed for the Calendar {$this->getCalendarName()} for the year {$this->dataToTest->settings->year}: " . $text;
            }
        } else {
            $this->Message->text     = $text;
            $this->Message->jsonData = $this->dataToTest;
        }
    }

    /**
     * Sets the message to be an error message with the provided text. Called in
     * {@see \LiturgicalCalendar\Api\Test\LitTestRunner::__construct()}
     * and in {@see \LiturgicalCalendar\Api\Test\LitTestRunner::runTest()}
     * and in {@see \LiturgicalCalendar\Api\Test\LitTestRunner::getMessage()}.
     *
     * @param string $text The text of the error message.
     */
    private function setError(string $text): void
    {
        $this->setMessage('error', $text);
    }

    /**
     * Sets the message to be a success message with the provided text. Called in {@see \LiturgicalCalendar\Api\Test\LitTestRunner::runTest()}.
     *
     * @param string|null $text The optional text to include in the message.
     */
    private function setSuccess(?string $text = null): void
    {
        $this->setMessage('success', $text);
    }

    /**
     * Gets the message for the test result.
     * If the test has not been run yet, sets the message to an error message
     * and returns it.
     *
     * @return \stdClass The message object.
     */
    public function getMessage(): \stdClass
    {
        if (is_null($this->Message)) {
            $this->setError('An unknown error occurred while trying to run the test');
        }
        $message = $this->Message;
        if (null === $message) {
            throw new \RuntimeException('An unknown error occurred while trying to run the test: an error message should have been set but apparently it was not?');
        }
        return $message;
    }
}
