<?php
include_once( 'vendor/autoload.php' );
use PHPUnit\Framework\TestCase;

/**
 * The memorial Mary Mother of the Church was added to the calendar in (2018)
 */
class MaryMotherChurchTest extends TestCase {
    public static object $testObject;
    public static array $expectedValues = [
        2018 => 1526860800,
        2019 => 1560124800
    ];


    public function testDoesNotExist() : bool|object {
        $res = false;
        if( self::$testObject->Settings->Year < 2018 ) {
            try {
                $this->assertObjectNotHasAttribute( 'MaryMotherChurch', self::$testObject->LitCal );
                $res = true;
            } catch(Exception $e) {
                $message = new stdClass();
                $type = property_exists( self::$testObject->Settings, 'NationalCalendar' ) ? 'the national calendar of ' : (
                    property_exists( self::$testObject->Settings, 'DiocesanCalendar' ) ? 'the diocesan calendar of ' : ''
                );
                $Calendar = property_exists( self::$testObject->Settings, 'NationalCalendar' ) ? self::$testObject->Settings->NationalCalendar : (
                    property_exists( self::$testObject->Settings, 'DiocesanCalendar' ) ? self::$testObject->Settings->DiocesanCalendar : 'the Universal Roman Calendar'
                );
                $message->type = "error";
                $message->text = "Assertion 'Memorial Mary Mother of the Church is not created' failed for Year " . self::$testObject->Settings->Year . " in {$type}{$Calendar}. The memorial should not exist before the year 2018!" . PHP_EOL . $e->getMessage();
                return $message;
            }
        }
        return $res;
    }

    public function testExists() : bool|object {
        $res = false;
        $expectedValue = self::$expectedValues[ self::$testObject->Settings->Year ];
        try {
            $this->assertSame( $expectedValue, self::$testObject->LitCal->MaryMotherChurch->date );
            $res = true;
        } catch (Exception $e) {
            $message = new stdClass();
            $type = property_exists( self::$testObject->Settings, 'NationalCalendar' ) ? 'the national calendar of ' : (
                property_exists( self::$testObject->Settings, 'DiocesanCalendar' ) ? 'the diocesan calendar of ' : ''
            );
            $Calendar = property_exists( self::$testObject->Settings, 'NationalCalendar' ) ? self::$testObject->Settings->NationalCalendar : (
                property_exists( self::$testObject->Settings, 'DiocesanCalendar' ) ? self::$testObject->Settings->DiocesanCalendar : 'the Universal Roman Calendar'
            );
            $message->type = "error";
            $message->text = "Assertion 'Memorial Mary Mother of the Church exists on expected day' failed for Year " . self::$testObject->Settings->Year . " in {$type}{$Calendar}. Expected value: {$expectedValue}, actual value: " . self::$testObject->LitCal->MaryMotherChurch->date . PHP_EOL . $e->getMessage();
            return $message;
        }
        return $res;
    }
}
