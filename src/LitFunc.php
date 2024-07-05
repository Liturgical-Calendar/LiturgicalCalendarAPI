<?php

namespace Johnrdorazio\LitCal;

use Johnrdorazio\LitCal\DateTime;

/**
 * Class LitFunc
 * Useful functions for LitCal\API
 * @author: John Romano D'Orazio
 * THE ENTIRE LITURGICAL CALENDAR DEPENDS MAINLY ON THE DATE OF EASTER
 * This class defines among other things the function
 *  for calculating Gregorian Easter for a given year as used by the latin rite
 */
class LitFunc
{
    // NON_EVENT_KEYS are keys whose value is an array, but are not a LitCalEvent
    private const NON_EVENT_KEYS = [
        'litcal',
        'settings',
        'messages',
        'metadata',
        'solemnities',
        'solemnities_keys',
        'feasts',
        'feasts_keys',
        'memorials',
        'memorials_keys',
        'request_headers',
        'color',
        'color_lcl',
        'common'
    ];
    private static string $LAST_ARRAY_KEY = '';
    private const TRANSFORM_KEYS = [
      "litcal"            => "LitCal",
      "has_vesper_ii"     => "HasVesperII"
    ];
    public static string $HASH_REQUEST    = '';

    private static function isNotLitCalEventKey(string $key): bool
    {
        return in_array($key, self::NON_EVENT_KEYS);
    }

    private static function isTransformKey(string $key): bool
    {
        return array_key_exists($key, self::TRANSFORM_KEYS);
    }

    public static function convertArray2XML(array $data, ?\SimpleXMLElement &$xml): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                self::$LAST_ARRAY_KEY = $key;
                //self::debugWrite( "value of key <$key> is an array" );
                if (self::isNotLitCalEventKey($key)) {
                    //self::debugWrite( "key <$key> is not a LitCalEvent" );
                    if (self::isTransformKey($key)) {
                        $key = self::TRANSFORM_KEYS[$key];
                    } else {
                        $key = str_replace('_', '', ucwords($key, '_'));
                    }
                    $new_object = $xml->addChild($key);
                } else {
                    //self::debugWrite( "key <$key> is a LitCalEvent" );
                    $new_object = $xml->addChild("LitCalEvent");
                    if (is_numeric($key)) {
                        $new_object->addAttribute("idx", $key);
                    } else {
                        $new_object->addAttribute("eventKey", $key);
                    }
                }
                //self::debugWrite( "proceeding to convert array value of <$key> to xml sequence..." );
                self::convertArray2XML($value, $new_object);
            } else {
              // XML elements cannot have numerical names, they must have text
                if (is_numeric($key)) {
                    //self::debugWrite( "key <$key> is numerical, have to deal with this..." );
                    if (self::$LAST_ARRAY_KEY === 'messages') {
                      //self::debugWrite( "key <$key> seems to belong to the Messages array: will create a corresponding <message> element with attribute 'idx'" );
                        $el = $xml->addChild('Message', htmlspecialchars($value));
                        $el->addAttribute("idx", $key);
                    } else {
                      //self::debugWrite( "key <$key> does not seem to belong to the Messages array: will create a corresponding <option> element with attribute 'idx'" );
                        $el = $xml->addChild('Option', $value);
                        $el->addAttribute("idx", $key);
                    }
                } elseif (is_bool($value)) {
                    $boolVal = $value ? 1 : 0;
                    if (self::isTransformKey($key)) {
                        $key = self::TRANSFORM_KEYS[$key];
                    } else {
                        $key = str_replace('_', '', ucwords($key, '_'));
                    }
                    $xml->addChild($key, $boolVal);
                } else {
                    if (self::isTransformKey($key)) {
                        $key = self::TRANSFORM_KEYS[$key];
                    } else {
                        $key = str_replace('_', '', ucwords($key, '_'));
                    }
                    $xml->addChild($key, htmlspecialchars($value));
                }
            }
        }
    }

    // https://en.wikipedia.org/wiki/Computus#Anonymous_Gregorian_algorithm
    // aka Meeus/Jones/Butcher algorithm
    public static function calcGregEaster($Y): DateTime
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

        $dateObj   = DateTime::createFromFormat('!j-n-Y', $day . '-' . $month . '-' . $Y, new \DateTimeZone('UTC'));

        return $dateObj;
    }


    //https://en.wikipedia.org/wiki/Computus#Meeus.27_Julian_algorithm
    //Meeus' Julian algorithm
    //Also many javascript examples can be found here:
    //https://web.archive.org/web/20150227133210/http://www.merlyn.demon.co.uk/estralgs.txt
    public static function calcJulianEaster(int $Y, bool $gregCal = false): DateTime
    {
        $a = $Y % 4;
        $b = $Y % 7;
        $c = $Y % 19;
        $d = (19 * $c + 15) % 30;
        $e = (2 * $a + 4 * $b - $d + 34) % 7;
        $month = floor(($d + $e + 114) / 31);
        $day = ( ($d + $e + 114) % 31 ) + 1;

        $dateObj   = DateTime::createFromFormat('!j-n-Y', $day . '-' . $month . '-' . $Y, new \DateTimeZone('UTC'));
        if ($gregCal) {
            //from February 29th 2100 Julian (March 14th 2100 Gregorian),
            //the difference between the Julian and Gregorian calendars will increase to 14 days
            /*
            $dateDiff = 'P' . floor((intval(substr($Y,0,2)) / .75) - 1.25) . 'D';
            $dateObj->add(new DateInterval($dateDiff));
            */
            $GregDateDiff = array();
            $GregDateDiff[0] = [DateTime::createFromFormat('!j-n-Y', '4-10-1582'),"P10D"]; //add 10 == GREGORIAN CUTOVER DATE
            $idx = 0;
            $cc = 10;
            for ($cent = 17; $cent <= 99; $cent++) {
                if ($cent % 4 > 0) {
                    $GregDateDiff[++$idx] = [DateTime::createFromFormat('!j-n-Y', '28-2-' . $cent . '00'),"P" . ++$cc . "D"];
                }
            }

            for ($i = count($GregDateDiff); $i > 0; $i--) {
                if ($dateObj > $GregDateDiff[$i - 1][0]) {
                    $dateObj->add(new \DateInterval($GregDateDiff[$i - 1][1]));
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
    /**
    private static function debugWrite( string $string ) {
      $debugFile = "LitFuncDebug_" . LitFunc::$HASH_REQUEST . ".log";
      file_put_contents( $debugFile, date('c') . "\t" . $string . PHP_EOL, FILE_APPEND );
    }
    */
}
