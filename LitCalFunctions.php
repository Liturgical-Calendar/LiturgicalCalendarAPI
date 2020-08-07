<?php

/**
 * Useful functions for LitCalEngine.php
 * @Author: John Romano D'Orazio
 * @Date:   2017-2018
 */

// https://en.wikipedia.org/wiki/Computus#Anonymous_Gregorian_algorithm
// aka Meeus/Jones/Butcher algorithm
ini_set('date.timezone', 'Europe/Vatican');

function calcGregEaster($Y)
{
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
function ordSuffix($ord)
{
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
function getOrdinal($num,$LOCALE,$formatter,$latinOrdinals){
  $ordinal = "";
  switch($LOCALE){
      case 'LA':
          $ordinal = $latinOrdinals[$num];
      break;
      case 'EN':
          //$ordinal = $formatter->format($currentOrdWeek);
          $ordinal = $num . ordSuffix($num);
      break;
      default:
          $ordinal = $formatter->format($num);
  }
  return $ordinal;
}

/**
 * Database Connection function
 */
define("LITURGYAPP", "AMDG"); //definition needed to allow inclusion of liturgy_config.php, otherwise will fail
//this is a security to prevent liturgy_config.php from being accessed directly
//access is allowed only if this constant is defined
include "LitCalConfig.php"; //this is where database connection info is defined

function databaseConnect()
{

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

function convertArray2XML(SimpleXMLElement $object, array $data)
{
  foreach ($data as $key => $value) {
    if (is_array($value)) {
      if($key === 'LitCal' || $key === 'Settings' || $key === 'Messages'){
        $new_object = $object->addChild($key);
      } else {
        $new_object = $object->addChild("LitCalEvent");
        $new_object->addAttribute("eventkey",$key);  
      }
      convertArray2XML($new_object, $value);
    } else {
      // if the key is a number, it needs text with it to actually work
      if (is_numeric($key)) {
        $key = "numeric_$key";
      }

      $object->addChild($key, $value);
    }
  }
}
