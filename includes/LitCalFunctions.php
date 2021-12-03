<?php

/**
 * Useful functions for LitCalEngine.php
 * @Author: John Romano D'Orazio
 * @Date:   2017-2018
 */

ini_set('date.timezone', 'Europe/Vatican');

define("LITURGYAPP", "AMDG");
include_once( "LitCalConfig.php" );

/**
 *  THE ENTIRE LITURGICAL CALENDAR DEPENDS MAINLY ON THE DATE OF EASTER
 *  THE FOLLOWING LITCALFUNCTIONS.PHP DEFINES AMONG OTHER THINGS THE FUNCTION
 *  FOR CALCULATING GREGORIAN EASTER FOR A GIVEN YEAR AS USED BY THE LATIN RITE
 */


class LitCalFf {

  const NON_EVENT_KEYS = [ 'LitCal', 'Settings', 'Messages', 'Metadata', 'SOLEMNITIES', 'FEASTS_MEMORIALS' ];

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

    $dateObj   = DateTime::createFromFormat('!j-n-Y', $day . '-' . $month . '-' . $Y, new DateTimeZone('UTC'));

    return $dateObj;
  }



  /** 
   * Ordinal Suffix function
   * Useful for choosing the correct suffix for ordinal numbers
   * in the English language
   * @Author: John Romano D'Orazio
   */
  public static function ordSuffix(int $ord) : string {
    $ord_suffix = ''; //st, nd, rd, th
    if ($ord === 1 || ($ord % 10 === 1  && $ord <> 11)) {
      $ord_suffix = 'st';
    } else if ($ord === 2 || ($ord % 10 === 2  && $ord <> 12)) {
      $ord_suffix = 'nd';
    } else if ($ord === 3 || ($ord % 10 === 3  && $ord <> 13)) {
      $ord_suffix = 'rd';
    } else {
      $ord_suffix = 'th';
    }
    return $ord_suffix;
  }

  /**
   * @param int $num
   * @param string $LOCALE
   * @param NumberFormatter $formatter
   * @param string[] $latinOrdinals
   */
  public static function getOrdinal(int $num, string $LOCALE, NumberFormatter $formatter, array $latinOrdinals) : string {
    $ordinal = "";
    switch($LOCALE){
        case 'LA':
            $ordinal = $latinOrdinals[$num];
        break;
        case 'EN':
            $ordinal = $num . self::ordSuffix($num);
        break;
        default:
            $ordinal = $formatter->format($num);
    }
    return $ordinal;
  }
  
  /**
   * Database Connection function
   */
  
  public static function databaseConnect() : stdClass {
  
    $retObject = new stdClass();
    $retObject->retString = "";
    $retObject->mysqli = new mysqli(DB_SERVER, DB_USER, DB_PASSWORD, DB_NAME);
  
    if ($retObject->mysqli->connect_errno) {
      $retObject->retString .= "Failed to connect to MySQL: (" . $retObject->mysqli->connect_errno . ") " . $retObject->mysqli->connect_error . PHP_EOL;
      return $retObject;
    } else {
      $retObject->retString .= sprintf("Connected to MySQL Database: %s\n", DB_NAME);
    }
    if (!$retObject->mysqli->set_charset(DB_CHARSET)) {
      $retObject->retString .= sprintf("Error loading character set utf8: %s\n", $retObject->mysqli->error);
    } else {
      $retObject->retString .= sprintf("Current character set: %s\n", $retObject->mysqli->character_set_name());
    }

    return $retObject;
  }

  public static function isNotLitCalEventKey( string $key ) : bool {
    return in_array( $key, self::NON_EVENT_KEYS );
  }

  public static function convertArray2XML(array $data, ?SimpleXMLElement &$xml) : void {
    foreach( $data as $key => $value ) {
      if( is_array( $value ) ) {
        if( self::isNotLitCalEventKey( $key ) ) {
          $new_object = $xml->addChild( $key );
        } else {
          $new_object = $xml->addChild( "LitCalEvent" );
          $new_object->addAttribute( "eventkey", $key );
        }
        //TODO: verify that this actually works?
        //do we need to catch anything being returnd?
        //do we need to pass $new_object as a reference?
        self::convertArray2XML( $value, $new_object );
      } else {
        // if the key is a number, it needs text with it to actually work
        if( is_numeric( $key ) ) {
          $key = "numeric_$key";
        }
        $xml->addChild( $key, $value );
      }
    }
  }

  /**
   * psalterWeek function
   * Calculates the current Week of the Psalter (from 1 to 4)
   * based on the week of Ordinary Time
   * OR the week of Advent, Christmas, Lent, or Easter
   */
  public static function psalterWeek( int $weekOfOrdinaryTimeOrSeason ) : int {
    return $weekOfOrdinaryTimeOrSeason % 4 === 0 ? 4 : $weekOfOrdinaryTimeOrSeason % 4;
  }

}
