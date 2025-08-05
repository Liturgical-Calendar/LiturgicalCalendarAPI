<?php

namespace LiturgicalCalendar\Api;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsMap;

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
        'solemnities_lord_bvm',
        'solemnities_lord_bvm_keys',
        'solemnities',
        'solemnities_keys',
        'feasts_lord',
        'feasts_lord_keys',
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
        'common',
        'readings',
        'schema_one',
        'schema_two',
        'schema_three',
        'night',
        'dawn',
        'day',
        'evening'
    ];

    // EVENT_KEY_ELS are keys whose value is an array of LitCalEvents, and should become <Key> elements rather than <Option> elements
    private const EVENT_KEY_ELS = [
        'solemnities_lord_bvm_keys',
        'solemnities_keys',
        'feasts_lord_keys',
        'feasts_keys',
        'memorials_keys',
        'suppressed_events_keys',
        'reinstated_events_keys'
    ];

    /**
     * Used to keep track of the current array type
     * when processing items of the array.
     * This way the items can be transformed to an appropriate XML element.
     * @var string|int
     */
    private static string|int $LAST_ARRAY_KEY = '';

    /**
     * All snake_case keys are automatically transformed to their PascalCase equivalent.
     * If any key needs a specific case transformation other than the automatic snake_case to PascalCase, add it to this array.
     */
    private const CUSTOM_TRANSFORM_KEYS = [
        'litcal'                    => 'LitCal',
        'has_vesper_ii'             => 'HasVesperII',
        'solemnities_lord_bvm'      => 'SolemnitiesLordBVM',
        'solemnities_lord_bvm_keys' => 'SolemnitiesLordBVMKeys'
    ];

    private const READINGS_CHRISTMAS_KEYS = [
        'night',
        'dawn',
        'day'
    ];

    /**
     * Used to keep track of the current request hash value.
     * The value is set from the CalendarPath.php class representing the `/calendar` API path.
     * @var string
     */
    public static string $HASH_REQUEST = '';

    /**
     * Checks if a given key is not a LitCalEvent key.
     * LitCalEvent keys are keys in the LitCal array that are associated with a LitCalEvent array.
     * This function is used to determine if a key is a 'special' key in the LitCal array,
     * such as 'metadata', 'settings', 'litcal', etc.
     * @param string|int $key
     * @return bool
     */
    private static function isNotLitCalEventKey(string|int $key): bool
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

    /**
     * Returns the PascalCase representation of a given snake_case key.
     * If the key is present in the CUSTOM_TRANSFORM_KEYS array, its value is returned instead.
     * @param string $key
     * @return string
     */
    private static function transformKey(string $key): string
    {
        if (self::isCustomTransformKey($key)) {
            return self::CUSTOM_TRANSFORM_KEYS[$key];
        } else {
            return str_replace('_', '', ucwords($key, '_'));
        }
    }

    /**
     * Calculates the xsi type of the lectionary readings object, based on its keys.
     *
     * This function is used when generating the XML representation of the Readings for a LitCalEvent.
     *
     * @param array<string,string> $value
     * @return array<string,string>
     */
    private static function getReadingsType(array $value): array
    {
        $itemKeys     = array_keys($value);
        $itemKeyCount = count($itemKeys);

        // First we test for the two dimensional readings instances,
        // starting from the most complex to the most simple.
        // This is important to calculate correctly the diff between the keys!
        // e.g. READINGS_CHRISTMAS_KEYS has more values than READINGS_WITH_VIGIL_KEYS

        if ($itemKeyCount === count(ReadingsMap::READINGS_MULTIPLE_SCHEMAS_KEYS) && array_diff(ReadingsMap::READINGS_MULTIPLE_SCHEMAS_KEYS, $itemKeys) === []) {
            return [
                'attribute' => 'multipleSchemas',
                'xsi'       => 'cl:ReadingsMultipleSchemasType'
            ];
        }

        if ($itemKeyCount === count(self::READINGS_CHRISTMAS_KEYS) && array_diff(self::READINGS_CHRISTMAS_KEYS, $itemKeys) === []) {
            return [
                'attribute' => 'christmas',
                'xsi'       => 'cl:ReadingsChristmasType'
            ];
        }

        if ($itemKeyCount === count(ReadingsMap::READINGS_WITH_EVENING_MASS_KEYS) && array_diff(ReadingsMap::READINGS_WITH_EVENING_MASS_KEYS, $itemKeys) === []) {
            return [
                'attribute' => 'withEveningMass',
                'xsi'       => 'cl:ReadingsWithEveningMassType'
            ];
        }

        if ($itemKeyCount === count(ReadingsMap::READINGS_SEASONAL_KEYS) && array_diff(ReadingsMap::READINGS_SEASONAL_KEYS, $itemKeys) === []) {
            return [
                'attribute' => 'seasonal',
                'xsi'       => 'cl:ReadingsSeasonalType'
            ];
        }

        // Then we test for the one dimensional readings instances,
        // starting from the most complex to the most simple.
        // This is important to calculate correctly the diff between the keys!
        // e.g. EASTER_VIGIL_KEYS has more values than PALM_SUNDAY_KEYS, and so on.

        if ($itemKeyCount === count(ReadingsMap::EASTER_VIGIL_KEYS) && array_diff(ReadingsMap::EASTER_VIGIL_KEYS, $itemKeys) === []) {
            return [
                'attribute' => 'easterVigil',
                'xsi'       => 'cl:ReadingsEasterVigilType'
            ];
        }
        if ($itemKeyCount === count(ReadingsMap::PALM_SUNDAY_KEYS) && array_diff(ReadingsMap::PALM_SUNDAY_KEYS, $itemKeys) === []) {
            return [
                'attribute' => 'palmSunday',
                'xsi'       => 'cl:ReadingsPalmSundayType'
            ];
        }
        if ($itemKeyCount === count(ReadingsMap::FESTIVE_KEYS) && array_diff(ReadingsMap::FESTIVE_KEYS, $itemKeys) === []) {
            return [
                'attribute' => 'festive',
                'xsi'       => 'cl:ReadingsFestiveType'
            ];
        }
        if ($itemKeyCount === count(ReadingsMap::FERIAL_KEYS) && array_diff(ReadingsMap::FERIAL_KEYS, $itemKeys) === []) {
            return [
                'attribute' => 'ferial',
                'xsi'       => 'cl:ReadingsFerialType'
            ];
        }
        return [
            'attribute' => '???',
            'xsi'       => 'cl:ReadingsType'
        ];
    }

    /**
     * Recursively convert an associative array to an XML object
     * @param array<string|int,mixed> $data
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
                    // the key will always be a string in this case,
                    // but we pass it explicitly as a string to the transformKey function
                    // which expects a string, to make phpstan happy
                    $key        = self::transformKey($key . '');
                    $new_object = $xml->addChild($key);
                    if (in_array($key, ['Readings', 'Night', 'Day', 'Dawn', 'Evening', 'SchemaOne', 'SchemaTwo', 'SchemaThree'])) {
                        ['attribute' => $attribute, 'xsi' => $xsi] = self::getReadingsType($value);
                        $new_object->addAttribute('readingsType', $attribute);
                        $new_object->addAttribute('xsi:type', $xsi, 'http://www.w3.org/2001/XMLSchema-instance');
                    }
                } else {
                    //self::debugWrite( "key <$key> is a LitCalEvent" );
                    $new_object = $xml->addChild('LitCalEvent');
                    if (is_numeric($key)) {
                        $new_object->addAttribute('idx', $key . '');
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
                        $el->addAttribute('idx', $key . '');
                    } elseif (in_array(self::$LAST_ARRAY_KEY, self::EVENT_KEY_ELS, true)) {
                        $el = $xml->addChild('Key', $value);
                        $el->addAttribute('idx', $key . '');
                    } else {
                        // color, color_lcl, and common array items will be converted to Option elements
                        $el = $xml->addChild('Option', $value);
                        $el->addAttribute('idx', $key . '');
                    }
                } else {
                    $key = self::transformKey($key);
                    if (is_bool($value)) {
                        $boolVal = $value ? '1' : '0';
                        $xml->addChild($key, $boolVal);
                    }
                    elseif (gettype($value) === 'string') {
                        $xml->addChild($key, htmlspecialchars($value));
                        if ('Readings' === $key) {
                            $xml->Readings->addAttribute('readingsType', 'fromCommons');
                            $xml->Readings->addAttribute('xsi:type', 'cl:ReadingsCommonsType', 'http://www.w3.org/2001/XMLSchema-instance');
                        }
                    } else {
                        $xml->addChild($key, $value);
                    }
                }
            }
        }
    }


    /**
     * Calculates the Gregorian Easter date for a given year.
     * @link https://en.wikipedia.org/wiki/Computus#Anonymous_Gregorian_algorithm
     * aka Meeus/Jones/Butcher algorithm
     * @param int $Y The year for which to calculate the Easter date.
     * @return DateTime The date of Easter in the Gregorian calendar for the given year.
     */
    public static function calcGregEaster($Y): DateTime
    {
        $a     = $Y % 19;
        $b     = floor($Y / 100);
        $c     = $Y % 100;
        $d     = floor($b / 4);
        $e     = $b % 4;
        $f     = floor(( $b + 8 ) / 25);
        $g     = floor(( $b - $f + 1 ) / 3);
        $h     = ( 19 * $a + $b - $d - $g + 15 ) % 30;
        $i     = floor($c / 4);
        $k     = $c % 4;
        $l     = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
        $m     = floor(( $a + 11 * $h + 22 * $l ) / 451);
        $month = floor(( $h + $l - 7 * $m + 114 ) / 31);
        $day   = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;

        return DateTime::fromFormat($day . '-' . $month . '-' . $Y);
    }


    /**
     * Meeus' Julian algorithm
     *
     * See {@link https://en.wikipedia.org/wiki/Computus#Meeus.27_Julian_algorithm}.
     * Also many javascript examples can be found here: {@link https://web.archive.org/web/20150227133210/http://www.merlyn.demon.co.uk/estralgs.txt}
     *
     * @param int $Y The year for which to calculate the Easter date.
     * @param bool $gregCal If true, the resulting date is adjusted to the Gregorian calendar.
     * @return DateTime The date of Easter in the Julian calendar for the given year.
     * @throws \Exception
     */
    public static function calcJulianEaster(int $Y, bool $gregCal = false): DateTime
    {
        $a     = $Y % 4;
        $b     = $Y % 7;
        $c     = $Y % 19;
        $d     = ( 19 * $c + 15 ) % 30;
        $e     = ( 2 * $a + 4 * $b - $d + 34 ) % 7;
        $month = floor(( $d + $e + 114 ) / 31);
        $day   = ( ( $d + $e + 114 ) % 31 ) + 1;

        $dateObj = DateTime::fromFormat($day . '-' . $month . '-' . $Y);
        if ($gregCal) {
            //from February 29th 2100 Julian (March 14th 2100 Gregorian),
            //the difference between the Julian and Gregorian calendars will increase to 14 days
            /*
            $dateDiff = 'P' . floor((intval(substr($Y,0,2)) / .75) - 1.25) . 'D';
            $dateObj->add(new DateInterval($dateDiff));
            */
            $GregDateDiff    = [];
            $gregDateObj     = DateTime::fromFormat('4-10-1582');
            $GregDateDiff[0] = [$gregDateObj, 'P10D']; //add 10 = GREGORIAN CUTOVER DATE
            $idx             = 0;
            $cc              = 10;
            for ($cent = 17; $cent <= 99; $cent++) {
                if ($cent % 4 > 0) {
                    $gregDateObj          = DateTime::fromFormat('28-2-' . $cent . '00');
                    $GregDateDiff[++$idx] = [$gregDateObj, 'P' . ++$cc . 'D'];
                }
            }

            for ($i = count($GregDateDiff); $i > 0; $i--) {
                if ($dateObj > $GregDateDiff[$i - 1][0]) {
                    $dateInterval = new \DateInterval($GregDateDiff[$i - 1][1]);
                    $dateObj->add($dateInterval);
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
    private static function debugWrite(string $string): void
    {
        $debugFile = 'UtilitiesDebug_' . Utilities::$HASH_REQUEST . '.log';
        file_put_contents($debugFile, date('c') . "\t" . $string . PHP_EOL, FILE_APPEND);
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
        $hex = '#';
        switch ($color) {
            case 'red':
                $hex .= 'FF0000';
                break;
            case 'green':
                $hex .= '00AA00';
                break;
            case 'white':
                $hex .= 'AAAAAA';
                break;
            case 'purple':
                $hex .= 'AA00AA';
                break;
            case 'pink':
                $hex .= 'FFAAAA';
                break;
            default:
                $hex .= '000000';
        }
        return $hex;
    }

    /**
     * Converts an array of LitColor items to a string of localized color names.
     *
     * @param LitColor[] $colors An array of LitColor.
     * @param string $LOCALE The locale to use when localizing the color names.
     * @param bool $html If true, the result will be an HTML string with the color names in bold, italic font with the corresponding color.
     * @return string The localized color names, separated by spaces and the word "or".
     */
    public static function parseColorToString(array $colors, string $LOCALE, bool $html = false): string
    {
        if ($html === true) {
            $colorStrings = array_map(function (LitColor $litColor) use ($LOCALE) {
                return '<B><I><SPAN LANG=' . strtolower($LOCALE) . '><FONT FACE="Calibri" COLOR="' . self::colorToHex($litColor->value) . '">'
                    . LitColor::i18n($litColor, $LOCALE)
                    . '</FONT></SPAN></I></B>';
            }, $colors);
            return implode(' <I><FONT FACE="Calibri">' . _('or') . '</FONT></I> ', $colorStrings);
        } else {
            $colorStrings = array_map(function (LitColor $txt) use ($LOCALE) {
                return LitColor::i18n($txt, $LOCALE);
            }, $colors);
            return implode(' ' . _('or') . ' ', $colorStrings);
        }
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
        if ($ord === 1 || ( $ord % 10 === 1  && $ord <> 11 )) {
            $ord_suffix = 'st';
        } elseif ($ord === 2 || ( $ord % 10 === 2  && $ord <> 12 )) {
            $ord_suffix = 'nd';
        } elseif ($ord === 3 || ( $ord % 10 === 3  && $ord <> 13 )) {
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
        $ordinal    = '';
        $baseLocale = \Locale::getPrimaryLanguage($locale);
        switch ($baseLocale) {
            case 'la':
                $ordinal = $latinOrdinals[$num];
                break;
            case 'en':
                $ordinal = $num . self::ordSuffix($num);
                break;
            default:
                $ordinal = $formatter->format($num);
        }
        if (false === $ordinal) {
            throw new \Exception('Unable to get ordinal for ' . $num . ' in locale ' . $locale);
        }
        return $ordinal;
    }

    private static function rawContentsFromFile(string $filename, bool $associative): string
    {
        if (false === file_exists($filename)) {
            throw new \Exception('File ' . $filename . ' does not exist');
        }

        if (false === is_readable($filename)) {
            throw new \Exception('File ' . $filename . ' is not readable');
        }

        $rawContents = file_get_contents($filename);
        if (false === $rawContents) {
            throw new \Exception('Unable to read file ' . $filename);
        }

        return $rawContents;
    }

    /**
     * @param string $filename
     * @return array<string|int,mixed>
     * @throws \Exception
     */
    public static function jsonFileToArray(string $filename): array
    {
        $rawContents = self::rawContentsFromFile($filename, true);
        $jsonArr     = json_decode($rawContents, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \Exception('Unable to decode JSON from file ' . $filename . ' as an array: ' . json_last_error_msg());
        }

        /** @var array<string|int,mixed> $jsonArr */
        return $jsonArr;
    }

    /**
     * Reads a JSON file and converts its contents to an object.
     *
     * @param string $filename The path to the JSON file.
     * @return \stdClass|\stdClass[] The decoded JSON data as an object.
     * @throws \Exception If the file does not exist, is not readable, or contains invalid JSON.
     */
    public static function jsonFileToObject(string $filename): \stdClass|array
    {
        $rawContents = self::rawContentsFromFile($filename, true);
        $jsonObj     = json_decode($rawContents, false);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \Exception('Unable to decode JSON from file ' . $filename . ' as an object: ' . json_last_error_msg());
        }

        /** @var \stdClass|\stdClass[] $jsonObj */
        return $jsonObj;
    }

    /**
     * Converts an object to an array.
     *
     * The object should contain public properties.
     * This method is useful for a deep conversion of an object to an array.
     *
     * @param \stdClass $object The object to convert.
     * @return array<string|int,mixed> The array representation of the object.
     * @throws \Exception If unable to encode or decode the object.
     */
    public static function objectToArray(\stdClass $object): array
    {
        $encoded = json_encode($object);
        if (false === $encoded) {
            throw new \Exception('Unable to encode object to array');
        }
        $decoded = json_decode($encoded, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Unable to decode json to array');
        }
        /** @var array<string|int,mixed> $decoded */
        return $decoded;
    }

    public static function ucfirst(string|false $str): string
    {
        if (false === $str) {
            throw new \InvalidArgumentException('value is false, cannot capitalize the first letter');
        }
        return \ucfirst($str);
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
