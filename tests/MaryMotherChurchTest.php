<?php

include_once('vendor/autoload.php');
use PHPUnit\Framework\TestCase;

/**
 * The memorial Mary Mother of the Church was added to the calendar in (2018)
 */
class MaryMotherChurchTest extends TestCase
{
    const DESCRIPTION = "The memorial 'Mary Mother of the Church' was added in 2018 by Decree of the Congregation for Divine Worship";
    const TEST_TYPE = "exactCorrespondenceSince"; //exactCorrespondenceSince = before the first year in ExpectedValues keys, a test for non existence will be performed; from such year test will be performed against corresponding value in ExpectedValues

    const ExpectedValues = [
        2018 => 1526860800,
        2019 => 1560124800
    ];

    const Assertions = [
        "before" => "The memorial 'Mary Mother of the Church' should not exist before the year 2018",
        2018 => "The memorial 'Mary Mother of the Church' should be created on the expected date",
        2019 => "The memorial 'Mary Mother of the Church' should be created on the expected date"
    ];

    public static object $testObject;

    public function test(): bool|object
    {
        $res = false;
        $sinceYear = array_keys(self::ExpectedValues)[0];
        if (self::$testObject->Settings->Year < $sinceYear) {
            $assertion = self::Assertions["before"];
            try {
                $phpUnitVersion = \PHPUnit\Runner\Version::id();
                if (version_compare($phpUnitVersion, '10.1', '>=')) {
                    $this->assertObjectNotHasProperty('MaryMotherChurch', self::$testObject->LitCal);
                } else {
                    //$this->assertObjectNotHasAttribute( 'MaryMotherChurch', self::$testObject->LitCal );
                    $this->assertFalse(property_exists(self::$testObject->LitCal, 'MaryMotherChurch'));
                }
                $res = true;
            } catch (Exception $e) {
                $message = new stdClass();
                $type = property_exists(self::$testObject->Settings, 'NationalCalendar') ? 'the national calendar of ' : (
                    property_exists(self::$testObject->Settings, 'DiocesanCalendar') ? 'the diocesan calendar of ' : ''
                );
                $Calendar = property_exists(self::$testObject->Settings, 'NationalCalendar') ? self::$testObject->Settings->NationalCalendar : (
                    property_exists(self::$testObject->Settings, 'DiocesanCalendar') ? self::$testObject->Settings->DiocesanCalendar : 'the Universal Roman Calendar'
                );
                $message->type = "error";
                $message->text = get_class($this) . " Assertion '{$assertion}' failed for Year " . self::$testObject->Settings->Year . " in {$type}{$Calendar}." . PHP_EOL . $e->getMessage();
                return $message;
            }
        } elseif (array_key_exists(self::$testObject->Settings->Year, self::ExpectedValues)) {
            $expectedValue = self::ExpectedValues[ self::$testObject->Settings->Year ];
            $actualValue = self::$testObject->LitCal->MaryMotherChurch->date;
            $assertion = self::Assertions[ self::$testObject->Settings->Year ];
            try {
                $this->assertSame($expectedValue, $actualValue);
                $res = true;
            } catch (Exception $e) {
                $message = new stdClass();
                $type = property_exists(self::$testObject->Settings, 'NationalCalendar') ? 'the national calendar of ' : (
                    property_exists(self::$testObject->Settings, 'DiocesanCalendar') ? 'the diocesan calendar of ' : ''
                );
                $Calendar = property_exists(self::$testObject->Settings, 'NationalCalendar') ? self::$testObject->Settings->NationalCalendar : (
                    property_exists(self::$testObject->Settings, 'DiocesanCalendar') ? self::$testObject->Settings->DiocesanCalendar : 'the Universal Roman Calendar'
                );
                $message->type = "error";
                $message->text = get_class($this) . " Assertion '{$assertion}' failed for Year " . self::$testObject->Settings->Year . " in {$type}{$Calendar}. Expected value: {$expectedValue}, actual value: {$actualValue}" . PHP_EOL . $e->getMessage();
                return $message;
            }
        } else {
            $message = new stdClass();
            $message->type = "error";
            $message->text = get_class($this) . " out of bounds: this test only supports calendar years [ " . implode(', ', array_keys(self::ExpectedValues)) . " ]";
            return $message;
        }
        return $res;
    }
}
