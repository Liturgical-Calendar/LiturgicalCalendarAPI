<?php

include_once( 'includes/LitDateTime.php' );

/**
 * Useful functions for LitCalEngine.php
 * @Author: John Romano D'Orazio
 * @Date:   2017-2018
 */

ini_set('date.timezone', 'Europe/Vatican');

/**
 *  THE ENTIRE LITURGICAL CALENDAR DEPENDS MAINLY ON THE DATE OF EASTER
 *  THE FOLLOWING LITCALFUNCTIONS.PHP DEFINES AMONG OTHER THINGS THE FUNCTION
 *  FOR CALCULATING GREGORIAN EASTER FOR A GIVEN YEAR AS USED BY THE LATIN RITE
 */


class LitFunc {

  const NON_EVENT_KEYS = [ 'LitCal', 'Settings', 'Messages', 'Metadata', 'Solemnities', 'FeastsMemorials', 'RequestHeaders', 'color', 'colorLcl', 'common' ];
  private static string $LAST_ARRAY_KEY = '';
  public static string $HASH_REQUEST    = '';

  public static function isNotLitCalEventKey( string $key ) : bool {
    return in_array( $key, self::NON_EVENT_KEYS );
  }

  public static function convertArray2XML(array $data, ?SimpleXMLElement &$xml) : void {
    foreach( $data as $key => $value ) {
      if( is_array( $value ) ) {
        self::$LAST_ARRAY_KEY = $key;
        self::debugWrite( "value of key <$key> is an array" );
        if( self::isNotLitCalEventKey( $key ) ) {
          self::debugWrite( "key <$key> is not a LitCalEvent" );
          $new_object = $xml->addChild( $key );
        } else {
          self::debugWrite( "key <$key> is a LitCalEvent" );
          $new_object = $xml->addChild( "LitCalEvent" );
          $new_object->addAttribute( "eventkey", $key );
        }
        self::debugWrite( "proceeding to convert array value of <$key> to xml sequence..." );
        self::convertArray2XML( $value, $new_object );
      } else {
        // XML elements cannot have numerical names, they must have text
        if( is_numeric( $key ) ) {
          self::debugWrite( "key <$key> is numerical, have to deal with this..." );
          if( self::$LAST_ARRAY_KEY === 'Messages' ) {
            self::debugWrite( "key <$key> seems to belong to the Messages array: will create a corresponding <message> element with attribute 'msgid'" );
            $el = $xml->addChild( 'message', htmlspecialchars( $value ) );
            $el->addAttribute( "msgid", $key );
          } else {
            $attribute = self::$LAST_ARRAY_KEY . "id";
            self::debugWrite( "key <$key> does not seem to belong to the Messages array: will create a corresponding <option> element with attribute '$attribute'" );
            $el = $xml->addChild( 'option', $value );
            $el->addAttribute( $attribute, $key );
          }
        }
      }
    }
  }

  // https://en.wikipedia.org/wiki/Computus#Anonymous_Gregorian_algorithm
  // aka Meeus/Jones/Butcher algorithm
  public static function calcGregEaster($Y) : DateTime {
    $a = $Y % 19;
    $b = floor($Y / 100);
    $c = $Y % 100;
    $d = floor($b / 4);
    $e = $b % 4;
    $f = floor(($b + 8) / 25);
    $g = floor(($b - $f + 1) / 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = floor($c / 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = floor(($a + 11 * $h + 22 * $l) / 451);
    $month = floor(($h + $l - 7 * $m + 114) / 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;

    $dateObj   = LitDateTime::createFromFormat('!j-n-Y', $day . '-' . $month . '-' . $Y, new DateTimeZone('UTC'));

    return $dateObj;
  }


  //https://en.wikipedia.org/wiki/Computus#Meeus.27_Julian_algorithm
  //Meeus' Julian algorithm
  //Also many javascript examples can be found here:
  //https://web.archive.org/web/20150227133210/http://www.merlyn.demon.co.uk/estralgs.txt
  public static function calcJulianEaster( int $Y, bool $gregCal=false ) : DateTime {
    $a = $Y % 4;
    $b = $Y % 7;
    $c = $Y % 19;
    $d = (19*$c + 15) % 30;
    $e = (2*$a + 4*$b - $d + 34) % 7;
    $month = floor( ($d + $e + 114) / 31 );
    $day = ( ($d + $e + 114) % 31 ) + 1;

    $dateObj   = LitDateTime::createFromFormat('!j-n-Y', $day.'-'.$month.'-'.$Y, new DateTimeZone( 'UTC' ));
    if($gregCal){
        //from February 29th 2100 Julian (March 14th 2100 Gregorian), 
        //the difference between the Julian and Gregorian calendars will increase to 14 days
        /*
        $dateDiff = 'P' . floor((intval(substr($Y,0,2)) / .75) - 1.25) . 'D';
        $dateObj->add(new DateInterval($dateDiff));
        */
        $GregDateDiff = array();
        $GregDateDiff[0] = [LitDateTime::createFromFormat('!j-n-Y', '4-10-1582'),"P10D"]; //add 10 == GREGORIAN CUTOVER DATE
        $idx = 0;
        $cc = 10;
        for($cent = 17;$cent <= 99; $cent++){
            if($cent % 4 > 0){
                $GregDateDiff[++$idx] = [LitDateTime::createFromFormat('!j-n-Y', '28-2-'.$cent.'00'),"P" . ++$cc . "D"];
            }
        }

        for ($i = count($GregDateDiff); $i>0; $i--){
            if($dateObj > $GregDateDiff[$i-1][0]){
                $dateObj->add(new DateInterval($GregDateDiff[$i-1][1]));
                break;
            }
        }
        /*
        $GregDateDiff[1] = LitDateTime::createFromFormat('!j-n-Y', '28-2-1700'); //add 11 (1600 was a leap year)
        $GregDateDiff[2] = LitDateTime::createFromFormat('!j-n-Y', '28-2-1800'); //add 12
        $GregDateDiff[3] = LitDateTime::createFromFormat('!j-n-Y', '28-2-1900'); //add 13
        $GregDateDiff[4] = LitDateTime::createFromFormat('!j-n-Y', '28-2-2100'); //add 14 (2000 was a leap year)
        $GregDateDiff[5] = LitDateTime::createFromFormat('!j-n-Y', '28-2-2200'); //add 15
        $GregDateDiff[6] = LitDateTime::createFromFormat('!j-n-Y', '28-2-2300'); //add 16
        $GregDateDiff[7] = LitDateTime::createFromFormat('!j-n-Y', '28-2-2500'); //add 17 (2400 will be a leap year)
        $GregDateDiff[8] = LitDateTime::createFromFormat('!j-n-Y', '28-2-2600'); //add 18 
        $GregDateDiff[9] = LitDateTime::createFromFormat('!j-n-Y', '28-2-2700'); //add 19 
        $GregDateDiff[10] = LitDateTime::createFromFormat('!j-n-Y', '28-2-2900'); //add 20 (2800 will be a leap year)
        $GregDateDiff[11] = LitDateTime::createFromFormat('!j-n-Y', '28-2-3000'); //add 21 
        $GregDateDiff[12] = LitDateTime::createFromFormat('!j-n-Y', '28-2-3100'); //add 22 
        */
    }
    return $dateObj;
  }

  private static function debugWrite( string $string ) {
    $debugFile = "LitFuncDebug_" . LitFunc::$HASH_REQUEST . ".log";
    file_put_contents( $debugFile, date('c') . "\t" . $string . PHP_EOL, FILE_APPEND );
  }

}
