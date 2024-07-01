<?php

namespace Johnrdorazio\LitCal;

use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;

class LitTest
{
    private bool $readyState            = false;
    private ?object $testInstructions   = null;
    private ?object $dataToTest         = null;
    private ?object $Message            = null;
    private ?string $Test               = null;

    private static ?object $testCache   = null;

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

    public function isReady(): bool
    {
        return $this->readyState;
    }

    public function runTest(): void
    {
        if ($this->readyState) {
            $assertion = $this->retrieveAssertionForYear($this->dataToTest->settings->year);
            if (is_null($assertion)) {
                $this->setError("Out of bounds error: {$this->Test} only supports calendar years [ " . implode(', ', self::$testCache->{$this->Test}->yearsSupported) . " ]");
                return;
            }

            $calendarType = $this->getCalendarTypeStr();
            $calendarName = $this->getCalendarName();
            $messageIfError = "{$this->Test} Assertion '{$assertion->assertion}' failed for Year " . $this->dataToTest->settings->year . " in {$calendarType}{$calendarName}.";
            $eventKey = self::$testCache->{$this->Test}->testInstructions->eventkey;

            switch ($assertion->assert) {
                case 'eventNotExists':
                    $errorMessage = is_null($assertion->expected_value)
                        ? " The event {$eventKey} should not exist, instead the event has a timestamp of {$this->dataToTest->LitCal->{$eventKey}->date}"
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

    private function getCalendarTypeStr(): string
    {
        return property_exists($this->dataToTest->settings, 'nationalcalendar') ? 'the national calendar of ' : (
            property_exists($this->dataToTest->settings, 'diocesancalendar') ? 'the diocesan calendar of ' : ''
        );
    }

    private function getCalendarName(): string
    {
        return property_exists($this->dataToTest->settings, 'diocesancalendar') ? $this->dataToTest->settings->diocesancalendar : (
            property_exists($this->dataToTest->settings, 'nationalcalendar') ? $this->dataToTest->settings->nationalcalendar : 'the Universal Roman Calendar'
        );
    }

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

    private function setError(string $text): void
    {
        $this->setMessage('error', $text);
    }

    private function setSuccess(?string $text = null): void
    {
        $this->setMessage('success', $text);
    }

    public function getMessage(): object
    {
        if (is_null($this->Message)) {
            $this->setError('An unknown error occurred while trying to run the test');
        }
        return $this->Message;
    }

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

    private function detectYearsSupported(): array
    {
        $years = [];
        foreach ($this->testInstructions->assertions as $assertion) {
            $years[] = $assertion->year;
        }
        return $years;
    }
}
