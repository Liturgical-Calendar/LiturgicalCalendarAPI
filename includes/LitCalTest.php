<?php
include_once( 'vendor/autoload.php' );

use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;

class LitCalTest
{
    private bool $readyState            = false;
    private ?object $testInstructions   = null;
    private ?object $dataToTest         = null;
    private ?object $errorMessage       = null;
    private ?string $Test               = null;

    private static ?object $testCache   = null;

    public function __construct( string $Test, object $testData )
    {
        $this->Test = $Test;
        $this->dataToTest = $testData;
        if( self::$testCache === null ) {
            self::$testCache = new stdClass;
        }
        if( false === property_exists( self::$testCache, $Test ) ) {
            $testPath = "tests/{$Test}.json";
            if( file_exists( $testPath ) ) {
                $testInstructions = file_get_contents( $testPath );
                if( $testInstructions ) {
                    $this->testInstructions = json_decode( $testInstructions );
                    if( JSON_ERROR_NONE === json_last_error() ) {
                        $schemaFile = 'schemas/LitCalTest.json';
                        $schemaContents = file_get_contents( $schemaFile );
                        $jsonSchema = json_decode( $schemaContents );
                        try {
                            $schema = Schema::import( $jsonSchema );
                            $schema->in($this->testInstructions);
                            self::$testCache->{$Test} = new stdClass;
                            self::$testCache->{$Test}->testInstructions     = $this->testInstructions;
                            self::$testCache->{$this->Test}->yearsSupported = $this->detectYearsSupported();
                            $this->readyState = true;
                        } catch (InvalidValue|Exception $e) {
                            $this->setError( "Cannot proceed with {$Test}, the Test instructions were incorrectly validated against schema " . $schemaFile . ": " . $e->getMessage() );
                        }
                    } else {
                        $this->setError( "Test server could not decode Test instructions JSON data for {$Test}" );
                    }
                }
            } else {
                $this->setError( "Test server could not read Test instructions for {$Test}" );
            }
        } else {
            $this->readyState = (
                property_exists( self::$testCache->{$Test}, 'testInstructions' )
                &&
                property_exists( self::$testCache->{$Test}, 'yearsSupported' )
            );
        }
    }

    public function isReady(): bool {
        return $this->readyState;
    }

    public function runTest(): true|object {

        if( $this->readyState ) {

            $assertion = $this->retrieveAssertionForYear( $this->dataToTest->Settings->Year );
            if( is_null( $assertion ) ) {
                $this->setError( "Out of bounds error: {$this->Test} only supports calendar years [ " . implode(', ', self::$testCache->{$this->Test}->yearsSupported ) . " ]" );
                return $this->getError();
            }

            $calendarType = $this->getCalendarTypeStr();
            $calendarName = $this->getCalendarName();
            $messageIfError = "{$this->Test} Assertion '{$assertion->assertion}' failed for Year " . $this->dataToTest->Settings->Year . " in {$calendarType}{$calendarName}.";
            $eventKey = self::$testCache->{$this->Test}->testInstructions->eventkey;

            switch( $assertion->assert ) {
                case 'eventNotExists':
                    $errorMessage = is_null( $assertion->expectedValue )
                        ? " The event {$eventKey} should not exist, instead the event has a timestamp of {$this->dataToTest->LitCal->{$eventKey}->date}"
                        : " What is going on here? We expected the event not to exist, and in fact it doesn't. We should never get here!";

                    $rule = ( false === property_exists( $this->dataToTest->LitCal, $eventKey ) );
                    try {
                        $res = assert( $rule, $messageIfError . $errorMessage );
                    } catch (AssertionError $e) {
                        $this->setError( $e->getMessage() );
                        $res = $this->getError();
                    }
                    return $res;
                case 'eventExists AND hasExpectedTimestamp':
                    $firstErrorMessage = " The event {$eventKey} should exist, instead it was not found";
                    $rule = property_exists( $this->dataToTest->LitCal, $eventKey );
                    try {
                        $res = assert( $rule, $messageIfError . $firstErrorMessage );
                    } catch (AssertionError $e) {
                        $this->setError( $e->getMessage() );
                        $res = $this->getError();
                    }
                    if( true === $res ) {
                        $actualValue = $this->dataToTest->LitCal->{$eventKey}->date;
                        $secondErrorMessage = " The event {$eventKey} was expected to have timestamp {$assertion->expectedValue}, instead it had timestamp {$actualValue}";
                        try {
                            $res = assert( $actualValue === $assertion->expectedValue, $messageIfError . $secondErrorMessage );
                        } catch (AssertionError $e) {
                            $this->setError( $e->getMessage() );
                            $res = $this->getError();
                        }
                    }
                    return $res;
                default:
                    $this->setError( 'This should never happen. We can only test whether an event does not exist, OR (does exist AND has an expected timestamp)' );
                    return $this->getError();
            }
        } else {
            if( is_null( $this->errorMessage ) ) {
                $this->setError( 'An unknown error occurred while trying to run the test' );
            }
            return $this->getError();
        }
    }

    private function getCalendarTypeStr(): string {
        return property_exists( $this->dataToTest->Settings, 'NationalCalendar' ) ? 'the national calendar of ' : (
            property_exists( $this->dataToTest->Settings, 'DiocesanCalendar' ) ? 'the diocesan calendar of ' : ''
        );
    }

    private function getCalendarName(): string {
        return property_exists( $this->dataToTest->Settings, 'NationalCalendar' ) ? $this->dataToTest->Settings->NationalCalendar : (
            property_exists( $this->dataToTest->Settings, 'DiocesanCalendar' ) ? $this->dataToTest->Settings->DiocesanCalendar : 'the Universal Roman Calendar'
        );
    }

    private function setError( string $text ): void {
        $this->errorMessage = new stdClass;
        $this->errorMessage->type = "error";
        $this->errorMessage->text = $text;
    }

    public function getError(): ?object {
        return $this->errorMessage;
    }

    private function retrieveAssertionForYear( int $year ): ?object {
        $assertions = self::$testCache->{$this->Test}->testInstructions->assertions;
        foreach( $assertions as $assertion ) {
            if( $assertion->year === $year ) {
                return $assertion;
            }
        }
        return null;
    }

    private function detectYearsSupported(): array {
        $years = [];
        foreach( $this->testInstructions->assertions as $assertion ) {
            $years[] = $assertion->year;
        }
        return $years;
    }

}
