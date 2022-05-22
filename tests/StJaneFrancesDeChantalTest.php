<?php
include_once( 'vendor/autoload.php' );
use PHPUnit\Framework\TestCase;

/**
 * In the Tertia Editio Typica (2002),
 * Saint Jane Frances de Chantal was moved from December 12 to August 12,
 * probably to allow local bishop's conferences to insert Our Lady of Guadalupe as an optional memorial on December 12
 * seeing that with the decree of March 25th 1999 of the Congregation of Divine Worship
 * Our Lady of Guadalupe was granted as a Feast day for all dioceses and territories of the Americas
 * source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_lt.html
 */
class StJaneFrancesDeChantalTest extends TestCase {
    public static object $testObject;
    public static array $expectedValues = [
        2001 => 1008115200,
        2002 => 1029110400,
        2010 => 1281571200 //this test is significant since December 12th falls on a Sunday, so the memorial would have been suppressed if it hadn't been moved
    ];

    //Years from 2002 in which August 12th is a Sunday
    public static array $overriddenSince2002 = [
        2012,
        2018,
        2029,
        2035,
        2040,
        2046
    ];

    //Years before 2002 in which December 12th is a Sunday
    public static array $overriddenBefore2002 = [
        1971,
        1976,
        1982,
        1993,
        1999
    ];


    public function testMovedOrNot() : bool|object {
        $res = false;
        $expectedValue = self::$expectedValues[ self::$testObject->Settings->Year ];
        try {
            $this->assertSame( $expectedValue, self::$testObject->LitCal->StJaneFrancesDeChantal->date );
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
            $message->text = "Saint Jane Frances de Chantal test (moved or not) failed for Year " . self::$testObject->Settings->Year . " in {$type}{$Calendar}. Expected value: {$expectedValue}, actual value: " . self::$testObject->LitCal->NativityJohnBaptist->date . PHP_EOL . $e->getMessage();
            return $message;
        }
        return $res;
    }

    public function testOverridden() : bool|object {
        $res = false;
        if( in_array( self::$testObject->Settings->Year, self::$overriddenSince2002 ) || in_array( self::$testObject->Settings->Year, self::$overriddenBefore2002 ) ) {
            try {
                $this->assertObjectNotHasAttribute( 'StJaneFrancesDeChantal', self::$testObject->LitCal );
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
                $message->text = "Saint Jane Frances de Chantal test (overriden) failed for Year " . self::$testObject->Settings->Year . " in {$type}{$Calendar}. The memorial should have been overridden since it falls on a Sunday!" . PHP_EOL . $e->getMessage();
                return $message;
            }
        }
        return $res;
    }
}
