<?php
include_once( 'vendor/autoload.php' );
use PHPUnit\Framework\TestCase;

//In the year 2022, the Solemnity Nativity of John the Baptist coincides with the Solemnity of the Sacred Heart
//Nativity of John the Baptist anticipated by one day to June 23
//( except in cases where John the Baptist is patron of a nation, diocese, city or religious community, then the Sacred Heart can be anticipated by one day to June 23 )
//http://www.cultodivino.va/content/cultodivino/it/documenti/responsa-ad-dubia/2020/de-calendario-liturgico-2022.html
//This will happen again in 2033 and 2044
class NativityJohnBaptistTest extends TestCase {
    public static object $testObject;
    public static array $expectedValues = [
        2022 => 1655942400,
        2033 => 2003097600,
        2044 => 2350252800
    ];

    public function testJune23() : bool|object {
        $res = false;
        $expectedValue = self::$expectedValues[ self::$testObject->Settings->Year ];
        try {
            $this->assertSame( $expectedValue, self::$testObject->LitCal->NativityJohnBaptist->date );
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
            $message->text = "Nativity of John the Baptist test failed for Year " . self::$testObject->Settings->Year . " in {$type}{$Calendar}. Expected value: {$expectedValue}, actual value: " . self::$testObject->LitCal->NativityJohnBaptist->date . PHP_EOL . $e->getMessage();
            return $message;
        }
        return $res;
    }
}
