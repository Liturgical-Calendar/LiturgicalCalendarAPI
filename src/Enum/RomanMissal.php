<?php

namespace LiturgicalCalendar\Api\Enum;

use LiturgicalCalendar\Api\Enum\JsonData;

/**
 * Enum class for the different Roman Missals that are used in the LitCal
 *
 * @method static bool isValid($value)
 * @method static bool isLatinMissal($value)
 * @method static string getName($value)
 * @method static string|false|null getSanctoraleFileName($value)
 * @method static string|false|null getSanctoraleI18nFilePath($value)
 * @method static object getYearLimits($value)
 * @method static array produceMetadata($obj = true)
 */
class RomanMissal
{
    public const EDITIO_TYPICA_1970                 = "EDITIO_TYPICA_1970";
    public const REIMPRESSIO_EMENDATA_1971          = "EDITIO_TYPICA_1971";
    public const EDITIO_TYPICA_SECUNDA_1975         = "EDITIO_TYPICA_1975";
    public const EDITIO_TYPICA_TERTIA_2002          = "EDITIO_TYPICA_2002";
    public const EDITIO_TYPICA_TERTIA_EMENDATA_2008 = "EDITIO_TYPICA_2008";

    public const USA_EDITION_2011         = "US_2011";
    public const ITALY_EDITION_1983       = "IT_1983";
    public const ITALY_EDITION_2020       = "IT_2020";
    public const NETHERLANDS_EDITION_1978 = "NL_1978";
    public const CANADA_EDITION_2011      = "CA_2011";
    public const CANADA_EDITION_2016      = "CA_2016";

    /**
     * The values of the Roman Missal enumeration constants.
     * This array is used to check if a given missal_id is a valid Roman Missal enumeration constant.
     * @static
     * @var array<string>
     * @see RomanMissal::isValid()
     */
    public static array $values = [
        "EDITIO_TYPICA_1970",
        "EDITIO_TYPICA_1971",
        "EDITIO_TYPICA_1975",
        "EDITIO_TYPICA_2002",
        "EDITIO_TYPICA_2008",
        "US_2011",
        "IT_1983",
        "IT_2020",
        "NL_1978",
        "CA_2011",
        "CA_2016"
    ];

    /**
     * An associative array of the Roman Missal names, where the key is the value of a Roman Missal constant.
     * This array is used to get the name of a Roman Missal given its id.
     * @static
     * @var array<string, string>
     * @see RomanMissal::getName()
     */
    public static array $names = [
        self::EDITIO_TYPICA_1970                    => "Editio Typica 1970",
        self::REIMPRESSIO_EMENDATA_1971             => "Reimpressio Emendata 1971",
        self::EDITIO_TYPICA_SECUNDA_1975            => "Editio Typica Secunda 1975",
        self::EDITIO_TYPICA_TERTIA_2002             => "Editio Typica Tertia 2002",
        self::EDITIO_TYPICA_TERTIA_EMENDATA_2008    => "Editio Typica Tertia Emendata 2008",
        self::USA_EDITION_2011                      => "2011 Roman Missal issued by the USCCB",
        self::ITALY_EDITION_1983                    => "Messale Romano ed. 1983 pubblicata dalla CEI",
        self::ITALY_EDITION_2020                    => "Messale Romano ed. 2020 pubblicata dalla CEI",
        self::NETHERLANDS_EDITION_1978              => "Romeins Missaal ed. 1978 goedgekeurd door de Nederlandse bisschoppenconferentie",
        self::CANADA_EDITION_2011                   => "2011 Roman Missal issued by the CCCB",
        self::CANADA_EDITION_2016                   => "2016 Roman Missal issued by the CCCB"
    ];

    /**
     * An associative array of the JSON file paths, where the key is the value of a Roman Missal constant.
     * This array is used to get the path to the JSON file containing the sanctorale data for a Roman Missal.
     * @static
     * @var array<string, string|false>
     * @see RomanMissal::getSanctoraleFileName()
     */
    public static array $jsonFiles = [
        self::EDITIO_TYPICA_1970                    => JsonData::MISSALS_FOLDER . "/propriumdesanctis_1970/propriumdesanctis_1970.json",
        self::REIMPRESSIO_EMENDATA_1971             => false,
        self::EDITIO_TYPICA_SECUNDA_1975            => false,
        self::EDITIO_TYPICA_TERTIA_2002             => JsonData::MISSALS_FOLDER . "/propriumdesanctis_2002/propriumdesanctis_2002.json",
        self::EDITIO_TYPICA_TERTIA_EMENDATA_2008    => JsonData::MISSALS_FOLDER . "/propriumdesanctis_2008/propriumdesanctis_2008.json",
        self::USA_EDITION_2011                      => JsonData::MISSALS_FOLDER . "/propriumdesanctis_US_2011/propriumdesanctis_US_2011.json",
        self::ITALY_EDITION_1983                    => JsonData::MISSALS_FOLDER . "/propriumdesanctis_IT_1983/propriumdesanctis_IT_1983.json",
        self::ITALY_EDITION_2020                    => false,
        self::NETHERLANDS_EDITION_1978              => false,
        self::CANADA_EDITION_2011                   => false,
        self::CANADA_EDITION_2016                   => false
    ];

    /**
     * An associative array of the i18n file paths, where the key is the value of a Roman Missal constant.
     * This array is used to get the path to the i18n directory for the sanctorale of a Roman Missal.
     * @static
     * @var array<string, string|false>
     * @see RomanMissal::getSanctoraleI18nFilePath()
     */
    public static array $i18nPath = [
        self::EDITIO_TYPICA_1970                    => JsonData::MISSALS_FOLDER . "/propriumdesanctis_1970/i18n/",
        self::REIMPRESSIO_EMENDATA_1971             => false,
        self::EDITIO_TYPICA_SECUNDA_1975            => false,
        self::EDITIO_TYPICA_TERTIA_2002             => JsonData::MISSALS_FOLDER . "/propriumdesanctis_2002/i18n/",
        self::EDITIO_TYPICA_TERTIA_EMENDATA_2008    => JsonData::MISSALS_FOLDER . "/propriumdesanctis_2008/i18n/",
        self::USA_EDITION_2011                      => false,
        self::ITALY_EDITION_1983                    => false,
        self::ITALY_EDITION_2020                    => false,
        self::NETHERLANDS_EDITION_1978              => false,
        self::CANADA_EDITION_2011                   => false,
        self::CANADA_EDITION_2016                   => false
    ];

    /**
     * An associative array of the year limits, where the key is the value of a Roman Missal constant
     * and the value is an associative array with the properties 'since_year' and optionally 'until_year'.
     * This array is used to get the year limits for a Roman Missal.
     * @static
     * @var array<string, array{since_year: int, until_year?: int}>
     * @see RomanMissal::getYearLimits()
     */
    public static array $yearLimits = [
        self::EDITIO_TYPICA_1970                    => [ "since_year" => 1970 ],
        self::REIMPRESSIO_EMENDATA_1971             => [ "since_year" => 1971 ],
        self::EDITIO_TYPICA_SECUNDA_1975            => [ "since_year" => 1975 ],
        self::EDITIO_TYPICA_TERTIA_2002             => [ "since_year" => 2002 ],
        self::EDITIO_TYPICA_TERTIA_EMENDATA_2008    => [ "since_year" => 2008 ],
        self::USA_EDITION_2011                      => [ "since_year" => 2011 ],
        //the festivities applied in the '83 edition were incorporated into the Latin 2002 edition,
        //therefore we no longer need to apply them after the year 2002 since the Latin edition takes precedence
        self::ITALY_EDITION_1983                    => [ "since_year" => 1983, "until_year" => 2002 ],
        self::ITALY_EDITION_2020                    => [ "since_year" => 2020 ],
        self::NETHERLANDS_EDITION_1978              => [ "since_year" => 1979 ],
        self::CANADA_EDITION_2011                   => [ "since_year" => 2011 ],
        self::CANADA_EDITION_2016                   => [ "since_year" => 2016 ]
    ];


    /**
     * Check if a given missal_id is a valid Roman Missal enumeration constant.
     *
     * @param string $missal_id the missal_id to check
     * @return bool true if the missal_id is a valid Roman Missal enumeration constant, false otherwise
     */
    public static function isValid(string $missal_id): bool
    {
        return in_array($missal_id, self::$values);
    }

    /**
     * Checks if a given value is a Latin Missal (Editio Typica).
     *
     * @param string $value the value to check
     * @return bool true if the value is a Latin Missal, false otherwise
     */
    public static function isLatinMissal(string $value): bool
    {
        return in_array($value, self::$values) && strpos($value, "EDITIO_TYPICA_");
    }

    /**
     * Gets the name of the Roman Missal corresponding to the given Missal id.
     *
     * @param string $missal_id the id of the Roman Missal
     * @return ?string the name of the Roman Missal, or null if missal_id not valid
     */
    public static function getName(string $missal_id): ?string
    {
        return self::$names[ $missal_id ] ?? null;
    }

    /**
     * Gets the path to the JSON file containing the sanctorale data for the given Roman Missal.
     *
     * @param string $missal_id the id of the Roman Missal
     * @return string|false|null the path to the JSON file, or false if the Roman Missal does not have any JSON data, or null if missal_id not valid
     */
    public static function getSanctoraleFileName(string $missal_id): string|false|null
    {
        return isset(self::$jsonFiles[ $missal_id ]) ? self::$jsonFiles[ $missal_id ] : null;
    }

    /**
     * Gets the path to the i18n directory for the sanctorale of the given Roman Missal.
     *
     * @param string $missal_id the id of the Roman Missal
     * @return string|false|null the path to the i18n directory, or false if the Roman Missal does not have any i18n data, or null if missal_id not valid
     */
    public static function getSanctoraleI18nFilePath(string $missal_id): string|false|null
    {
        return isset(self::$i18nPath[ $missal_id ]) ? self::$i18nPath[ $missal_id ] : null;
    }

    /**
     * Gets the year limits for the given Roman Missal.
     *
     * @param string $missal_id the id of the Roman Missal
     * @return ?object an object containing the year limits for the Roman Missal,
     *   with properties named 'since_year' and 'until_year', or null if missal_id not valid
     */
    public static function getYearLimits(string $missal_id): ?object
    {
        return isset(self::$yearLimits[ $missal_id ]) ? (object) self::$yearLimits[ $missal_id ] : null;
    }

    /**
     * This method was used by the /calendars route, to add metadata about the Roman Missals,
     * however we have created the /missals route which also produces this metadata
     * using however a different approach, by globbing the data directory.
     * In order for a request to the /missals route to use this method,
     * the method would need access to the MissalsParams instance in the Paths\Missals class,
     * or this RomanMissal enum would need to store arrays of current Missal regions and years,
     * and the MissalsParams class would need to check against the RomanMissal enum
     * rather than against it's own arrays.
     *
     * @param bool $obj whether to return an array of metadata objects or an array of associative arrays
     *
     * @return array an array of metadata objects or associative arrays each describing a Roman Missal,
     *      with the following properties:
     *      - `missal_id`: the value of the enumeration constant for the Roman Missal
     *      - `name`: the name of the Roman Missal
     *      - `region`: the region for which the Roman Missal is intended
     *      - `locales`: an array of the locales for which i18n data is available
     *      - `year_limits`: an object with two properties:
     *          - `since_year`: the year since when the Roman Missal is to be used
     *          - `until_year`: the year until when the Roman Missal is to be used (null if no end year is specified)
     *      - `year_published`: the year when the Roman Missal was published
     */
    public static function produceMetadata($obj = true): array
    {
        $reflectionClass = new \ReflectionClass(static::class);
        $missal_ids      = $reflectionClass->getConstants();
        $metadata        = [];
        foreach ($missal_ids as $key => $missal_id) {
            $i18n_path = self::getSanctoraleI18nFilePath($missal_id);
            $locales   = [];
            if ($i18n_path) {
                $it = new \DirectoryIterator("glob://$i18n_path*.json");
                foreach ($it as $f) {
                    $locales[] = $f->getBasename('.json');
                }
            }
            $region = null;
            if (str_starts_with($missal_id, "EDITIO_TYPICA_")) {
                $region = "VA";
            } else {
                $region = explode("_", $missal_id)[0];
            }
            $metadata[$key] = [
                "missal_id"      => $missal_id,
                "name"           => self::getName($missal_id),
                "region"         => $region,
                //"data_path"      => self::getSanctoraleFileName($missal_id),
                //"i18n_path"      => self::getSanctoraleI18nFilePath($missal_id),
                "locales"        => $locales,
                "year_limits"    => self::$yearLimits[ $missal_id ],
                "year_published" => self::$yearLimits[ $missal_id ][ "since_year" ]
            ];
            if ($obj) {
                $metadata[$key] = json_decode(json_encode($metadata[$key]));
            }
        }
        return $metadata;
    }
}
