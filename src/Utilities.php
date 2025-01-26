<?php

namespace LiturgicalCalendar\Api;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitColor;

/**
 * Class Utilities
 * Useful functions for LitCal\API
 * @author: John Romano D'Orazio
 * THE ENTIRE LITURGICAL CALENDAR DEPENDS MAINLY ON THE DATE OF EASTER
 * This class defines among other things the function
 *  for calculating Gregorian Easter for a given year as used by the latin rite
 */
class Utilities
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
        'suppressed_events',
        'suppressed_events_keys',
        'reinstated_events',
        'reinstated_events_keys',
        'request_headers',
        'color',
        'color_lcl',
        'common'
    ];

    /**
     * Used to keep track of the current array type
     * when processing items of the array.
     * This way the items can be transformed to an appropriate XML element.
     * @var string
     */
    private static string $LAST_ARRAY_KEY = '';

    /**
     * All snake_case keys are automatically transformed to their PascalCase equivalent.
     * If any key needs a specific case transformation other than the automatic snake_case to PascalCase, add it to this array.
     */
    private const CUSTOM_TRANSFORM_KEYS = [
        "litcal" => "LitCal",
        "has_vesper_ii" => "HasVesperII"
    ];

    /**
     * Used to keep track of the current request hash value.
     * The value is set from the Calendar.php class representing the `/calendar` API path.
     * @var string
     */
    public static string $HASH_REQUEST = '';

    /**
     * Checks if a given key is not a LitCalEvent key.
     * LitCalEvent keys are keys in the LitCal array that are associated with a LitCalEvent array.
     * This function is used to determine if a key is a 'special' key in the LitCal array,
     * such as 'metadata', 'settings', 'litcal', etc.
     * @param string $key
     * @return bool
     */
    private static function isNotLitCalEventKey(string $key): bool
    {
        return in_array($key, self::NON_EVENT_KEYS);
    }

    /**
     * Returns true if the key is present in the TRANSFORM_KEYS array, i.e. if the key needs a specific
     * case transformation other than the automatic snake_case to PascalCase.
     * @param string $key
     * @return bool
     */
    private static function isCustomTransformKey(string $key): bool
    {
        return array_key_exists($key, self::CUSTOM_TRANSFORM_KEYS);
    }

    private static function transformKey(string $key): string
    {
        if (self::isCustomTransformKey($key)) {
            return self::CUSTOM_TRANSFORM_KEYS[$key];
        } else {
            return str_replace('_', '', ucwords($key, '_'));
        }
    }

    /**
     * Recursively convert an associative array to an XML object
     * @param array $data
     * @param \SimpleXMLElement $xml
     * @return void
     */
    public static function convertArray2XML(array $data, ?\SimpleXMLElement &$xml): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                self::$LAST_ARRAY_KEY = $key;
                //self::debugWrite( "value of key <$key> is an array" );
                if (self::isNotLitCalEventKey($key)) {
                    $key = self::transformKey($key);
                    $new_object = $xml->addChild($key);
                } else {
                    //self::debugWrite( "key <$key> is a LitCalEvent" );
                    $new_object = $xml->addChild("LitCalEvent");
                    if (is_numeric($key)) {
                        $new_object->addAttribute("idx", $key);
                    }
                }
                //self::debugWrite( "proceeding to convert array value of <$key> to xml sequence..." );
                self::convertArray2XML($value, $new_object);
            } else {
                // For simple values

                // A numeric key means we are dealing with an array item,
                // however XML elements cannot have numerical names, they must have textual names,
                // so we determine the item element name based on the array to which the item belongs
                if (is_numeric($key)) {
                    if (self::$LAST_ARRAY_KEY === 'messages') {
                        $el = $xml->addChild('Message', htmlspecialchars($value));
                        $el->addAttribute("idx", $key);
                    } elseif (in_array(self::$LAST_ARRAY_KEY, ['solemnities_keys','feasts_keys','memorials_keys','suppressed_events_keys','reinstated_events_keys'])) {
                        $el = $xml->addChild('Key', $value);
                        $el->addAttribute("idx", $key);
                    } else {
                        // color, color_lcl, and common array items will be converted to Option elements
                        $el = $xml->addChild('Option', $value);
                        $el->addAttribute("idx", $key);
                    }
                } else {
                    $key = self::transformKey($key);
                    if (is_bool($value)) {
                        $boolVal = $value ? 1 : 0;
                        $xml->addChild($key, $boolVal);
                    }
                    elseif (gettype($value) === 'string') {
                        $xml->addChild($key, htmlspecialchars($value));
                    } else {
                        $xml->addChild($key, $value);
                    }
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
      $debugFile = "UtilitiesDebug_" . Utilities::$HASH_REQUEST . ".log";
      file_put_contents( $debugFile, date('c') . "\t" . $string . PHP_EOL, FILE_APPEND );
    }
    */

    /**
     * Convert a color name to its corresponding hexadecimal value.
     *
     * @param string $color The name of the color (e.g., "red", "green").
     *
     * @return string The hexadecimal representation of the color.
     *                Returns "#000000" for unrecognized color names.
     */
    public static function colorToHex(string $color): string
    {
        $hex = "#";
        switch ($color) {
            case "red":
                $hex .= "FF0000";
                break;
            case "green":
                $hex .= "00AA00";
                break;
            case "white":
                $hex .= "AAAAAA";
                break;
            case "purple":
                $hex .= "AA00AA";
                break;
            case "pink":
                $hex .= "FFAAAA";
                break;
            default:
                $hex .= "000000";
        }
        return $hex;
    }

    /**
     * Converts a string of colors to a string of localized color names.
     *
     * @param string|array $colors A string of color names separated by commas, or an array of color names.
     * @param string $LOCALE The locale to use when localizing the color names.
     * @param bool $html If true, the result will be an HTML string with the color names in bold, italic font with the corresponding color.
     * @return string The localized color names, separated by spaces and the word "or".
     */
    public static function parseColorString(string|array $colors, string $LOCALE, bool $html = false): string
    {
        if (is_string($colors)) {
            $colors = explode(",", $colors);
        }
        if ($html === true) {
            $colors = array_map(function ($txt) use ($LOCALE) {
                return '<B><I><SPAN LANG=' . strtolower($LOCALE) . '><FONT FACE="Calibri" COLOR="' . self::colorToHex($txt) . '">'
                    . LitColor::i18n($txt, $LOCALE)
                    . '</FONT></SPAN></I></B>';
            }, $colors);
            return implode(' <I><FONT FACE="Calibri">' . _("or") . "</FONT></I> ", $colors);
        } else {
            $colors = array_map(function ($txt) use ($LOCALE) {
                return LitColor::i18n($txt, $LOCALE);
            }, $colors);
            return implode(" " . _("or") . " ", $colors);
        }
        return ""; //should never get here
    }

    /**
     * Ordinal Suffix function
     * Useful for choosing the correct suffix for ordinal numbers
     * in the English language
     * @Author: John Romano D'Orazio
     */
    public static function ordSuffix(int $ord): string
    {
        $ord_suffix = ''; //st, nd, rd, th
        if ($ord === 1 || ($ord % 10 === 1  && $ord <> 11)) {
            $ord_suffix = 'st';
        } elseif ($ord === 2 || ($ord % 10 === 2  && $ord <> 12)) {
            $ord_suffix = 'nd';
        } elseif ($ord === 3 || ($ord % 10 === 3  && $ord <> 13)) {
            $ord_suffix = 'rd';
        } else {
            $ord_suffix = 'th';
        }
        return $ord_suffix;
    }

    /**
     * @param int $num
     * @param string $locale
     * @param \NumberFormatter $formatter
     * @param string[] $latinOrdinals
     */
    public static function getOrdinal(int $num, string $locale, \NumberFormatter $formatter, array $latinOrdinals): string
    {
        $ordinal = "";
        $baseLocale = \Locale::getPrimaryLanguage($locale);
        switch ($baseLocale) {
            case "la":
                $ordinal = $latinOrdinals[$num];
                break;
            case "en":
                $ordinal = $num . self::ordSuffix($num);
                break;
            default:
                $ordinal = $formatter->format($num);
        }
        return $ordinal;
    }

    /**
     * Function called after a successful installation of the Catholic Liturgical Calendar API.
     * It prints a message of thanksgiving to God and a prayer for the Pope.
     *
     * @return void
     */
    public static function postInstall(): void
    {
        printf("\t\033[4m\033[1;44mCatholic Liturgical Calendar\033[0m\n");
        printf("\t\033[0;33mAd Majorem Dei Gloriam\033[0m\n");
        printf("\t\033[0;36mOremus pro Pontifice nostro Francisco Dominus\n\tconservet eum et vivificet eum et beatum faciat eum in terra\n\tet non tradat eum in animam inimicorum ejus\033[0m\n");
    }
}
