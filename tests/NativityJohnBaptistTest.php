<?php

include_once('vendor/autoload.php');
use PHPUnit\Framework\TestCase;

//In the year 2022, the Solemnity Nativity of John the Baptist coincides with the Solemnity of the Sacred Heart
//Nativity of John the Baptist anticipated by one day to June 23
//( except in cases where John the Baptist is patron of a nation, diocese, city or religious community, then the Sacred Heart can be anticipated by one day to June 23 )
//http://www.cultodivino.va/content/cultodivino/it/documenti/responsa-ad-dubia/2020/de-calendario-liturgico-2022.html
//This will happen again in 2033 and 2044
class NativityJohnBaptistTest extends TestCase
{
    const DESCRIPTION = "When the Nativity of John the Baptist coincides with the Solemnity of the Sacred Heart, is it correctly moved to June 23?";
    const TEST_TYPE = "exactCorrespondence"; //exactCorrespondence = test will only be performed against a year if included in ExpectedValues

    const ExpectedValues = [
        2022 => 1655942400,
        2033 => 2003097600,
        2044 => 2350252800
    ];

    const Assertions = [
        2022 => "Nativity of John the Baptist should be moved to expected date",
        2033 => "Nativity of John the Baptist should be moved to expected date",
        2044 => "Nativity of John the Baptist should be moved to expected date"
    ];

    public static object $testObject;

    public function test(): bool|object
    {
        $res = false;
        if (array_key_exists(self::$testObject->Settings->Year, self::ExpectedValues)) {
            $expectedValue = self::ExpectedValues[ self::$testObject->Settings->Year ];
            $actualValue =  self::$testObject->LitCal->NativityJohnBaptist->date;
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
                $message->text = get_class($this) . " Assertion '{$assertion}' failed for Year " . self::$testObject->Settings->Year . " in {$type}{$Calendar}. Expected value: {$expectedValue}, actual value: $actualValue" . PHP_EOL . $e->getMessage();
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
