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
    const DESCRIPTION = "Saint Jane Frances de Chantal was moved from December 12 to August 12 in the 2002 Latin Edition of the Roman Missal, to allow bishops conferences to insert Our Lady of Guadalupe as an optional memorial on December 12. DOES ST JANE FRANCES DE CHANTAL FALL ON THE RIGHT DAY BOTH BEFORE AND AFTER 2002, OR IT CORRECTLY OVERRIDEN BY GREATER FESTIVITIES?";
    const TEST_TYPE = "variableCorrespondence"; //variableCorrespondence = test will be performed differently according to which year it's being tested against
    const ExpectedValues = [
        2001 => 1008115200,
        2002 => 1029110400,
        2010 => 1281571200 //this test is significant since December 12th falls on a Sunday, so the memorial would have been suppressed if it hadn't been moved
    ];

    const Assertions = [
        1971 => "should not exist, December 12th is a Sunday",
        1976 => "should not exist, December 12th is a Sunday",
        1982 => "should not exist, December 12th is a Sunday",
        1993 => "should not exist, December 12th is a Sunday",
        1999 => "should not exist, December 12th is a Sunday",
        2001 => "should fall on expected date",
        2002 => "should fall on expected date",
        2010 => "should fall on expected date",
        2012 => "should not exist, August 12th is a Sunday",
        2018 => "should not exist, August 12th is a Sunday",
        2029 => "should not exist, August 12th is a Sunday",
        2035 => "should not exist, August 12th is a Sunday",
        2040 => "should not exist, August 12th is a Sunday",
        2046 => "should not exist, August 12th is a Sunday"
    ];

    public static object $testObject;

    public function test() : bool|object {
        $res = false;
        if( array_key_exists( self::$testObject->Settings->Year, self::ExpectedValues ) ) {
            $expectedValue = self::ExpectedValues[ self::$testObject->Settings->Year ];
            $actualValue = self::$testObject->LitCal->StJaneFrancesDeChantal->date;
            $assertion = self::Assertions[ self::$testObject->Settings->Year ];
            try {
                $this->assertSame( $expectedValue, $actualValue );
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
                $message->text =  get_class($this) . " Assertion '{$assertion}' failed for Year " . self::$testObject->Settings->Year . " in {$type}{$Calendar}. Expected value: {$expectedValue}, actual value: $actualValue" . PHP_EOL . $e->getMessage();
                return $message;
            }
        } else if( array_key_exists( self::$testObject->Settings->Year, self::Assertions ) ) {
            $assertion = self::Assertions[ self::$testObject->Settings->Year ];
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
                $message->text = get_class($this) . " Assertion '{$assertion}' failed for Year " . self::$testObject->Settings->Year . " in {$type}{$Calendar}." . PHP_EOL . $e->getMessage();
                return $message;
            }
        }
         else {
            $message = new stdClass();
            $message->type = "error";
            $message->text = get_class($this) . " out of bounds: this test only supports calendar years [ " . implode(', ', array_keys(self::Assertions) ) . " ]";
            return $message;
        }
        return $res;
    }

}
