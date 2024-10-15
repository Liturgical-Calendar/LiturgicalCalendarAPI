<?php

namespace LiturgicalCalendar\Api;

use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;

/**
 * Class LitTest
 * @package LiturgicalCalendar\Api
 *
 * @property private bool $readyState              Whether the test is ready to be run
 * @property private ?object $testInstructions     The JSON instructions for the test
 * @property private ?object $dataToTest           The data to be tested
 * @property private ?object $Message              The message returned by the test
 * @property private ?string $Test                 The name of the test
 * @property private ?object $testCache            The test cache
 *
 * @method void __construct(string $Test, object $dataToTest)               Initializes the test object with the provided Test and test data.
 * @method bool isReady()                                                   Returns whether the test is ready to be run
 * @method void runTest()                                                   Runs the test
 * @method private string getCalendarType()                                 Returns the type of the calendar
 * @method private string getCalendarName()                                 Returns the name of the calendar
 * @method private void setMessage(string $type, ?string $message)          Sets the message for the test results, whether of type success or error
 * @method private void setError(string $message)                           Sets the error message
 * @method private void setSuccess(?string $message)                        Sets the success message
 * @method object getMessage()                                              Returns the message returned by the test
 * @method private ?object retrieveAssertionForYear(int $year)              Retrieves the assertion for the given year
 * @method private array detectYearsSupported()                             Detects the years supported by the test
 */
class LitTest
{
    private bool $readyState            = false;
    private ?object $testInstructions   = null;
    private ?object $dataToTest         = null;
    private ?object $Message            = null;
    private ?string $Test               = null;

    private static ?object $testCache   = null;

    /**
     * Initializes the test object with the provided Test and test data.
     * Loads test instructions from a JSON file and validates them against the LitCalTest schema.
     * Populates the test cache with the test instructions and supported years.
     * Updates the ready state based on successful initialization.
     *
     * @param string $Test The name of the test.
     * @param object $testData The test data object.
     */
    public function __construct(string $Test, object $testData)
    {
        $this->Test = $Test;
        $this->dataToTest = $testData;
        if (self::$testCache === null) {
            self::$testCache = new \stdClass();
        }
        if (false === property_exists(self::$testCache, $Test)) {
            $testPath = "tests/{$Test}.json";
            if (file_exists($testPath)) {
                $testInstructions = file_get_contents($testPath);
                if ($testInstructions) {
                    $this->testInstructions = json_decode($testInstructions);
                    if (JSON_ERROR_NONE === json_last_error()) {
                        $schemaFile = 'schemas/LitCalTest.json';
                        $schemaContents = file_get_contents($schemaFile);
                        $jsonSchema = json_decode($schemaContents);
                        try {
                            $schema = Schema::import($jsonSchema);
                            $schema->in($this->testInstructions);
                            self::$testCache->{$Test} = new \stdClass();
                            self::$testCache->{$Test}->testInstructions = $this->testInstructions;
                            self::$testCache->{$Test}->yearsSupported = $this->detectYearsSupported();
                            $this->readyState = true;
                        } catch (InvalidValue | \Exception $e) {
                            $this->setError("Cannot proceed with {$Test}, the Test instructions were incorrectly validated against schema " . $schemaFile . ": " . $e->getMessage());
                        }
                    } else {
                        $this->setError("Test server could not decode Test instructions JSON data for {$Test}");
                    }
                }
            } else {
                $this->setError("Test server could not read Test instructions for {$Test}");
            }
        } else {
            $this->readyState = (
                property_exists(self::$testCache->{$Test}, 'testInstructions')
                &&
                property_exists(self::$testCache->{$Test}, 'yearsSupported')
            );
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
     * If the assertion is of type "eventExists AND hasExpectedTimestamp", it
     * will check if the event exists in the calendar and has the expected
     * timestamp. If it does not, it will set an error message. Otherwise, it
     * will set a success message.
     *
     * If the assertion is of any other type, it will set an error message.
     */
    public function runTest(): void
    {
        if ($this->readyState) {
            $assertion = $this->retrieveAssertionForYear($this->dataToTest->settings->year);
            if (is_null($assertion)) {
                $this->setError("Out of bounds error: {$this->Test} only supports calendar years [ " . implode(', ', self::$testCache->{$this->Test}->yearsSupported) . " ]");
                return;
            }

            $calendarType = $this->getCalendarType();
            $calendarName = $this->getCalendarName();
            $messageIfError = "{$this->Test} Assertion '{$assertion->assertion}' failed for Year " . $this->dataToTest->settings->year . " in {$calendarType}{$calendarName}.";
            $eventKey = self::$testCache->{$this->Test}->testInstructions->event_key;

            switch ($assertion->assert) {
                case 'eventNotExists':
                    $errorMessage = is_null($assertion->expected_value)
                        ? " The event {$eventKey} should not exist, instead the event has a timestamp of {$this->dataToTest->litcal->{$eventKey}->date}"
                        : " What is going on here? We expected the event not to exist, and in fact it doesn't. We should never get here!";

                    if (false === property_exists($this->dataToTest->litcal, $eventKey)) {
                        $this->setSuccess();
                    } else {
                        $this->setError($messageIfError . $errorMessage);
                    }
                    break;
                case 'eventExists AND hasExpectedTimestamp':
                    $firstErrorMessage = " The event {$eventKey} should exist, instead it was not found";
                    if (property_exists($this->dataToTest->litcal, $eventKey)) {
                        $actualValue = $this->dataToTest->litcal->{$eventKey}->date;
                        $secondErrorMessage = " The event {$eventKey} was expected to have timestamp {$assertion->expected_value}, instead it had timestamp {$actualValue}";
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
                    $this->setError('This should never happen. We can only test whether an event does not exist, OR (does exist AND has an expected timestamp)');
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
     * Sets the message details based on the provided type and optional text. Called by {@see setError()} and {@see setSuccess()}.
     *
     * @param string $type The type of the message ('success' or 'error').
     * @param string|null $text The optional text to include in the message.
     */
    private function setMessage(string $type, ?string $text = null): void
    {
        $this->Message = new \stdClass();
        $this->Message->type = $type;
        $this->Message->classes = ".$this->Test.year-{$this->dataToTest->settings->year}.test-valid";
        $this->Message->test = $this->Test;
        if ($type === 'success') {
            if (is_null($text)) {
                $this->Message->text = "$this->Test passed for the Calendar {$this->getCalendarName()} for the year {$this->dataToTest->settings->year}";
            } else {
                $this->Message->text = "$this->Test passed for the Calendar {$this->getCalendarName()} for the year {$this->dataToTest->settings->year}: " . $text;
            }
        } else {
            $this->Message->text = $text;
            $this->Message->jsonData = $this->dataToTest;
        }
    }

    /**
     * Sets the message to be an error message with the provided text. Called in {@see __construct()} and in {@see runTest()}.
     *
     * @param string $text The text of the error message.
     */
    private function setError(string $text): void
    {
        $this->setMessage('error', $text);
    }

    /**
     * Sets the message to be a success message with the provided text. Called in {@see runTest()}.
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
     * @return object The message object.
     */
    public function getMessage(): object
    {
        if (is_null($this->Message)) {
            $this->setError('An unknown error occurred while trying to run the test');
        }
        return $this->Message;
    }

    /**
     * Retrieves the assertion for a given year, if it exists. Called in {@see runTest()}.
     *
     * @param int $year The year for which to retrieve the assertion.
     * @return object|null The assertion, or null if no assertion exists for the given year.
     */
    private function retrieveAssertionForYear(int $year): ?object
    {
        $assertions = self::$testCache->{$this->Test}->testInstructions->assertions;
        foreach ($assertions as $assertion) {
            if ($assertion->year === $year) {
                return $assertion;
            }
        }
        return null;
    }

    /**
     * Retrieves an array of all years for which there are assertions. Called in {@see __construct()}.
     *
     * @return array The years for which there are assertions.
     */
    private function detectYearsSupported(): array
    {
        $years = [];
        foreach ($this->testInstructions->assertions as $assertion) {
            $years[] = $assertion->year;
        }
        return $years;
    }
}
