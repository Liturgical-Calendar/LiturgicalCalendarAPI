<?php

namespace LiturgicalCalendar\Api\Paths;

use LiturgicalCalendar\Api\Core;
use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\LiturgicalEvent;
use LiturgicalCalendar\Api\LiturgicalEventCollection;
use LiturgicalCalendar\Api\LatinUtils;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Utilities;
use LiturgicalCalendar\Api\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\CacheDuration;
use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Enum\CorpusChristi;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitCommon;
use LiturgicalCalendar\Api\Enum\LitFeastType;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\RequestContentType;
use LiturgicalCalendar\Api\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\ReturnType;
use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Enum\StatusCode;
use LiturgicalCalendar\Api\Enum\YearType;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Params\CalendarParams;

/**
 * Class Calendar
 *
 * This class is responsible for generating the Liturgical Calendar.
 *
 * @package LiturgicalCalendar\Api
 * @phpstan-import-type EventCollectionItem from LiturgicalEventCollection
 * @phpstan-type PropriumDeTemporeItem array{
 *      name: string,
 *      date: DateTime,
 *      color: string[],
 *      type: string,
 *      grade: int
 * }
 * @phpstan-type PropriumDeTemporeMap array<string, PropriumDeTemporeItem>
 * @phpstan-type MissalItem array<string, object>
 * @phpstan-type TempCalMap array<string, MissalItem>
 * @phpstan-type CatholicDioceseLatinRiteItem object{
 *      diocese_name: string,
 *      diocese_id: string,
 *      province?: string
 * }
 * @phpstan-type CatholicDioceseLatinRiteCountryItem object{
 *      country_iso: string,
 *      country_name_english: string,
 *      dioceses: CatholicDioceseLatinRiteItem[]
 * }
 * @phpstan-type CatholicDiocesesLatinRite CatholicDioceseLatinRiteCountryItem[]
 * @phpstan-type NationalCalendarLiturgicalEventItem object{
 *      liturgical_event: object,
 *      metadata: object{
 *          action: string,
 *          since_year: int,
 *          until_year?: int
 *      }
 * }
 */
class Calendar
{
    public static Core $Core;
    /** @var string[] */ private array $AllowedReturnTypes; // can only be set once, after which it will be read-only
    /** @var CatholicDiocesesLatinRite */ private array $worldDiocesesLatinRite; // can only be set once, after which it will be read-only
    private CalendarParams $CalendarParams;
    private LitCommon $LitCommon;
    private LitGrade $LitGrade;
    private \NumberFormatter $formatter;
    private \NumberFormatter $formatterFem;
    private \IntlDateFormatter $dayAndMonth;
    private \IntlDateFormatter $dayOfTheWeek;
    private LiturgicalEventCollection $Cal;
    private int $startTime;
    private int $endTime;
    private string $BaptismLordFmt;
    private string $BaptismLordMod;

    public const API_VERSION         = '4.5';
    private string $CachePath        = '';
    private string $CacheFile        = '';
    private string $CacheDuration    = '';
    private ?string $DioceseName     = null;
    private ?object $DiocesanData    = null;
    private ?object $NationalData    = null;
    private ?object $WiderRegionData = null;

    /** @var PropriumDeTemporeMap      */ private array $PropriumDeTempore = [];
    /** @var string[]                  */ private array $Messages          = [];
    /** @var TempCalMap                */ private array $tempCal           = [];


    /**
     * The following schemas for ordinal spellouts have been taken from
     * https://www.saxonica.com/html/documentation12/localization/ICU-numbers-and-dates/ICU-numbering.html
     *
     * verbose forms supported by certain languages
     *   - such as "one hundred and two" of British English
     *   - as opposed to the shorter "one hundred two" of US English
     *   should not be necessary in this project,
     *   as the weeks of the liturgical calendar don't get that far
     *   so we will only consider the basic ordinal form, not the verbose form
     *
     * plural forms supported by certain languages
     *   - such as "los primeros hombres", "las primeras mujeres" in Spanish
     *   also should not be necessary, since we refer to single weeks or days
     *   so again in these cases we can stick with the singular masculine / feminine forms
     */

    /**
     * Languages that use spellout-ordinal, without masculine or feminine specifications
     */
    private const GENERIC_SPELLOUT_ORDINAL = [
        'af', //Afrikaans
        'am', //Amharic
        'as', //Assamese
        'az', //Azerbaijani
        'bn', //Bengali
        'bo', //Tibetan
        'chr', //Cherokee,
        'de', //German : has also spellout-ordinal-n, spellout-ordinal-r, spellout-ordinal-s
              //        these seem to affect the article "the" preceding the ordinal,
              //        making it masculine, feminine, or neuter (or plural)
              //        but which is which between n-r-s? I believe r = masc, n = plural, s = neut?
              //        perhaps depends also on case: genitive, dative, etc.
        'dsb', //Lower Sorbian
        'dz', //Dzongha
        'en', //English
        'ee', //Ewe
        'es', //Esperanto
        'fi', //Finnish : also supports a myriad of other forms, too complicated to handle!
        'fil', //Filipino
        'gl', //Gallegan
        'gu', //Gujarati
        'ha', //Hausa
        'haw', //Hawaiian
        'hsb', //Upper Sorbian
        'hu', //Hungarian
        'id', //Indonesian
        'ig', //Igbo
        'ja', //Japanese
        'kk', //Kazakh
        'km', //Khmer
        'kn', //Kannada
        'kok', //Konkani
        'jy', //Kirghiz
        'lb', //Luxembourgish
        'lkt', //Lakota
        'ln', //Lingala
        'lo', //Lao
        'ml', //Malayalam
        'mn', //Mongolian
        'mr', //Marathi
        'ms', //Malay
        'my', //Burmese
        'ne', //Nepali
        'nl', //Dutch
        'om', //Oromo
        'or', //Oriva
        'pa', //Panjabi
        'ps', //Pushto
        'si', //Sinhalese
        'smn', //Inari Sami
        'sr', //Serbian
        'sw', //Swahili
        'ta', //Tamil
        'te', //Telugu
        'th', //Thai
        'to', //Tonga
        'tr', //Turkish
        'ug', //Uighur
        'ur', //Urdu
        'uz', //Uzbek
        'vi', //Vietnamese
        'wae', //Walser
        'yi', //Yiddish
        'yo', //Yoruba
        'zh', //Chinese
        'zu'  //Zulu
    ];

    /**
     * Languages that use spellout-ordinal-masculine and spellout-ordinal-feminine
     */
    private const MASC_FEM_SPELLOUT_ORDINAL = [
        'ar', //Arabic
        'ca', //Catalan
        'es', //Spanish : also supports plural forms, as well as a masculine adjective form (? spellout-ordinal-masculine-adjective)
        'fr', //French
        'he', //Hebrew
        'hi', //Hindi
        'it', //Italian
        'pt'  //Portuguese
    ];

    /**
     * Languages that use spellout-ordinal-masculine, spellout-ordinal-feminine, and spellout-ordinal-neuter
     */
    private const MASC_FEM_NEUT_SPELLOUT_ORDINAL = [
        'bg', //Bulgarian
        'be', //Belarusian
        'el', //Greek
        'hr', //Croatian
        'nb', //Norwegian Bokmål
        'ru', //Russian : also supports a myriad of other cases, too complicated to handle for now
        'sv'  //Swedish : also supports spellout-ordinal-reale ?
    ];

    //even though these do not yet support spellout-ordinal, however they do support digits-ordinal
    /*private const NO_SPELLOUT_ORDINAL               = [
        'bs', //Bosnian
        'cs', //Czech
        'cy', //Welsh
        'et', //Estonian
        'fa', //Persian
        'fo', //Faroese
        'ga', //Irish
        'hy', //Armenian
        'is', //Icelandic
        'ka', //Georgian
        'kl', //Greenlandic aka Kalaallisut
        'ko', //Korean : supports specific forms spellout-ordinal-native etc. but too complicated to handle for now
        'lt', //Lithuanian
        'lv', //Latvian
        'mk', //Macedonian
        'mt', //Maltese
        'nn', //Norwegian Nynorsk
        'pl', //Polish
        'ro', //Romanian
        'se', //Northern Sami
        'sk', //Slovak
        'sl', //Slovenian
        'sq', //Albanian
        'uq'  //Ukrainian
    ];*/

    /**
     * Whatever does spellout-ordinal-common mean?
     * ChatGPT tells us:
     * In Danish, ordinals are formed by adding "-te" (for most ordinals) or "-ende" (for some specific cases).
     * Danish ordinal formation becomes relatively regular after the first few numbers, with "-te" being the primary suffix.
     * In Danish, ordinals may change depending on gender. For example, "2nd -> second": Anden (for common gender) vs. andet (for neuter gender).
     * Examples:
     *  - "anden plads" (second place)
     *  - "andet hus" (second house) for neuter gender
     *
     * So apparently it is very similar to spellout-ordinal with a few cases using neutral gender.
     */
    private const COMMON_NEUT_SPELLOUT_ORDINAL = [ 'da' ]; //Danish


    /**
     * Constructor for the Calendar class.
     *
     * The constructor for the Calendar class has no parameters. It initializes a new instance of the Core class, and sets a cache duration of one month.
     */
    public function __construct()
    {
        $this->startTime     = hrtime(true);
        $this->CacheDuration = '_' . CacheDuration::MONTH->value . date('m');
        self::$Core          = new Core();
    }

    /**
     * Debugging function to write a string to a debug log file.
     * This function is currently commented out, but can be used for debugging purposes.
     *
     * @param string $string the string to write to the debug log
     * @return void
     */
/**
    private static function debugWrite(string $string)
    {
        file_put_contents("debug.log", $string . PHP_EOL, FILE_APPEND);
    }
*/

    /**
     * Produce an error response with HTTP status code related to the error that was encountered.
     *
     * @param int $statusCode the HTTP status code to return
     * @param string $description a short description of the error
     * @return void
     */
    public static function produceErrorResponse(int $statusCode, string $description): void
    {
        if (self::$Core->getResponseContentType() === null) {
            // set a default response content type that can be overriden by a parameter or an accept header
            self::$Core->setResponseContentType(self::$Core->getAllowedAcceptHeaders()[ 0 ]);
            self::$Core->setResponseContentTypeHeader();
        }
        header($_SERVER[ 'SERVER_PROTOCOL' ] . StatusCode::toString($statusCode), true, $statusCode);
        $message              = new \stdClass();
        $message->status      = 'ERROR';
        $message->description = $description;
        $response             = json_encode($message);
        switch (self::$Core->getResponseContentType()) {
            case AcceptHeader::YAML:
                $responseObj = json_decode($response, true);
                echo yaml_emit($responseObj, YAML_UTF8_ENCODING);
                break;
            case AcceptHeader::JSON:
                echo $response;
                break;
            case AcceptHeader::ICS:
            case AcceptHeader::XML:
            default:
                // do not emit anything, the header should be enough
        }
        die();
    }

    /**
     * Initialize the CalendarParams object from the body of the request or from the URL query parameters.
     *
     * The request body is expected to be a JSON, YAML or Form encoded object.
     * The following URL query parameters / request body properties are supported:
     * - epiphany: a string indicating whether Epiphany should be calculated on Jan 6th or on the Sunday between Jan 2nd and Jan 8th
     * - ascension: a string indicating whether Ascension should be calculated on a Thursday or on a Sunday
     * - corpus_christi: a string indicating whether Corpus Christi should be calculated on a Thursday or on a Sunday
     * - eternal_high_priest: a string indicating whether the celebration of Jesus Christ Eternal High Priest is applicable to the current requested calendar
     * - year_type: a string indicating whether the calendar should be caculated according to the Civil year or the Liturgical year
     * - year: an integer indicating the year for which the calendar should be calculated (preferably indicated in the path rather than in the request body)
     * - national_calendar: a string indicating the national calendar to produce (preferably indicated in the path rather than in the request body)
     * - diocesan_calendar: a string indicating the diocesan calendar to produce (preferably indicated in the path rather than in the request body)
     * - locale: a string representing the locale of the calendar (preferably indicated in the Accept-Language header rather than in the request body)
     * - return_type: a string indicating the format of the response (preferably indicated in the Accept header rather than in the request body)
     *
     * @return void
     */
    private function initParamsFromRequestBodyOrUrl()
    {
        // initialize an empty temporary array
        $params = [];

        // Any request with URL parameters (a query string) will populate the $_GET global associative array
        if (!empty($_GET)) {
            $params = $_GET;
        }

        // Merge any URL parameters with the data from the request body
        // Body parameters will override URL parameters
        if (self::$Core->getRequestContentType() === RequestContentType::JSON) {
            $params = array_merge(
                $params,
                (self::$Core->readJsonBody(false, true) ?? [])
            );
        }
        elseif (self::$Core->getRequestContentType() === RequestContentType::YAML) {
            $params = array_merge(
                $params,
                (self::$Core->readYamlBody(false, true) ?? [])
            );
        }
        elseif (self::$Core->getRequestContentType() === RequestContentType::FORMDATA) {
            if (!empty($_POST)) {
                $params = array_merge(
                    $params,
                    $_POST
                );
            }
        }
        $this->CalendarParams = new CalendarParams($params);
    }

    /**
     * Initialize the CalendarParams object from the path parameters of the request.
     * Expected path parameters are:
     * 1) nation or diocese or year: a string indicating whether a national or diocesan calendar is requested, or an integer indicating the year for which the General Roman calendar should be calculated
     * 2) (when 1 is a string) a string indicating the national or diocesan calendar to produce
     * 3) (when 1 is a string) an integer indicating the year for which the national or diocesan calendar should be calculated
     *
     * @param array<string|int> $requestPathParts an array of path parameters
     *
     * @return void
     */
    private function initParamsFromRequestPath(array $requestPathParts)
    {
        $numPathParts = count($requestPathParts);
        if ($numPathParts > 0) {
            $params = [];
            if ($numPathParts === 1) {
                if (
                    false === in_array(gettype($requestPathParts[0]), ['string', 'integer'])
                    || (
                        gettype($requestPathParts[0]) === 'string'
                        && (
                            false === is_numeric($requestPathParts[0])
                            || false === ctype_digit($requestPathParts[0])
                            || strlen($requestPathParts[0]) !== 4
                        )
                    )
                ) {
                    self::produceErrorResponse(StatusCode::BAD_REQUEST, 'path parameter expected to represent Year value but did not have type Integer or numeric String');
                } else {
                    $params['year'] = $requestPathParts[0];
                }
            } elseif ($numPathParts > 3) {
                $description = 'Expected at least one and at most three path parameters, instead found ' . $numPathParts;
                self::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
            } else {
                if (false === in_array($requestPathParts[0], ['nation', 'diocese'])) {
                    $description = "Invalid value `{$requestPathParts[0]}` for path parameter in position 1,"
                        . ' the first parameter should have a value of either `nation` or `diocese`';
                    self::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
                } else {
                    if ($requestPathParts[0] === 'nation') {
                        $params['national_calendar'] = $requestPathParts[1];
                    } elseif ($requestPathParts[0] === 'diocese') {
                        $params['diocesan_calendar'] = $requestPathParts[1];
                    }
                }
                if ($numPathParts === 3) {
                    if (
                        false === in_array(gettype($requestPathParts[2]), ['string', 'integer'])
                        || (
                            gettype($requestPathParts[2]) === 'string'
                            && (
                                false === is_numeric($requestPathParts[2])
                                || false === ctype_digit($requestPathParts[2])
                                || strlen($requestPathParts[2]) !== 4
                            )
                        )
                    ) {
                        self::produceErrorResponse(StatusCode::BAD_REQUEST, 'path parameter expected to represent Year value but did not have type Integer or numeric String');
                    } else {
                        $params['year'] = $requestPathParts[2];
                    }
                }
            }
            if (count($params)) {
                $this->CalendarParams->setParams($params);
            }
        }
    }

    /**
     * Sets the Response Content Type header based on whether the request provided a *return_type* parameter
     * and whether the value of that parameter is in the list of content types that the API can produce.
     * If the request did not provide a *return_type* parameter, it will check for the presence of an *Accept* header,
     * and if it is present and in the list of content types that the API can produce, it will use that.
     * If the request did not provide a *return_type* parameter and there is no *Accept* header,
     * or if the *Accept* header is not in the list of content types that the API can produce, it will use the first
     * value in the list of content types that the API can produce.
     * If the request did provide a *return_type* parameter, and the value of that parameter is not in the list of content types
     * that the API can produce, it will return a *406 Not Acceptable* error.
     * If the request did provide a *return_type* parameter, and the value of that parameter is in the list of content types
     * that the API can produce, it will use that.
     */
    private function initReturnType(): void
    {
        if ($this->CalendarParams->ReturnType !== null) {
            if (false === in_array($this->CalendarParams->ReturnType, $this->AllowedReturnTypes)) {
                $description = 'You are requesting a content type which this API cannot produce. Allowed content types are '
                    . implode(' and ', $this->AllowedReturnTypes)
                    . ', but you have issued a parameter requesting a Content Type of '
                    . strtoupper($this->CalendarParams->ReturnType);
                self::produceErrorResponse(StatusCode::NOT_ACCEPTABLE, $description);
            }
            self::$Core->setResponseContentType(
                self::$Core->getAllowedAcceptHeaders()[ array_search($this->CalendarParams->ReturnType, $this->AllowedReturnTypes) ]
            );
        } else {
            if (self::$Core->hasAcceptHeader()) {
                if (self::$Core->isAllowedAcceptHeader()) {
                    $this->CalendarParams->ReturnType = $this->AllowedReturnTypes[ self::$Core->getIdxAcceptHeaderInAllowed() ];
                    self::$Core->setResponseContentType(self::$Core->getAcceptHeader());
                } else {
                    //Requests from browser windows using the address bar will probably have an Accept header of text/html
                    //In order to not be too drastic, let's treat text/html as though it were application/json
                    $acceptHeaders = explode(',', self::$Core->getAcceptHeader());
                    if (in_array('text/html', $acceptHeaders) || in_array('text/plain', $acceptHeaders) || in_array('*/*', $acceptHeaders)) {
                        $this->CalendarParams->ReturnType = ReturnType::JSON;
                        self::$Core->setResponseContentType(AcceptHeader::JSON);
                    } else {
                        $description = 'You are requesting a content type which this API cannot produce. Allowed Accept headers are '
                            . implode(' and ', self::$Core->getAllowedAcceptHeaders())
                            . ', but you have issued an request with an Accept header of '
                            . self::$Core->getAcceptHeader();
                        self::produceErrorResponse(StatusCode::NOT_ACCEPTABLE, $description);
                    }
                }
            } else {
                $this->CalendarParams->ReturnType = $this->AllowedReturnTypes[ 0 ];
                self::$Core->setResponseContentType(self::$Core->getAllowedAcceptHeaders()[ 0 ]);
            }
        }
    }

    /**
     * Initialize the CalendarParams object from the request body and URL query parameters
     * and the request path, and set the return type of the response.
     *
     * @param array<string|int> $requestPathParts the parts of the request path
     *
     * @return void
     */
    private function initParameterData(array $requestPathParts = []): void
    {
        $this->initParamsFromRequestBodyOrUrl();
        $this->initParamsFromRequestPath($requestPathParts);
        $this->initReturnType();
    }

    /**
     * Updates the CalendarParams object based on the settings defined in the NationalData object for the
     * NationalCalendar that has been requested. If the NationalCalendar is 'VA', it will use the default
     * settings for the Vatican, otherwise it will use the settings defined in the NationalData object.
     *
     * @return void
     */
    private function updateSettingsBasedOnNationalCalendar(): void
    {
        if ($this->CalendarParams->NationalCalendar !== null) {
            if ($this->CalendarParams->NationalCalendar === 'VA') {
                $this->CalendarParams->Epiphany      = Epiphany::JAN6;
                $this->CalendarParams->Ascension     = Ascension::THURSDAY;
                $this->CalendarParams->CorpusChristi = CorpusChristi::THURSDAY;
                $this->CalendarParams->Locale        = LitLocale::LATIN;
            } else {
                if (property_exists($this->NationalData, 'settings')) {
                    // phpcs:disable Generic.Formatting.MultipleStatementAlignment
                    if (
                        property_exists($this->NationalData->settings, 'epiphany')
                        && Epiphany::isValid($this->NationalData->settings->epiphany)
                    ) {
                        $this->CalendarParams->Epiphany          = $this->NationalData->settings->epiphany;
                    }
                    if (
                        property_exists($this->NationalData->settings, 'ascension')
                        && Ascension::isValid($this->NationalData->settings->ascension)
                    ) {
                        $this->CalendarParams->Ascension         = $this->NationalData->settings->ascension;
                    }
                    if (
                        property_exists($this->NationalData->settings, 'corpus_christi')
                        && CorpusChristi::isValid($this->NationalData->settings->corpus_christi)
                    ) {
                        $this->CalendarParams->CorpusChristi     = $this->NationalData->settings->corpus_christi;
                    }
                    if (
                        property_exists($this->NationalData->settings, 'eternal_high_priest')
                        && is_bool($this->NationalData->settings->eternal_high_priest)
                    ) {
                        $this->CalendarParams->EternalHighPriest = $this->NationalData->settings->eternal_high_priest;
                    }
                    // phpcs:enable Generic.Formatting.MultipleStatementAlignment
                }
            }
        }
    }

    /**
     * If a Diocesan calendar is specified, we need to check if any of the settings for Epiphany, Ascension, Corpus Christi
     * have been overridden. If so, we update the CalendarParams object with the new values.
     *
     * @return void
     */
    private function updateSettingsBasedOnDiocesanCalendar(): void
    {
        if ($this->CalendarParams->DiocesanCalendar !== null && $this->DiocesanData !== null) {
            if (property_exists($this->DiocesanData, 'settings')) {
                foreach ($this->DiocesanData->settings as $key => $value) {
                    // phpcs:disable Generic.Formatting.MultipleStatementAlignment
                    switch ($key) {
                        case 'epiphany':
                            if (Epiphany::isValid($value)) {
                                $this->CalendarParams->Epiphany        = $value;
                            }
                            break;
                        case 'ascension':
                            if (Ascension::isValid($value)) {
                                $this->CalendarParams->Ascension       = $value;
                            }
                            break;
                        case 'corpus_christi':
                            if (CorpusChristi::isValid($value)) {
                                $this->CalendarParams->CorpusChristi   = $value;
                            }
                            break;
                    }
                    // phpcs:enable Generic.Formatting.MultipleStatementAlignment
                }
            }
            if (
                property_exists($this->DiocesanData->metadata, 'locales')
                && LitLocale::areValid($this->DiocesanData->metadata->locales)
            ) {
                // phpcs:disable Generic.Formatting.MultipleStatementAlignment
                if (count($this->DiocesanData->metadata->locales) === 1) {
                    $this->CalendarParams->Locale      = $this->DiocesanData->metadata->locales[0];
                } else {
                    // If multiple locales are available for the diocesan calendar,
                    // the desired locale should be set in the Accept-Language header.
                    // We should however check that this is an available locale for the current Diocesan Calendar,
                    // and if not use the first valid value.
                    if (false === in_array($this->CalendarParams->Locale, $this->DiocesanData->metadata->locales)) {
                        $this->CalendarParams->Locale  = $this->DiocesanData->metadata->locales[0];
                    }
                }
                // phpcs:enable Generic.Formatting.MultipleStatementAlignment
            }
        }
    }

    /**
     * Takes a diocese ID and returns the corresponding diocese name.
     * If the diocese ID is not found, returns null.
     *
     * @param string $id The diocese ID.
     * @return array{diocese_name: string, nation: string}|null The diocese name and nation, or null if not found.
     */
    private function dioceseIdToName(string $id): ?array
    {
        if (empty($this->worldDiocesesLatinRite)) {
            $worldDiocesesFile            = JsonData::FOLDER . '/world_dioceses.json';
            $this->worldDiocesesLatinRite = json_decode(
                file_get_contents($worldDiocesesFile)
            )->catholic_dioceses_latin_rite;
        }
        $dioceseName = null;
        $nation      = null;
        // Search for the diocese by its ID in the worldDioceseLatinRite data
        foreach ($this->worldDiocesesLatinRite as $country) {
            foreach ($country->dioceses as $diocese) {
                if ($diocese->diocese_id === $id) {
                    $dioceseName = $diocese->diocese_name;
                    if (property_exists($diocese, 'province')) {
                        $dioceseName .= ' (' . $diocese->province . ')';
                    }
                    $nation = $country->country_iso;
                    break 2; // Break out of both loops
                }
            }
        }
        return $dioceseName !== null && $nation !== null ? [ 'diocese_name' => $dioceseName, 'nation' => $nation] : null;
    }

    /**
     * Loads the JSON data for the specified Diocesan calendar.
     *
     * @return void
     */
    private function loadDiocesanCalendarData(): void
    {
        if ($this->CalendarParams->DiocesanCalendar !== null) {
            $idTransform = Calendar::dioceseIdToName($this->CalendarParams->DiocesanCalendar);
            if (null === $idTransform) {
                $this->Messages[] = sprintf(
                    _('The name of the diocese could not be derived from the diocese ID "%s".'),
                    $this->CalendarParams->DiocesanCalendar
                );
            } else {
                ['diocese_name' => $dioceseName, 'nation' => $nation] = $idTransform;
                $this->DioceseName                                    = $dioceseName;
                $this->CalendarParams->NationalCalendar               = strtoupper($nation);
                $diocesanDataFile                                     = strtr(
                    JsonData::DIOCESAN_CALENDARS_FILE,
                    [
                        '{nation}'       => $this->CalendarParams->NationalCalendar,
                        '{diocese}'      => $this->CalendarParams->DiocesanCalendar,
                        '{diocese_name}' => $dioceseName
                    ]
                );
                if (file_exists($diocesanDataFile)) {
                    $this->DiocesanData = json_decode(file_get_contents($diocesanDataFile));
                    if (
                        property_exists($this->DiocesanData->metadata, 'locales')
                        && LitLocale::areValid($this->DiocesanData->metadata->locales)
                    ) {
                        // phpcs:disable Generic.Formatting.MultipleStatementAlignment
                        if (count($this->DiocesanData->metadata->locales) === 1) {
                            $this->CalendarParams->Locale      = $this->DiocesanData->metadata->locales[0];
                        } else {
                            // If multiple locales are available for the national calendar,
                            // the desired locale should be set in the Accept-Language header.
                            // We should however check that this is an available locale for the current Diocesan Calendar,
                            // and if not use the first valid value.
                            if (false === in_array($this->CalendarParams->Locale, $this->DiocesanData->metadata->locales)) {
                                $this->CalendarParams->Locale  = $this->DiocesanData->metadata->locales[0];
                            }
                        }
                        // phpcs:enable Generic.Formatting.MultipleStatementAlignment
                    }
                } else {
                    $this->Messages[] = sprintf(
                        _('The Diocesan calendar "%s" was not found in the index file.'),
                        $this->CalendarParams->DiocesanCalendar
                    );
                }
            }
        }
    }

    /**
     * Determines if a cache file is available for the current request.
     *
     * This function will determine if a cache file is available for the current request.
     * This is done by checking if a file exists in the engine cache directory with the
     * name of the md5 hash of the serialized CalendarParams object, followed by the
     * CacheDuration (in minutes) and the ReturnType of the request.
     *
     * @return bool true if a cache file is available, false otherwise
     */
    private function cacheFileIsAvailable(): bool
    {
        //LitCommon::$HASH_REQUEST = $paramsHash;
        $paramsHash              = md5(serialize($this->CalendarParams));
        Utilities::$HASH_REQUEST = $paramsHash;
        $cacheFileName           = $paramsHash . $this->CacheDuration . '.' . strtolower($this->CalendarParams->ReturnType);
        $this->CacheFile         = $this->CachePath . $cacheFileName;
        return file_exists($this->CacheFile);
    }

    /**
     * Creates the IntlDateFormatter objects used to format dates and ordinals in output.
     *
     * This method creates the objects used to format dates and ordinals in the output of the
     * Liturgical Calendar API. The objects are configured according to the locale specified
     * in the CalendarParams object passed to the constructor of this class.
     *
     * @return void
     */
    private function createFormatters(): void
    {
        $baseLocale         = LitLocale::$PRIMARY_LANGUAGE;
        $this->dayAndMonth  = \IntlDateFormatter::create(
            $baseLocale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            'd MMMM'
        );
        $this->dayOfTheWeek = \IntlDateFormatter::create(
            $baseLocale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            'EEEE'
        );
        $this->formatter    = new \NumberFormatter(
            $baseLocale,
            \NumberFormatter::SPELLOUT
        );
        //follow rules as indicated here:
        // https://www.saxonica.com/html/documentation11/extensibility/localizing/ICU-numbering-dates/ICU-numbering.html
        if (in_array($baseLocale, self::GENERIC_SPELLOUT_ORDINAL)) {
            $this->formatter->setTextAttribute(\NumberFormatter::DEFAULT_RULESET, '%spellout-ordinal');
            //feminine version will be the same as masculine
            $this->formatterFem = $this->formatter;
        } elseif (in_array($baseLocale, self::MASC_FEM_SPELLOUT_ORDINAL) || in_array($baseLocale, self::MASC_FEM_NEUT_SPELLOUT_ORDINAL)) {
            $this->formatter->setTextAttribute(\NumberFormatter::DEFAULT_RULESET, '%spellout-ordinal-masculine');
            $this->formatterFem = new \NumberFormatter($baseLocale, \NumberFormatter::SPELLOUT);
            $this->formatterFem->setTextAttribute(\NumberFormatter::DEFAULT_RULESET, '%spellout-ordinal-feminine');
        } elseif (in_array($baseLocale, self::COMMON_NEUT_SPELLOUT_ORDINAL)) {
            $this->formatter->setTextAttribute(\NumberFormatter::DEFAULT_RULESET, '%spellout-ordinal-common');
            //feminine version will be the same as masculine
            $this->formatterFem = $this->formatter;
        } else {
            $this->formatter = new \NumberFormatter($baseLocale, \NumberFormatter::ORDINAL);
            //feminine version will be the same as masculine
            $this->formatterFem = $this->formatter;
        }
    }

    /**
     * This function will produce a 400 Bad Request error if the year requested
     * is earlier than 1970, the year in which the Prima Editio
     * Typica of the Roman Missal and the General Norms were promulgated with
     * the Motu Proprio "Mysterii Paschali".
     *
     * @see https://w2.vatican.va/content/paul-vi/en/motu_proprio/documents/hf_p-vi_motu-proprio_19690214_mysterii-paschalis.html
     *
     * @return void
     */
    private function dieIfBeforeMinYear(): void
    {
        if ($this->CalendarParams->Year < 1970) {
            $message = sprintf(
                _('Only years from 1970 and after are supported. You tried requesting the year %d.'),
                $this->CalendarParams->Year
            );
            self::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
        }
    }

    /**
     * Loads localization data stored in JSON format from a file in the
     * `{JsonData::MISSALS_FOLDER}/propriumdetempore/i18n` directory, named according to the locale
     * specified in LitLocale::$PRIMARY_LANGUAGE.
     *
     * If the file does not exist, or if there is an error decoding the
     * JSON data, a 503 Service Unavailable error is thrown.
     *
     * @return array<string, string>|null The loaded data, or null if there was an error.
     */
    private function loadPropriumDeTemporeI18nData(): ?array
    {
        $locale                    = LitLocale::$PRIMARY_LANGUAGE;
        $propriumDeTemporeI18nFile = strtr(
            JsonData::MISSALS_I18N_FILE,
            ['{missal_folder}' => 'propriumdetempore', '{locale}' => $locale]
        );
        if (file_exists($propriumDeTemporeI18nFile)) {
            $rawData               = file_get_contents($propriumDeTemporeI18nFile);
            $PropriumDeTemporeI18n = json_decode($rawData, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $PropriumDeTemporeI18n;
            } else {
                $message = sprintf(
                    /**translators: Temporale refers to the Proprium de Tempore */
                    _('There was an error trying to decode localized JSON data for the Temporale: %s'),
                    json_last_error_msg()
                );
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
            }
        } else {
            /**translators: Temporale refers to the Proprium de Tempore */
            $message = _('There was an error trying to retrieve localized data for the Temporale.');
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
        }
        return null;
    }

    /**
     * Retrieve Higher Ranking Solemnities from Proprium de Tempore
     */
    private function loadPropriumDeTemporeData(): void
    {
        $propriumDeTemporeFile = strtr(
            JsonData::MISSALS_FILE,
            ['{missal_folder}' => 'propriumdetempore']
        );
        if (file_exists($propriumDeTemporeFile)) {
            $rawData           = file_get_contents($propriumDeTemporeFile);
            $PropriumDeTempore = json_decode($rawData, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $PropriumDeTemporeI18n = $this->loadPropriumDeTemporeI18nData();
                if (null !== $PropriumDeTemporeI18n) {
                    foreach ($PropriumDeTempore as $event) {
                        $key = $event['event_key'];
                        unset($event['event_key']);
                        $this->PropriumDeTempore[ $key ] = [
                            'name' => $PropriumDeTemporeI18n[ $key ],
                            ...$event
                        ];
                    }
                }
            } else {
                $message = sprintf(
                    /**translators: Temporale refers to the Proprium de Tempore */
                    _('There was an error trying to decode JSON data for the Temporale: %s'),
                    json_last_error_msg()
                );
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
            }
        } else {
            /**translators: Temporale refers to the Proprium de Tempore */
            $message = _('There was an error trying to retrieve data for the Temporale.');
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
        }
    }

    /**
     * Loads the Proprium de Sanctis (Sanctorale) data from JSON files for the given
     * Roman Missal.
     *
     * Will produce an Http Status code of 503 Service Unavailable for the API response if it encounters an error
     * while parsing the JSON data.
     *
     * @param string $missal The name of the Roman Missal to load the data for.
     */
    private function loadPropriumDeSanctisData(string $missal): void
    {
        $propriumdesanctisFile     = RomanMissal::getSanctoraleFileName($missal);
        $propriumdesanctisI18nPath = RomanMissal::getSanctoraleI18nFilePath($missal);
        $i18nData                  = null;

        // only produce an error if a translation file is expected but not found
        if (
            str_starts_with($missal, 'EDITIO_TYPICA_')
            && (
                false === $propriumdesanctisI18nPath
                || false === file_exists($propriumdesanctisI18nPath)
            )
        ) {
            $message = sprintf(
                /**translators: name of the Roman Missal */
                _('Translation data for the sanctorale from %s could not be found.'),
                RomanMissal::getName($missal)
            );
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
        }

        if ($propriumdesanctisI18nPath !== false) {
            $locale                    = LitLocale::$PRIMARY_LANGUAGE;
            $propriumdesanctisI18nFile = $propriumdesanctisI18nPath . $locale . '.json';
            if (file_exists($propriumdesanctisI18nFile)) {
                $rawData  = file_get_contents($propriumdesanctisI18nFile);
                $i18nData = json_decode($rawData, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $message = sprintf(
                        /**translators:
                         *  do not translate 'JSON';
                         * 'Sanctorale' refers to the Proprium de Sanctis;
                         * 1: name of the Roman Missal
                         * 2: error message
                         */
                        _('There was an error trying to decode JSON localization data for the Sanctorale for the Missal %1$s: %2$s'),
                        RomanMissal::getName($missal),
                        json_last_error_msg()
                    );
                    self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
                }
            } else {
                $message = sprintf(
                    /**translators: Sanctorale refers to the Proprium de Sanctis; %s = name of the Roman Missal */
                    _('Data for the Sanctorale from %s could not be found.'),
                    RomanMissal::getName($missal)
                );
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
            }
        }

        if (file_exists($propriumdesanctisFile)) {
            $rawData           = file_get_contents($propriumdesanctisFile);
            $PropriumDeSanctis = json_decode($rawData);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->tempCal[ $missal ] = [];
                foreach ($PropriumDeSanctis as $row) {
                    if ($propriumdesanctisI18nPath !== false && $i18nData !== null) {
                        $row->name = $i18nData[ $row->event_key ];
                    }
                    $this->tempCal[ $missal ][ $row->event_key ] = $row;
                }
            } else {
                $message = sprintf(
                    /**translators: Sanctorale refers to the Proprium de Sanctis;
                     * 1: name of the Roman Missal
                     * 2: error message
                     */
                    _('There was an error trying to decode JSON data for the Sanctorale for the Missal %1$s: %2$s.'),
                    RomanMissal::getName($missal),
                    json_last_error_msg()
                );
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
            }
        } else {
            /**translators: Sanctorale refers to Proprium de Sanctis */
            $message = _('Could not find the Sanctorale data');
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
        }
    }

    /**
     * Loads the Memorials based on Decrees of the Congregation for Divine Worship
     * data from JSON files.
     *
     * Will produce an Http Status code of 503 Service Unavailable for the API
     * response if it encounters an error while parsing the JSON data.
     */
    private function loadMemorialsFromDecreesData(): void
    {
        $locale          = LitLocale::$PRIMARY_LANGUAGE;
        $decreesI18nFile = strtr(
            JsonData::DECREES_I18N_FILE,
            ['{locale}' => $locale]
        );
        $NAME            = null;

        if (file_exists($decreesI18nFile)) {
            $rawData = file_get_contents($decreesI18nFile);
            $NAME    = json_decode($rawData, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $message = sprintf(
                    _('There was an error trying to decode translation data for Memorials based on Decrees of the Congregation for Divine Worship: %s'),
                    json_last_error_msg()
                );
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
            }
        } else {
            $message = _('Could not find translation data for Memorials based on Decrees of the Congregation for Divine Worship');
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
        }

        if (file_exists(JsonData::DECREES_FILE)) {
            $decreesRawData = file_get_contents(JsonData::DECREES_FILE);
            $decrees        = json_decode($decreesRawData);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $message = sprintf(
                    _('There was an error trying to decode JSON data for Memorials based on Decrees of the Congregation for Divine Worship: %s'),
                    json_last_error_msg()
                );
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
            } else {
                $this->tempCal[ 'MEMORIALS_FROM_DECREES' ] = [];
                foreach ($decrees as $decree) {
                    if (
                        (
                            $decree->metadata->action === 'createNew'
                            ||
                            ($decree->metadata->action === 'setProperty' && $decree->metadata->property === 'name' )
                        )
                        && $NAME !== null
                    ) {
                        $decree->liturgical_event->name = $NAME[ $decree->liturgical_event->event_key ];
                    }
                    $this->tempCal[ 'MEMORIALS_FROM_DECREES' ][ $decree->liturgical_event->event_key ] = $decree;
                }
            }
        }
    }

    /**
     * Creates a LiturgicalEvent object from an entry in the Proprium de Tempore and adds it to the calendar
     * @param string $key The key of the LiturgicalEvent in the Proprium de Tempore
     * @return LiturgicalEvent The new LiturgicalEvent object
     */
    private function createPropriumDeTemporeLiturgicalEventByKey(?string $key = null): LiturgicalEvent
    {
        if (null === $key || false === array_key_exists($key, $this->PropriumDeTempore)) {
            die("createPropriumDeTemporeLiturgicalEventByKey requires a key from the Proprium de Tempore, instead got $key");
        }
        $event = new LiturgicalEvent(
            $this->PropriumDeTempore[ $key ][ 'name' ],
            $this->PropriumDeTempore[ $key ][ 'date' ],
            $this->PropriumDeTempore[ $key ][ 'color' ],
            $this->PropriumDeTempore[ $key ][ 'type' ],
            $this->PropriumDeTempore[ $key ][ 'grade' ]
        );
        $this->Cal->addLiturgicalEvent($key, $event);
        return $event;
    }

    /**
     * Calculates the dates for Holy Thursday, Good Friday, Easter Vigil and Easter Sunday
     * and creates the corresponding LiturgicalEvents in the calendar
     */
    private function calculateEasterTriduum(): void
    {
        $this->PropriumDeTempore[ 'HolyThurs' ][ 'date' ]   = Utilities::calcGregEaster($this->CalendarParams->Year)->sub(new \DateInterval('P3D'));
        $this->PropriumDeTempore[ 'GoodFri' ][ 'date' ]     = Utilities::calcGregEaster($this->CalendarParams->Year)->sub(new \DateInterval('P2D'));
        $this->PropriumDeTempore[ 'EasterVigil' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)->sub(new \DateInterval('P1D'));
        $this->PropriumDeTempore[ 'Easter' ][ 'date' ]      = Utilities::calcGregEaster($this->CalendarParams->Year);
        $this->createPropriumDeTemporeLiturgicalEventByKey('HolyThurs');
        $this->createPropriumDeTemporeLiturgicalEventByKey('GoodFri');
        $this->createPropriumDeTemporeLiturgicalEventByKey('EasterVigil');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter');
    }


    /**
     * Calculates the dates for Christmas and Epiphany and creates the corresponding LiturgicalEvents in the calendar
     *
     * Christmas is a fixed date, but Epiphany depends on the calendar settings (JAN6 or SUNDAY_JAN2_JAN8).
     *
     * @return void
     */
    private function calculateChristmasEpiphany(): void
    {
        // Calculate Christmas
        $this->PropriumDeTempore[ 'Christmas' ][ 'date' ] = DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Christmas');

        // Calculate Epiphany (and the "Second Sunday of Christmas" if applicable)
        switch ($this->CalendarParams->Epiphany) {
            case Epiphany::JAN6:
                $this->PropriumDeTempore[ 'Epiphany' ][ 'date' ] = DateTime::createFromFormat('!j-n-Y', '6-1-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
                $this->createPropriumDeTemporeLiturgicalEventByKey('Epiphany');

                // if a Sunday falls between Jan. 2 and Jan. 5, it is called the "Second Sunday of Christmas"
                for ($i = 2; $i < 6; $i++) {
                    $dateTime = DateTime::createFromFormat(
                        '!j-n-Y',
                        $i . '-1-' . $this->CalendarParams->Year,
                        new \DateTimeZone('UTC')
                    );
                    if (self::dateIsSunday($dateTime)) {
                        $this->PropriumDeTempore[ 'Christmas2' ][ 'date' ] = $dateTime;
                        $this->createPropriumDeTemporeLiturgicalEventByKey('Christmas2');
                        break;
                    }
                }
                break;
            case Epiphany::SUNDAY_JAN2_JAN8:
                //If January 2nd is a Sunday, then go with Jan 2nd
                $dateTime = DateTime::createFromFormat(
                    '!j-n-Y',
                    '2-1-' . $this->CalendarParams->Year,
                    new \DateTimeZone('UTC')
                );
                if (self::dateIsSunday($dateTime)) {
                    $this->PropriumDeTempore[ 'Epiphany' ][ 'date' ] = $dateTime;
                    $this->createPropriumDeTemporeLiturgicalEventByKey('Epiphany');
                } else {
                    //otherwise find the Sunday following Jan 2nd
                    $SundayOfEpiphany                                = $dateTime->modify('next Sunday');
                    $this->PropriumDeTempore[ 'Epiphany' ][ 'date' ] = $SundayOfEpiphany;
                    $this->createPropriumDeTemporeLiturgicalEventByKey('Epiphany');
                }
                break;
        }
    }

    /**
     * Weekdays from Jan. 2 to the day before Epiphany are called "*day before Epiphany" (in which calendar? England?)
     * Actually in Latin they are "Feria II temporis Nativitatis",
     *  in English "Monday - Christmas Weekday",
     *  in Italian "Feria propria del 3 gennaio" etc.
     *
     * We have to make sure to skip any Sunday that might fall between Jan. 2 and Epiphany when Epiphany is on Jan 6
     *
     * @return void
     */
    private function calculateChristmasWeekdaysThroughEpiphany(): void
    {
        $nth           = 0;
        $Epiphany      = $this->Cal->getLiturgicalEvent('Epiphany');
        $DayOfEpiphany = (int)$Epiphany->date->format('j');
        for ($i = 2; $i < $DayOfEpiphany; $i++) {
            $dateTime = DateTime::createFromFormat(
                '!j-n-Y',
                $i . '-1-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            );
            if (false === self::dateIsSunday($dateTime) && $this->Cal->notInSolemnitiesFeastsOrMemorials($dateTime)) {
                $nth++;
                $locale       = LitLocale::$PRIMARY_LANGUAGE;
                $dayOfTheWeek = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? LatinUtils::LATIN_DAYOFTHEWEEK[ $dateTime->format('w') ]
                    : ( $locale === 'it'
                        ? $this->dayAndMonth->format($dateTime->format('U'))
                        : ucfirst($this->dayOfTheWeek->format($dateTime->format('U')))
                    );
                $name         = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? sprintf('%s temporis Nativitatis', $dayOfTheWeek)
                    : ( $locale === 'it'
                        ? sprintf('Feria propria del %s', $dayOfTheWeek)
                        : sprintf(
                            /**translators: days before Epiphany (not useful in Italian!) */
                            _('%s - Christmas Weekday'),
                            ucfirst($dayOfTheWeek)
                        )
                    );
                $litEvent = new LiturgicalEvent(
                    $name,
                    $dateTime,
                    LitColor::WHITE,
                    LitFeastType::MOBILE
                );
                $this->Cal->addLiturgicalEvent('DayBeforeEpiphany' . $nth, $litEvent);
            }
        }
    }

    /**
     * Weekdays after Epiphany until the Baptism of the Lord are called "*day after Epiphany" (in which calendar? England?)
     * Actually in Latin they are still "Feria II temporis Nativitatis",
     *   in English "Monday - Christmas Weekday",
     *   in Italian "Feria propria del 3 gennaio" etc.
     *
     * @return void
     */
    private function calculateChristmasWeekdaysAfterEpiphany(): void
    {
        $Epiphany         = $this->Cal->getLiturgicalEvent('Epiphany');
        $DayOfEpiphany    = (int)$Epiphany->date->format('j');
        $BaptismLord      = $this->Cal->getLiturgicalEvent('BaptismLord');
        $DayOfBaptismLord = (int)$BaptismLord->date->format('j');
        $nth              = 0;
        for ($i = $DayOfEpiphany + 1; $i < $DayOfBaptismLord; $i++) {
            $nth++;
            $dateTime = DateTime::createFromFormat(
                '!j-n-Y',
                $i . '-1-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            );
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($dateTime)) {
                $locale       = LitLocale::$PRIMARY_LANGUAGE;
                $dayOfTheWeek = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? LatinUtils::LATIN_DAYOFTHEWEEK[ $dateTime->format('w') ]
                    : ( $locale === 'it'
                        ? $this->dayAndMonth->format($dateTime->format('U'))
                        : ucfirst($this->dayOfTheWeek->format($dateTime->format('U')))
                    );
                $name         = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? sprintf('%s temporis Nativitatis', $dayOfTheWeek)
                    : ( $locale === 'it'
                        ? sprintf('Feria propria del %s', $dayOfTheWeek)
                        : sprintf(
                            /**translators: days after Epiphany when Epiphany falls on Jan 6 (not useful in Italian!) */
                            _('%s - Christmas Weekday'),
                            ucfirst($dayOfTheWeek)
                        )
                    );
                $litEvent = new LiturgicalEvent(
                    $name,
                    $dateTime,
                    LitColor::WHITE,
                    LitFeastType::MOBILE
                );
                $this->Cal->addLiturgicalEvent('DayAfterEpiphany' . $nth, $litEvent);
            }
        }
    }

    /**
     * Calculates the dates for Ascension and Pentecost and creates the corresponding LiturgicalEvents in the calendar
     *
     * Ascension can be either Thursday or Sunday, depending on the calendar settings,
     * so call either calculateAscensionThursday or calculateAscensionSunday
     *
     * Pentecost is fixed date, so just create a LiturgicalEvent
     *
     * @return void
     */
    private function calculateAscensionPentecost(): void
    {
        if ($this->CalendarParams->Ascension === Ascension::THURSDAY) {
            $this->PropriumDeTempore[ 'Ascension' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P39D'));
            $this->createPropriumDeTemporeLiturgicalEventByKey('Ascension');
            $this->PropriumDeTempore[ 'Easter7' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
                ->add(new \DateInterval('P' . ( 7 * 6 ) . 'D'));
            $this->createPropriumDeTemporeLiturgicalEventByKey('Easter7');
        } elseif ($this->CalendarParams->Ascension === Ascension::SUNDAY) {
            $this->PropriumDeTempore[ 'Ascension' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
                ->add(new \DateInterval('P' . ( 7 * 6 ) . 'D'));
            $this->createPropriumDeTemporeLiturgicalEventByKey('Ascension');
        }

        $this->PropriumDeTempore[ 'Pentecost' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 7 ) . 'D'));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Pentecost');
    }

    /**
     * Calculates the dates for Sundays of Advent, Lent, Easter, Ordinary Time, and special Sundays like Palm Sunday, Corpus Christi, and Trinity Sunday
     * and creates the corresponding LiturgicalEvents in the calendar
     *
     * @return void
     */
    private function calculateSundaysMajorSeasons(): void
    {
        //We calculate Sundays of Advent based on Christmas
        $jny              = '!j-n-Y';
        $christmasDateStr = '25-12-' . $this->CalendarParams->Year;

        $this->PropriumDeTempore[ 'Advent1' ][ 'date' ] = DateTime::createFromFormat($jny, $christmasDateStr, new \DateTimeZone('UTC'))
            ->modify('last Sunday')->sub(new \DateInterval('P' . ( 3 * 7 ) . 'D'));
        $this->PropriumDeTempore[ 'Advent2' ][ 'date' ] = DateTime::createFromFormat($jny, $christmasDateStr, new \DateTimeZone('UTC'))
            ->modify('last Sunday')->sub(new \DateInterval('P' . ( 2 * 7 ) . 'D'));
        $this->PropriumDeTempore[ 'Advent3' ][ 'date' ] = DateTime::createFromFormat($jny, $christmasDateStr, new \DateTimeZone('UTC'))
            ->modify('last Sunday')->sub(new \DateInterval('P7D'));
        $this->PropriumDeTempore[ 'Advent4' ][ 'date' ] = DateTime::createFromFormat($jny, $christmasDateStr, new \DateTimeZone('UTC'))
            ->modify('last Sunday');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Advent1');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Advent2');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Advent3');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Advent4');

        //We calculate Sundays of Lent, Palm Sunday, Sundays of Easter, Trinity Sunday and Corpus Christi based on Easter
        $this->PropriumDeTempore[ 'Lent1' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P' . ( 6 * 7 ) . 'D'));
        $this->PropriumDeTempore[ 'Lent2' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P' . ( 5 * 7 ) . 'D'));
        $this->PropriumDeTempore[ 'Lent3' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P' . ( 4 * 7 ) . 'D'));
        $this->PropriumDeTempore[ 'Lent4' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P' . ( 3 * 7 ) . 'D'));
        $this->PropriumDeTempore[ 'Lent5' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P' . ( 2 * 7 ) . 'D'));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Lent1');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Lent2');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Lent3');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Lent4');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Lent5');
        $this->PropriumDeTempore[ 'PalmSun' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P7D'));
        $this->PropriumDeTempore[ 'Easter2' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P7D'));
        $this->PropriumDeTempore[ 'Easter3' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 2 ) . 'D'));
        $this->PropriumDeTempore[ 'Easter4' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 3 ) . 'D'));
        $this->PropriumDeTempore[ 'Easter5' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 4 ) . 'D'));
        $this->PropriumDeTempore[ 'Easter6' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 5 ) . 'D'));
        $this->PropriumDeTempore[ 'Trinity' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 8 ) . 'D'));
        $this->createPropriumDeTemporeLiturgicalEventByKey('PalmSun');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter2');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter3');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter4');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter5');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter6');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Trinity');
        if ($this->CalendarParams->CorpusChristi === CorpusChristi::THURSDAY) {
            $this->PropriumDeTempore[ 'CorpusChristi' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
                ->add(new \DateInterval('P' . ( 7 * 8 + 4 ) . 'D'));
            $this->createPropriumDeTemporeLiturgicalEventByKey('CorpusChristi');
            //Seeing the Sunday is not taken by Corpus Christi, it should be later taken by a Sunday of Ordinary Time
            // (they are calculated back to Pentecost)
        } elseif ($this->CalendarParams->CorpusChristi === CorpusChristi::SUNDAY) {
            $this->PropriumDeTempore[ 'CorpusChristi' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
                ->add(new \DateInterval('P' . ( 7 * 9 ) . 'D'));
            $this->createPropriumDeTemporeLiturgicalEventByKey('CorpusChristi');
        }

        if ($this->CalendarParams->Year >= 2000) {
            if ($this->CalendarParams->Locale === LitLocale::LATIN) {
                $divineMercySunday = $this->PropriumDeTempore[ 'Easter2' ][ 'name' ]
                    . ' vel Dominica Divinæ Misericordiæ';
            } else {
                /**translators: context alternate name for a liturgical event, e.g. Second Sunday of Easter `or` Divine Mercy Sunday*/
                $or                = _('or');
                $divineMercySunday = $this->PropriumDeTempore[ 'Easter2' ][ 'name' ]
                    . " $or "
                    /**translators: as instituted on the day of the canonization of St Faustina Kowalska by Pope John Paul II in the year 2000 */
                    . _('Divine Mercy Sunday');
            }
            $this->Cal->setProperty('Easter2', 'name', $divineMercySunday);
        }
    }

    /**
     * Calculates the date for Ash Wednesday
     * and creates the corresponding LiturgicalEvent in the calendar
     *
     * @return void
     */
    private function calculateAshWednesday(): void
    {
        $this->PropriumDeTempore[ 'AshWednesday' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P46D'));
        $this->createPropriumDeTemporeLiturgicalEventByKey('AshWednesday');
    }

    /**
     * Calculates the dates for Weekdays of Holy Week from Monday to Thursday inclusive
     * and creates the corresponding LiturgicalEvents in the calendar
     *
     * @return void
     */
    private function calculateWeekdaysHolyWeek(): void
    {
        //Weekdays of Holy Week from Monday to Thursday inclusive
        // ( that is, thursday morning chrism Mass... the In Coena Domini Mass begins the Easter Triduum )
        $this->PropriumDeTempore[ 'MonHolyWeek' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P6D'));
        $this->PropriumDeTempore[ 'TueHolyWeek' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P5D'));
        $this->PropriumDeTempore[ 'WedHolyWeek' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P4D'));
        $this->createPropriumDeTemporeLiturgicalEventByKey('MonHolyWeek');
        $this->createPropriumDeTemporeLiturgicalEventByKey('TueHolyWeek');
        $this->createPropriumDeTemporeLiturgicalEventByKey('WedHolyWeek');
    }

    /**
     * Calculates the dates for Monday to Saturday of the Octave of Easter
     * and creates the corresponding LiturgicalEvents in the calendar
     *
     * @return void
     */
    private function calculateEasterOctave(): void
    {
        $this->PropriumDeTempore[ 'MonOctaveEaster' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P1D'));
        $this->PropriumDeTempore[ 'TueOctaveEaster' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P2D'));
        $this->PropriumDeTempore[ 'WedOctaveEaster' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P3D'));
        $this->PropriumDeTempore[ 'ThuOctaveEaster' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P4D'));
        $this->PropriumDeTempore[ 'FriOctaveEaster' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P5D'));
        $this->PropriumDeTempore[ 'SatOctaveEaster' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P6D'));
        $this->createPropriumDeTemporeLiturgicalEventByKey('MonOctaveEaster');
        $this->createPropriumDeTemporeLiturgicalEventByKey('TueOctaveEaster');
        $this->createPropriumDeTemporeLiturgicalEventByKey('WedOctaveEaster');
        $this->createPropriumDeTemporeLiturgicalEventByKey('ThuOctaveEaster');
        $this->createPropriumDeTemporeLiturgicalEventByKey('FriOctaveEaster');
        $this->createPropriumDeTemporeLiturgicalEventByKey('SatOctaveEaster');
    }

    /**
     * Calculates the dates for Sacred Heart and Christ the King and creates the corresponding LiturgicalEvents in the calendar
     *
     * @return void
     */
    private function calculateMobileSolemnitiesOfTheLord(): void
    {
        $this->PropriumDeTempore[ 'SacredHeart' ][ 'date' ] = Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 9 + 5 ) . 'D'));
        $this->createPropriumDeTemporeLiturgicalEventByKey('SacredHeart');

        //Christ the King is calculated backwards from the first sunday of advent
        $this->PropriumDeTempore[ 'ChristKing' ][ 'date' ] = DateTime::createFromFormat(
            '!j-n-Y',
            '25-12-' . $this->CalendarParams->Year,
            new \DateTimeZone('UTC')
        )->modify('last Sunday')->sub(new \DateInterval('P' . ( 4 * 7 ) . 'D'));
        $this->createPropriumDeTemporeLiturgicalEventByKey('ChristKing');
    }

    /**
     * Calculates the dates for fixed date Solemnities and creates the corresponding LiturgicalEvents in the calendar.
     *
     * Solemnities are celebrations of the highest rank in the Liturgical Calendar. They are days of special importance
     * in the Roman Rite, and are usually observed with a Vigil, proper readings, and a special Mass formulary.
     * Fixed date Solemnities, as the name implies, are Solemnities that fall on the same date every year.
     *
     * @return void
     */
    private function calculateFixedSolemnities(): void
    {
        //even though Mary Mother of God is a fixed date solemnity,
        // it is however found in the Proprium de Tempore and not in the Proprium de Sanctis
        $this->PropriumDeTempore[ 'MotherGod' ][ 'date' ] = DateTime::createFromFormat(
            '!j-n-Y',
            '1-1-' . $this->CalendarParams->Year,
            new \DateTimeZone('UTC')
        );
        $this->createPropriumDeTemporeLiturgicalEventByKey('MotherGod');

        $tempCalSolemnities = array_filter($this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ], function ($el) {
            return $el->grade === LitGrade::SOLEMNITY;
        });
        foreach ($tempCalSolemnities as $row) {
            $currentFeastDate    = DateTime::createFromFormat(
                '!j-n-Y',
                $row->day . '-' . $row->month . '-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            );
            $tempLiturgicalEvent = new LiturgicalEvent($row->name, $currentFeastDate, $row->color, LitFeastType::FIXED, $row->grade, $row->common);

            /**
             * A Solemnity impeded in any given year is transferred to the nearest day following designated in nn. 1-8 of the Tables given above ( LY 60 )
             * However if a solemnity is impeded by a Sunday of Advent, Lent or Easter Time, the solemnity is transferred to the Monday following,
             *   or to the nearest free day, as laid down by the General Norms.
             * This affects Joseph, Husband of Mary ( Mar 19 ), Annunciation ( Mar 25 ), and Immaculate Conception ( Dec 8 ).
             * It is not possible for a fixed date Solemnity to fall on a Sunday of Easter.

             * However, if a solemnity is impeded by Palm Sunday or by Easter Sunday, it is transferred to the first free day ( Monday? )
             *   after the Second Sunday of Easter ( decision of the Congregation of Divine Worship, dated 22 April 1990,
             *   in Notitiæ vol. 26 [ 1990 ] num. 3/4, p. 160, Prot. CD 500/89 ).
             * Any other celebrations that are impeded are omitted for that year.
             *
             * <<
             * Quando vero sollemnitates in his dominicis ( i.e. Adventus, Quadragesimae et Paschae ),
             *   iuxta n.5 "Normarum universalium de anno liturgico et de calendario" sabbato anticipari debent.
             * Experientia autem pastoralis ostendit quod solutio huiusmodi nonnullas praebet difficultates praesertim quoad occurrentiam
             *   celebrationis Missae vespertinae et II Vesperarum Liturgiae Horarum cuiusdam sollemnitatis
             *   cum celebratione Missae vespertinae et I Vesperarum diei dominicae.
             * [ ... Perciò facciamo la seguente modifica al n. 5 delle norme universali: ]
             * Sollemnitates autem in his dominicis occurrentes ad feriam secundam sequentem transferuntur,
             *   nisi agatur de occurrentia in Dominica in Palmis aut in Dominica Resurrectionis Domini.
             * >>
             *
             * http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/1990/284-285.html
             * https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/1990/notitiae-26-(1990)/Notitiae-284-285-1990.pdf
             *
             * In the year 2024, an exemption was decreed by the Dicastery for Divine Worship upon the request of Cardinal Zuppi,
             *   to maintain the celebration of the Immaculate Conception on December 8th notwithstanding the coincidence with the
             *   Second Sunday of Advent, rather than transfer it to the Monday following the Second Sunday of Lent.
             * https://liturgico.chiesacattolica.it/solennita-dellimmacolata-concezione-2024/
             */

            if ($this->Cal->inSolemnities($currentFeastDate)) {
                /**
                 * If Joseph, Husband of Mary ( Mar 19 ) falls on Palm Sunday or during Holy Week, it is moved to the Saturday preceding Palm Sunday
                 * This is correct and the reason for this is that, in this case, Annunciation will also fall during Holy Week,
                 *   and the Annunciation will be transferred to the Monday following the Second Sunday of Easter
                 * Notitiæ vol. 42 [ 2006 ] num. 3/4, 475-476, p. 96
                 * http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/2006/475-476.html
                 * https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/2000/notitiae-42-(2006)/Notitiae-475-476-2006.pdf
                 */
                $locale = LitLocale::$PRIMARY_LANGUAGE;
                if (
                    $row->event_key === 'StJoseph'
                    && $currentFeastDate >= $this->Cal->getLiturgicalEvent('PalmSun')->date
                    && $currentFeastDate <= $this->Cal->getLiturgicalEvent('Easter')->date
                ) {
                    $tempLiturgicalEvent->date = Utilities::calcGregEaster($this->CalendarParams->Year)->sub(new \DateInterval('P8D'));
                    $this->Messages[]          = sprintf(
                        /**translators: 1: LiturgicalEvent name, 2: LiturgicalEvent date, 3: Requested calendar year, 4: Description of the reason for the transferral (ex. the Saturday preceding Palm Sunday), 5: actual date for the transferral, 6: Decree of the Congregation for Divine Worship  */
                        _('The Solemnity \'%1$s\' falls on %2$s in the year %3$d, the celebration has been transferred to %4$s (%5$s) as per the %6$s.'),
                        $tempLiturgicalEvent->name,
                        $this->Cal->solemnityFromDate($currentFeastDate)->name,
                        $this->CalendarParams->Year,
                        _('the Saturday preceding Palm Sunday'),
                        $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                            ? ( $tempLiturgicalEvent->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[ (int)$tempLiturgicalEvent->date->format('n') ] )
                            : ( $locale === 'en'
                                ? $tempLiturgicalEvent->date->format('F jS')
                                : $this->dayAndMonth->format($tempLiturgicalEvent->date->format('U'))
                            ),
                        '<a href="https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/2000/notitiae-42-(2006)/Notitiae-475-476-2006.pdf">'
                            . _('Decree of the Congregation for Divine Worship')
                        . '</a>'
                    );
                } elseif ($row->event_key === 'Annunciation' && $currentFeastDate >= $this->Cal->getLiturgicalEvent('PalmSun')->date && $currentFeastDate <= $this->Cal->getLiturgicalEvent('Easter2')->date) {
                    //if the Annunciation falls during Holy Week or within the Octave of Easter, it is transferred to the Monday after the Second Sunday of Easter.
                    $tempLiturgicalEvent->date = Utilities::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P8D'));
                    $this->Messages[]          = sprintf(
                        /**translators: 1: LiturgicalEvent name, 2: LiturgicalEvent date, 3: Requested calendar year, 4: Explicatory string for the transferral (ex. the Saturday preceding Palm Sunday), 5: actual date for the transferral, 6: Decree of the Congregation for Divine Worship */
                        _('The Solemnity \'%1$s\' falls on %2$s in the year %3$d, the celebration has been transferred to %4$s (%5$s) as per the %6$s.'),
                        $tempLiturgicalEvent->name,
                        $this->Cal->solemnityFromDate($currentFeastDate)->name,
                        $this->CalendarParams->Year,
                        _('the Monday following the Second Sunday of Easter'),
                        $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                            ? ( $tempLiturgicalEvent->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[ (int)$tempLiturgicalEvent->date->format('n') ] )
                            : ( $locale === 'en'
                                ? $tempLiturgicalEvent->date->format('F jS')
                                : $this->dayAndMonth->format($tempLiturgicalEvent->date->format('U'))
                            ),
                        '<a href="https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/2000/notitiae-42-(2006)/Notitiae-475-476-2006.pdf">'
                            . _('Decree of the Congregation for Divine Worship')
                        . '</a>'
                    );

                    //In some German churches it was the custom to keep the office of the Annunciation on the Saturday before Palm Sunday
                    // if the 25th of March fell in Holy Week.
                    // source: https://www.newadvent.org/cathen/01542a.htm
                    /*
                            else if( $tempLiturgicalEvent->date == $this->Cal->getLiturgicalEvent( "PalmSun" )->date ){
                            $tempLiturgicalEvent->date->add( new \DateInterval( 'P15D' ) );
                            //$tempLiturgicalEvent->date->sub( new \DateInterval( 'P1D' ) );
                            }
                    */
                } elseif (
                    in_array($row->event_key, [ 'Annunciation', 'StJoseph', 'ImmaculateConception' ])
                    && $this->Cal->isSundayAdventLentEaster($currentFeastDate)
                ) {
                    // Take into account the exemption made for Italy since 2024, when the Immaculate Conception coincides with the Second Sunday of Advent
                    if ($this->CalendarParams->Year >= 2024 && $row->event_key === 'ImmaculateConception' && $this->CalendarParams->NationalCalendar === 'IT') {
                        // We actually suppress the Second Sunday of Advent in this case
                        $this->Cal->removeLiturgicalEvent('Advent2');
                        $this->Messages[] = sprintf(
                            'La solennità dell\'Immacolata Concezione\' coincide con la Seconda Domenica dell\'Avvento nell\'anno %1$d, e per <a href="%2$s" target="_blank">decreto della Congregazione per il Culto Divino</a> del 6 ottobre 2023 viene fatta deroga alla regola del trasferimento al lunedì seguente per tutte le diocesi dell\'Italia, per le quali verrà celebrata comunque il giorno 8 dicembre.',
                            $this->CalendarParams->Year,
                            'https://liturgico.chiesacattolica.it/solennita-dellimmacolata-concezione-2024/'
                        );
                    } else {
                        $tempLiturgicalEvent->date = clone( $currentFeastDate );
                        $tempLiturgicalEvent->date->add(new \DateInterval('P1D'));
                        $this->Messages[] = sprintf(
                            /**translators:
                             * 1: LiturgicalEvent name,
                             * 2: LiturgicalEvent date,
                             * 3: Requested calendar year,
                             * 4: Explicatory string for the transferral,
                             * 5: actual date for the transferral,
                             * 6: Decree of the Congregation for Divine Worship
                             */
                            _('The Solemnity \'%1$s\' falls on %2$s in the year %3$d, the celebration has been transferred to %4$s (%5$s) as per the %6$s.'),
                            $tempLiturgicalEvent->name,
                            $this->Cal->solemnityFromDate($currentFeastDate)->name,
                            $this->CalendarParams->Year,
                            _('the following Monday'),
                            $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                                ? ( $tempLiturgicalEvent->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[ (int)$tempLiturgicalEvent->date->format('n') ] )
                                : ( $locale === 'en'
                                        ? $tempLiturgicalEvent->date->format('F jS')
                                        : $this->dayAndMonth->format($tempLiturgicalEvent->date->format('U'))
                            ),
                            '<a href="https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/1990/notitiae-26-(1990)/Notitiae-284-285-1990.pdf">' . _('Decree of the Congregation for Divine Worship') . '</a>'
                        );
                    }
                } else {
                    //In all other cases, let's make a note of what's happening and ask the Congegation for Divine Worship
                    $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                        /**translators: 1: LiturgicalEvent name, 2: Coinciding LiturgicalEvent name, 3: Requested calendar year */
                        _('The Solemnity \'%1$s\' coincides with the Solemnity \'%2$s\' in the year %3$d. We should ask the Congregation for Divine Worship what to do about this!'),
                        $row->name,
                        $this->Cal->solemnityFromDate($currentFeastDate)->name,
                        $this->CalendarParams->Year
                    );
                }

                //In the year 2022, the Solemnity Nativity of John the Baptist coincides with the Solemnity of the Sacred Heart
                // Nativity of John the Baptist anticipated by one day to June 23
                // ( except in cases where John the Baptist is patron of a nation, diocese, city or religious community,
                // then the Sacred Heart can be anticipated by one day to June 23 )
                // http://www.cultodivino.va/content/cultodivino/it/documenti/responsa-ad-dubia/2020/de-calendario-liturgico-2022.html
                // https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/2020/notitiae-56-(2020)/Notitiae-597-NS-005-2020.pdf
                // This will happen again in 2033 and 2044
                if ($row->event_key === 'NativityJohnBaptist' && $this->Cal->solemnityKeyFromDate($currentFeastDate) === 'SacredHeart') {
                    $NativityJohnBaptistNewDate = clone( $this->Cal->getLiturgicalEvent('SacredHeart')->date );
                    $SacredHeart                = $this->Cal->solemnityFromDate($currentFeastDate);
                    if (!$this->Cal->inSolemnities($NativityJohnBaptistNewDate->sub(new \DateInterval('P1D')))) {
                        $tempLiturgicalEvent->date->sub(new \DateInterval('P1D'));
                        $decree = '<a href="'
                            . 'https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/2020/notitiae-56-(2020)/Notitiae-597-NS-005-2020.pdf'
                            . '">'
                            . _('Decree of the Congregation for Divine Worship')
                            . '</a>';

                        $this->Messages[] =
                        '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> '
                        . sprintf(
                            /**translators:
                             * 1: LiturgicalEvent name,
                             * 2: Coinciding LiturgicalEvent name,
                             * 3: Requested calendar year,
                             * 4: Decree of the Congregation for Divine Worship
                             */
                            _('Seeing that the Solemnity \'%1$s\' coincides with the Solemnity \'%2$s\' in the year %3$d, '
                                . 'it has been anticipated by one day as per %4$s.'),
                            $tempLiturgicalEvent->name,
                            $SacredHeart->name,
                            $this->CalendarParams->Year,
                            $decree
                        );
                    }
                }
            }
            $this->Cal->addLiturgicalEvent($row->event_key, $tempLiturgicalEvent);
        }

        //let's add a displayGrade property for AllSouls so applications don't have to worry about fixing it
        $this->Cal->setProperty('AllSouls', 'grade_display', ''); //$this->LitGrade->i18n(LitGrade::COMMEMORATION, false)

        $this->Cal->addSolemnitiesLordBVM([
            'Easter',
            'Christmas',
            'Ascension',
            'Pentecost',
            'Trinity',
            'CorpusChristi',
            'SacredHeart',
            'ChristKing',
            'MotherGod',
            'Annunciation',
            'ImmaculateConception',
            'Assumption',
            'StJoseph',
            'NativityJohnBaptist'
        ]);
    }


    /**
     * Calculates the dates for the Baptism of the Lord, Holy Family, and the other feasts of the Lord
     * (Presentation, Transfiguration, Triumph of the Holy Cross and Dedication of the Lateran Basilica)
     * and creates the corresponding LiturgicalEvents in the calendar.
     *
     * Also creates the LiturgicalEvent for Christ the Eternal High Priest for those areas that have adopted this liturgical event
     *
     * @return void
     */
    private function calculateFeastsOfTheLord(): void
    {
        //Baptism of the Lord is celebrated the Sunday after Epiphany, for exceptions see immediately below...
        $this->BaptismLordFmt = '6-1-' . $this->CalendarParams->Year;
        $this->BaptismLordMod = 'next Sunday';
        //If Epiphany is celebrated on Sunday between Jan. 2 - Jan 8, and Jan. 7 or Jan. 8 is Sunday,
        // then the Baptism of the Lord is celebrated on the Monday immediately following that Sunday
        if ($this->CalendarParams->Epiphany === Epiphany::SUNDAY_JAN2_JAN8) {
            $dateJan7 = DateTime::createFromFormat(
                '!j-n-Y',
                '7-1-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            );
            $dateJan8 = DateTime::createFromFormat(
                '!j-n-Y',
                '8-1-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            );
            if (self::dateIsSunday($dateJan7)) {
                $this->BaptismLordFmt = '7-1-' . $this->CalendarParams->Year;
                $this->BaptismLordMod = 'next Monday';
            } elseif (self::dateIsSunday($dateJan8)) {
                $this->BaptismLordFmt = '8-1-' . $this->CalendarParams->Year;
                $this->BaptismLordMod = 'next Monday';
            }
        }
        $this->PropriumDeTempore[ 'BaptismLord' ][ 'date' ] = DateTime::createFromFormat('!j-n-Y', $this->BaptismLordFmt, new \DateTimeZone('UTC'))
            ->modify($this->BaptismLordMod);
        $this->createPropriumDeTemporeLiturgicalEventByKey('BaptismLord');

        // the other feasts of the Lord ( Presentation, Transfiguration and Triumph of the Holy Cross) are fixed date feasts
        // and are found in the Proprium de Sanctis
        // :DedicationLateran is a specific case, we consider it a Feast of the Lord even though it is displayed as FEAST
        //  source: in the Missale Romanum, in the section Index Alphabeticus Celebrationum,
        //    under Iesus Christus D. N., the Dedicatio Basilicae Lateranensis is also listed
        $tempCal = array_filter($this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ], function ($el) {
            return $el->grade === LitGrade::FEAST_LORD;
        });

        foreach ($tempCal as $row) {
            $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row->day . '-' . $row->month . '-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
            $litEvent         = new LiturgicalEvent($row->name, $currentFeastDate, $row->color, LitFeastType::FIXED, $row->grade, $row->common);
            if ($row->event_key === 'DedicationLateran') {
                $litEvent->grade_display = $this->LitGrade->i18n(LitGrade::FEAST, false);
                $litEvent->setGradeAbbreviation($this->LitGrade->i18n(LitGrade::FEAST, false, true));
            }
            $this->Cal->addLiturgicalEvent($row->event_key, $litEvent);
        }

        //Holy Family is celebrated the Sunday after Christmas, unless Christmas falls on a Sunday, in which case it is celebrated Dec. 30
        $locale = LitLocale::$PRIMARY_LANGUAGE;
        if (self::dateIsSunday($this->Cal->getLiturgicalEvent('Christmas')->date)) {
            $this->PropriumDeTempore[ 'HolyFamily' ][ 'date' ] = DateTime::createFromFormat(
                '!j-n-Y',
                '30-12-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            );

            $HolyFamily       = $this->createPropriumDeTemporeLiturgicalEventByKey('HolyFamily');
            $this->Messages[] = sprintf(
                /**translators: 1: LiturgicalEvent name (Christmas), 2: Requested calendar year, 3: LiturgicalEvent name (Holy Family), 4: New date for Holy Family */
                _('\'%1$s\' falls on a Sunday in the year %2$d, therefore the Feast \'%3$s\' is celebrated on %4$s rather than on the Sunday after Christmas.'),
                $this->Cal->getLiturgicalEvent('Christmas')->name,
                $this->CalendarParams->Year,
                $HolyFamily->name,
                $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? ( $HolyFamily->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[ (int)$HolyFamily->date->format('n') ] )
                    : ( $locale === 'en'
                        ? $HolyFamily->date->format('F jS')
                        : $this->dayAndMonth->format($HolyFamily->date->format('U'))
                    )
            );
        } else {
            $this->PropriumDeTempore[ 'HolyFamily' ][ 'date' ] = DateTime::createFromFormat(
                '!j-n-Y',
                '25-12-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            )->modify('next Sunday');
            $this->createPropriumDeTemporeLiturgicalEventByKey('HolyFamily');
        }

        // In 2012, Pope Benedict XVI gave faculty to the Episcopal Conferences
        //  to insert the Feast of Our Lord Jesus Christ, the Eternal High Priest
        //  in their own liturgical calendars on the Thursday after Pentecost,
        //  see https://notitiae.ipsissima-verba.org/pdf/notitiae-2012-335-368.pdf
        if ($this->CalendarParams->Year >= 2012 && true === $this->CalendarParams->EternalHighPriest) {
            $EternalHighPriestDate = clone( $this->Cal->getLiturgicalEvent('Pentecost')->date );
            $EternalHighPriestDate->modify('next Thursday');
            $locale                = LitLocale::$PRIMARY_LANGUAGE;
            $EternalHighPriestName = ($locale === LitLocale::LATIN_PRIMARY_LANGUAGE)
                ? 'Domini Nostri Iesu Christi Summi et Aeterni Sacerdotis'
                /**translators: You can ignore this translation if the Feast has not been inserted by the Episcopal Conference */
                : _('Our Lord Jesus Christ, The Eternal High Priest');
            $litEvent = new LiturgicalEvent($EternalHighPriestName, $EternalHighPriestDate, LitColor::WHITE, LitFeastType::FIXED, LitGrade::FEAST_LORD, LitCommon::PROPRIO);
            $this->Cal->addLiturgicalEvent('JesusChristEternalHighPriest', $litEvent);
            $this->Messages[] = sprintf(
                /**translators: 1: National Calendar, 2: Requested calendar year, 3: source of the rule */
                _('In 2012, Pope Benedict XVI gave faculty to the Episcopal Conferences' .
                   ' to insert the Feast of Our Lord Jesus Christ the Eternal High Priest in their own liturgical calendars' .
                   ' on the Thursday after Pentecost: applicable to the calendar \'%1$s\' in the year \'%2$d\' (%3$s).'),
                $this->CalendarParams->NationalCalendar,
                $this->CalendarParams->Year,
                '<a href="https://notitiae.ipsissima-verba.org/pdf/notitiae-2012-335-368.pdf" target="_blank">notitiae-2012-335-368.pdf</a>'
            );
        }
    }

    /**
     * Calculates the dates for Sundays of Christmas and Ordinary Time and creates the corresponding LiturgicalEvents in the calendar
     *
     * Sundays of Ordinary Time in the First part of the year are numbered from after the Baptism of the Lord
     * ( which begins the 1st week of Ordinary Time ) until Ash Wednesday
     * Sundays of Ordinary Time in the Latter part of the year are numbered backwards from Christ the King ( 34th ) to Pentecost
     *
     * @return void
     */
    private function calculateSundaysChristmasOrdinaryTime(): void
    {
        //If a fixed date Solemnity occurs on a Sunday of Ordinary Time or on a Sunday of Christmas,
        // the Solemnity is celebrated in place of the Sunday. ( e.g., Birth of John the Baptist, 1990 )
        //If a fixed date Feast of the Lord occurs on a Sunday in Ordinary Time, the feast is celebrated in place of the Sunday

        //Sundays of Ordinary Time in the First part of the year are numbered from after the Baptism of the Lord
        // ( which begins the 1st week of Ordinary Time ) until Ash Wednesday
        $firstOrdinary = DateTime::createFromFormat('!j-n-Y', $this->BaptismLordFmt, new \DateTimeZone('UTC'))->modify($this->BaptismLordMod);
        //Basically we take Ash Wednesday as the limit...
        //Here is ( Ash Wednesday - 7 ) since one more cycle will complete...
        $firstOrdinaryLimit = Utilities::calcGregEaster($this->CalendarParams->Year)->sub(new \DateInterval('P53D'));
        $ordSun             = 1;
        while ($firstOrdinary >= $this->Cal->getLiturgicalEvent('BaptismLord')->date && $firstOrdinary < $firstOrdinaryLimit) {
            $firstOrdinary = DateTime::createFromFormat(
                '!j-n-Y',
                $this->BaptismLordFmt,
                new \DateTimeZone('UTC')
            )->modify($this->BaptismLordMod)->modify('next Sunday')->add(new \DateInterval('P' . ( ( $ordSun - 1 ) * 7 ) . 'D'));
            $ordSun++;
            if (!$this->Cal->inSolemnities($firstOrdinary)) {
                $this->Cal->addLiturgicalEvent('OrdSunday' . $ordSun, new LiturgicalEvent(
                    $this->PropriumDeTempore[ 'OrdSunday' . $ordSun ][ 'name' ],
                    $firstOrdinary,
                    LitColor::GREEN,
                    LitFeastType::MOBILE,
                    LitGrade::FEAST_LORD,
                    [],
                    ''
                ));
            } else {
                $this->Messages[] = sprintf(
                    /**translators: 1: LiturgicalEvent name, 2: Superseding LiturgicalEvent grade, 3: Superseding LiturgicalEvent name, 4: Requested calendar year */
                    _('\'%1$s\' is superseded by the %2$s \'%3$s\' in the year %4$d.'),
                    $this->PropriumDeTempore[ 'OrdSunday' . $ordSun ][ 'name' ],
                    $this->Cal->solemnityFromDate($firstOrdinary)->grade > LitGrade::SOLEMNITY ? '<i>' . $this->LitGrade->i18n($this->Cal->solemnityFromDate($firstOrdinary)->grade, false) . '</i>' : $this->LitGrade->i18n($this->Cal->solemnityFromDate($firstOrdinary)->grade, false),
                    $this->Cal->solemnityFromDate($firstOrdinary)->name,
                    $this->CalendarParams->Year
                );
            }
        }

        //Sundays of Ordinary Time in the Latter part of the year are numbered backwards from Christ the King ( 34th ) to Pentecost
        $lastOrdinary = DateTime::createFromFormat(
            '!j-n-Y',
            '25-12-' . $this->CalendarParams->Year,
            new \DateTimeZone('UTC')
        )->modify('last Sunday')->sub(new \DateInterval('P' . ( 4 * 7 ) . 'D'));
        //We take Trinity Sunday as the limit...
        //Here is ( Trinity Sunday + 7 ) since one more cycle will complete...
        $lastOrdinaryLowerLimit = Utilities::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P' . ( 7 * 9 ) . 'D'));
        $ordSun                 = 34;
        $ordSunCycle            = 4;

        while ($lastOrdinary <= $this->Cal->getLiturgicalEvent('ChristKing')->date && $lastOrdinary > $lastOrdinaryLowerLimit) {
            $lastOrdinary = DateTime::createFromFormat(
                '!j-n-Y',
                '25-12-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            )->modify('last Sunday')->sub(new \DateInterval('P' . ( ++$ordSunCycle * 7 ) . 'D'));
            $ordSun--;
            if (!$this->Cal->inSolemnities($lastOrdinary)) {
                $this->Cal->addLiturgicalEvent('OrdSunday' . $ordSun, new LiturgicalEvent(
                    $this->PropriumDeTempore[ 'OrdSunday' . $ordSun ][ 'name' ],
                    $lastOrdinary,
                    LitColor::GREEN,
                    LitFeastType::MOBILE,
                    LitGrade::FEAST_LORD,
                    [],
                    ''
                ));
            } else {
                $this->Messages[] = sprintf(
                    /**translators: 1: LiturgicalEvent name, 2: Superseding LiturgicalEvent grade, 3: Superseding LiturgicalEvent name, 4: Requested calendar year */
                    _('\'%1$s\' is superseded by the %2$s \'%3$s\' in the year %4$d.'),
                    $this->PropriumDeTempore[ 'OrdSunday' . $ordSun ][ 'name' ],
                    $this->Cal->solemnityFromDate($lastOrdinary)->grade > LitGrade::SOLEMNITY ? '<i>' . $this->LitGrade->i18n($this->Cal->solemnityFromDate($lastOrdinary)->grade, false) . '</i>' : $this->LitGrade->i18n($this->Cal->solemnityFromDate($lastOrdinary)->grade, false),
                    $this->Cal->solemnityFromDate($lastOrdinary)->name,
                    $this->CalendarParams->Year
                );
            }
        }
    }

    /**
     * Calculates the dates for Feasts of Mary and Saints and creates the corresponding LiturgicalEvents in the calendar
     *
     * If a Feast ( not of the Lord ) occurs on a Sunday in Ordinary Time, the Sunday is celebrated.  ( e.g., St. Luke, 1992 )
     * obviously solemnities also have precedence
     * The Dedication of the Lateran Basilica is an exceptional case, where it is treated as a Feast of the Lord, even if it is displayed as a Feast
     *  source: in the Missale Romanum, in the section Index Alphabeticus Celebrationum,
     *    under Iesus Christus D. N., the Dedicatio Basilicae Lateranensis is also listed
     *  so we give it a grade of 5 === FEAST_LORD but a displayGrade of FEAST
     *  it should therefore have already been handled in $this->calculateFeastsOfTheLord(), see :DedicationLateran
     *
     * @return void
     */
    private function calculateFeastsMarySaints(): void
    {
        $tempCal = array_filter($this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ], function ($el) {
            return $el->grade === LitGrade::FEAST;
        });

        foreach ($tempCal as $row) {
            $row->date = DateTime::createFromFormat('!j-n-Y', $row->day . '-' . $row->month . '-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
            // If a Feast ( not of the Lord ) occurs on a Sunday in Ordinary Time, the Sunday is celebrated.  ( e.g., St. Luke, 1992 )
            // obviously solemnities also have precedence
            // The Dedication of the Lateran Basilica is an exceptional case, where it is treated as a Feast of the Lord, even if it is displayed as a Feast
            //  source: in the Missale Romanum, in the section Index Alphabeticus Celebrationum,
            //    under Iesus Christus D. N., the Dedicatio Basilicae Lateranensis is also listed
            //  so we give it a grade of 5 === FEAST_LORD but a displayGrade of FEAST
            //  it should therefore have already been handled in $this->calculateFeastsOfTheLord(), see :DedicationLateran
            if (self::dateIsNotSunday($row->date) && !$this->Cal->inSolemnities($row->date)) {
                $litEvent = new LiturgicalEvent($row->name, $row->date, $row->color, LitFeastType::FIXED, $row->grade, $row->common);
                $this->Cal->addLiturgicalEvent($row->event_key, $litEvent);
            } else {
                $this->handleCoincidence($row, RomanMissal::EDITIO_TYPICA_1970);
            }
        }

        // With the decree Apostolorum Apostola ( June 3rd 2016 ), the Congregation for Divine Worship
        // with the approval of Pope Francis elevated the memorial of Saint Mary Magdalen to a Feast
        // source: https://www.vatican.va/roman_curia/congregations/ccdds/documents/articolo-roche-maddalena_it.pdf
        // This is taken care of ahead when the "memorials from decrees" are applied
        // see :MEMORIALS_FROM_DECREES
    }

    /**
     * Calculates all weekdays of Advent, but gives a certain importance to the weekdays of Advent from 17 Dec. to 24 Dec.
     * ( the same will be true of the Octave of Christmas and weekdays of Lent )
     * on which days obligatory memorials can only be celebrated in partial form
     */
    private function calculateWeekdaysAdvent(): void
    {
        $DoMAdvent1       = $this->Cal->getLiturgicalEvent('Advent1')->date->format('j'); //DoM == Day of Month
        $MonthAdvent1     = $this->Cal->getLiturgicalEvent('Advent1')->date->format('n');
        $weekdayAdvent    = DateTime::createFromFormat('!j-n-Y', $DoMAdvent1 . '-' . $MonthAdvent1 . '-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
        $weekdayAdventCnt = 1;
        while ($weekdayAdvent >= $this->Cal->getLiturgicalEvent('Advent1')->date && $weekdayAdvent < $this->Cal->getLiturgicalEvent('Christmas')->date) {
            $weekdayAdvent = DateTime::createFromFormat(
                '!j-n-Y',
                $DoMAdvent1 . '-' . $MonthAdvent1 . '-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            )->add(new \DateInterval('P' . $weekdayAdventCnt . 'D'));

            //if we're not dealing with a sunday or a solemnity or an obligatory memorial, then create the weekday
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($weekdayAdvent) && self::dateIsNotSunday($weekdayAdvent)) {
                $upper          = (int)$weekdayAdvent->format('z');
                $diff           = $upper - (int)$this->Cal->getLiturgicalEvent('Advent1')->date->format('z'); //day count between current day and First Sunday of Advent
                $currentAdvWeek = ( ( $diff - $diff % 7 ) / 7 ) + 1; //week count between current day and First Sunday of Advent

                $dayOfTheWeek = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? LatinUtils::LATIN_DAYOFTHEWEEK[ $weekdayAdvent->format('w') ]
                    : ucfirst($this->dayOfTheWeek->format($weekdayAdvent->format('U')));
                $ordinal      = ucfirst(
                    Utilities::getOrdinal($currentAdvWeek, $this->CalendarParams->Locale, $this->formatterFem, LatinUtils::LATIN_ORDINAL_FEM_GEN)
                );
                $nthStr       = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? sprintf('Hebdomadæ %s Adventus', $ordinal)
                    : sprintf(
                        /**translators: %s is an ordinal number (first, second...) */
                        _('of the %s Week of Advent'),
                        $ordinal
                    );
                $name                   = $dayOfTheWeek . ' ' . $nthStr;
                $litEvent               = new LiturgicalEvent($name, $weekdayAdvent, LitColor::PURPLE, LitFeastType::MOBILE);
                $litEvent->psalter_week = $this->Cal::psalterWeek($currentAdvWeek);
                $this->Cal->addLiturgicalEvent('AdventWeekday' . $weekdayAdventCnt, $litEvent);
            }

            $weekdayAdventCnt++;
        }
    }


    /**
     * Calculates all weekdays of the Octave of Christmas, but gives a certain importance to the weekdays of Christmas from 25 Dec. to 31 Dec.
     * (the same will be true of the Octave of Easter and weekdays of Advent)
     *
     * @return void
     */
    private function calculateWeekdaysChristmasOctave(): void
    {
        $weekdayChristmas    = DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
        $weekdayChristmasCnt = 1;
        while (
            $weekdayChristmas >= $this->Cal->getLiturgicalEvent('Christmas')->date
            && $weekdayChristmas < DateTime::createFromFormat('!j-n-Y', '31-12-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'))
        ) {
            $weekdayChristmas = DateTime::createFromFormat(
                '!j-n-Y',
                '25-12-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            )->add(new \DateInterval('P' . $weekdayChristmasCnt . 'D'));
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($weekdayChristmas) && self::dateIsNotSunday($weekdayChristmas)) {
                $ordinal = ucfirst(Utilities::getOrdinal(( $weekdayChristmasCnt + 1 ), $this->CalendarParams->Locale, $this->formatter, LatinUtils::LATIN_ORDINAL));
                $name    = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? sprintf('Dies %s Octavæ Nativitatis', $ordinal)
                    : sprintf(
                        /**translators: %s is an ordinal number (first, second...) */
                        _('%s Day of the Octave of Christmas'),
                        $ordinal
                    );
                $litEvent = new LiturgicalEvent($name, $weekdayChristmas, LitColor::WHITE, LitFeastType::MOBILE);
                $this->Cal->addLiturgicalEvent('ChristmasWeekday' . $weekdayChristmasCnt, $litEvent);
            }
            $weekdayChristmasCnt++;
        }
    }

    /**
     * Calculates all weekdays of Lent, but gives a certain importance to the weekdays of Lent from Ash Wednesday to the day before Palm Sunday
     * (the same will be true of the Octave of Christmas and weekdays of Advent)
     *
     * @return void
     */
    private function calculateWeekdaysLent(): void
    {

        //Day of the Month of Ash Wednesday
        $DoMAshWednesday   = $this->Cal->getLiturgicalEvent('AshWednesday')->date->format('j');
        $MonthAshWednesday = $this->Cal->getLiturgicalEvent('AshWednesday')->date->format('n');
        $weekdayLent       = DateTime::createFromFormat(
            '!j-n-Y',
            $DoMAshWednesday . '-' . $MonthAshWednesday . '-' . $this->CalendarParams->Year,
            new \DateTimeZone('UTC')
        );
        $weekdayLentCnt    = 1;
        while ($weekdayLent >= $this->Cal->getLiturgicalEvent('AshWednesday')->date && $weekdayLent < $this->Cal->getLiturgicalEvent('PalmSun')->date) {
            $weekdayLent = DateTime::createFromFormat('!j-n-Y', $DoMAshWednesday . '-' . $MonthAshWednesday . '-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'))->add(new \DateInterval('P' . $weekdayLentCnt . 'D'));
            if (!$this->Cal->inSolemnities($weekdayLent) && self::dateIsNotSunday($weekdayLent)) {
                if ($weekdayLent > $this->Cal->getLiturgicalEvent('Lent1')->date) {
                    $upper           =  (int)$weekdayLent->format('z');
                    $diff            = $upper -  (int)$this->Cal->getLiturgicalEvent('Lent1')->date->format('z'); //day count between current day and First Sunday of Lent
                    $currentLentWeek = ( ( $diff - $diff % 7 ) / 7 ) + 1; //week count between current day and First Sunday of Lent
                    $ordinal         = ucfirst(Utilities::getOrdinal($currentLentWeek, $this->CalendarParams->Locale, $this->formatterFem, LatinUtils::LATIN_ORDINAL_FEM_GEN));
                    $dayOfTheWeek    = $this->CalendarParams->Locale == LitLocale::LATIN
                        ? LatinUtils::LATIN_DAYOFTHEWEEK[ $weekdayLent->format('w') ]
                        : ucfirst($this->dayOfTheWeek->format($weekdayLent->format('U')));
                    $nthStr          = $this->CalendarParams->Locale === LitLocale::LATIN
                        ? sprintf('Hebdomadæ %s Quadragesimæ', $ordinal)
                        : sprintf(
                            /**translators: %s is an ordinal number (first, second...) */
                            _('of the %s Week of Lent'),
                            $ordinal
                        );
                    $name                   = $dayOfTheWeek . ' ' .  $nthStr;
                    $litEvent               = new LiturgicalEvent($name, $weekdayLent, LitColor::PURPLE, LitFeastType::MOBILE);
                    $litEvent->psalter_week = $this->Cal::psalterWeek($currentLentWeek);
                } else {
                    $dayOfTheWeek = $this->CalendarParams->Locale == LitLocale::LATIN
                        ? LatinUtils::LATIN_DAYOFTHEWEEK[ $weekdayLent->format('w') ]
                        : ucfirst($this->dayOfTheWeek->format($weekdayLent->format('U')));
                    $postStr      = $this->CalendarParams->Locale === LitLocale::LATIN ? 'post Feria IV Cinerum' : _('after Ash Wednesday');
                    $name         = $dayOfTheWeek . ' ' . $postStr;
                    $litEvent     = new LiturgicalEvent($name, $weekdayLent, LitColor::PURPLE, LitFeastType::MOBILE);
                }
                $this->Cal->addLiturgicalEvent('LentWeekday' . $weekdayLentCnt, $litEvent);
            }
            $weekdayLentCnt++;
        }
    }

    /**
     * Adds a message to the API response indicating that a given memorial has been added to the calendar
     *
     * @param object{
     *      grade: int,
     *      name: string,
     *      date: DateTime,
     *      since_year: int,
     *      decree: string
     * } $row A JSON object representing data for the liturgical event in question
     */
    private function addMissalMemorialMessage(object $row): void
    {
        $locale = LitLocale::$PRIMARY_LANGUAGE;
        /**translators:
         * 1. Grade or rank of the liturgical event
         * 2. Name of the liturgical event
         * 3. Day of the liturgical event
         * 4. Year from which the liturgical event has been added
         * 5. Source of the information
         * 6. Requested calendar year
         */
        $message          = _('The %1$s \'%2$s\' has been added on %3$s since the year %4$d (%5$s), applicable to the year %6$d.');
        $this->Messages[] = sprintf(
            $message,
            $this->LitGrade->i18n($row->grade, false),
            $row->name,
            $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                ? ( $row->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[ (int)$row->date->format('n') ] )
                : ( $locale === 'en'
                    ? $row->date->format('F jS')
                    : $this->dayAndMonth->format($row->date->format('U'))
                ),
            $row->since_year,
            $row->decree,
            $this->CalendarParams->Year
        );
    }

    /**
     * Calculates memorials to add to the calendar, following the rules of the Roman Missal.
     *
     * @param int $grade the grade of the liturgical event (e.g. 'memorial', 'feast', etc.)
     * @param string $missal the edition of the Roman Missal
     */
    private function calculateMemorials(int $grade = LitGrade::MEMORIAL, string $missal = RomanMissal::EDITIO_TYPICA_1970): void
    {
        if ($missal === RomanMissal::EDITIO_TYPICA_1970 && $grade === LitGrade::MEMORIAL) {
            $this->createImmaculateHeart();
        }
        $tempCal = array_filter($this->tempCal[ $missal ], function ($el) use ($grade) {
            return $el->grade === $grade;
        });
        foreach ($tempCal as $row) {
            //If it doesn't occur on a Sunday or a Solemnity or a Feast of the Lord or a Feast, then go ahead and create the memorial
            $row->date = DateTime::createFromFormat('!j-n-Y', $row->day . '-' . $row->month . '-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
            if (self::dateIsNotSunday($row->date) && $this->Cal->notInSolemnitiesFeastsOrMemorials($row->date)) {
                $newLiturgicalEvent = new LiturgicalEvent($row->name, $row->date, $row->color, LitFeastType::FIXED, $row->grade, $row->common);
                //Calendar::debugWrite( "adding new memorial '$row->name', common vartype = " . gettype( $row->common ) . ", common = " . implode(', ', $row->common) );
                //Calendar::debugWrite( ">>> added new memorial '$newLiturgicalEvent->name', common vartype = " . gettype( $newLiturgicalEvent->common ) . ", common = " . implode(', ', $newLiturgicalEvent->common) );

                $this->Cal->addLiturgicalEvent($row->event_key, $newLiturgicalEvent);

                $this->reduceMemorialsInAdventLentToCommemoration($row->date, $row);

                if ($missal === RomanMissal::EDITIO_TYPICA_TERTIA_2002) {
                    $row->since_year = 2002;
                    $row->decree     = '<a href="https://press.vatican.va/content/salastampa/it/bollettino/pubblico/2002/03/22/0150/00449.html">'
                        . _('Vatican Press conference: Presentation of the Editio Typica Tertia of the Roman Missal')
                        . '</a>';
                    $this->addMissalMemorialMessage($row);
                } elseif ($missal === RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008) {
                    $row->since_year = 2008;
                    switch ($row->event_key) {
                        case 'StPioPietrelcina':
                            $row->decree = RomanMissal::getName($missal);
                            break;
                        /**both of the following event keys refer to the same decree, no need for a break between them */
                        case 'LadyGuadalupe':
                        case 'JuanDiego':
                            $langs       = ['la' => 'lt', 'es' => 'es'];
                            $lang        = in_array(LitLocale::$PRIMARY_LANGUAGE, array_keys($langs)) ? $langs[LitLocale::$PRIMARY_LANGUAGE] : 'lt';
                            $row->decree = "<a href=\"https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_$lang.html\">"
                                . _('Decree of the Congregation for Divine Worship')
                                . '</a>';
                            break;
                    }
                    $this->addMissalMemorialMessage($row);
                }
                if ($grade === LitGrade::MEMORIAL && $this->Cal->getLiturgicalEvent($row->event_key)->grade > LitGrade::MEMORIAL_OPT) {
                    $this->removeWeekdaysEpiphanyOverridenByMemorials($row->event_key);
                    $this->removeWeekdaysAdventOverridenByMemorials($row->event_key);
                }
            } else {
                // checkImmaculateHeartCoincidence will take care of the case of the Immaculate Heart, reducing both memorials to optional memorials in case of coincidence
                if (false === $this->checkImmaculateHeartCoincidence($row)) {
                    $this->handleCoincidence($row, $missal);
                }
            }
        }
        if ($missal === RomanMissal::EDITIO_TYPICA_TERTIA_2002 && $grade === LitGrade::MEMORIAL_OPT) {
            $this->handleSaintJaneFrancesDeChantal();
        }
    }


    /**
     * If a fixed date optional memorial falls between 17 Dec. to 24 Dec., the Octave of Christmas or weekdays of the Lenten season,
     * it is reduced in rank to a Commemoration ( only the collect can be used )
     *
     * @param DateTime $currentFeastDate the date of the liturgical event to be checked
     * @param \stdClass $row the row of data comprising the event_key and grade of the memorial
     */
    private function reduceMemorialsInAdventLentToCommemoration(DateTime $currentFeastDate, \stdClass $row): void
    {
        //If a fixed date optional memorial falls between 17 Dec. to 24 Dec., the Octave of Christmas or weekdays of the Lenten season,
        //it is reduced in rank to a Commemoration ( only the collect can be used )
        if ($this->Cal->inWeekdaysAdventChristmasLent($currentFeastDate)) {
            $this->Cal->setProperty($row->event_key, 'grade', LitGrade::COMMEMORATION);
            /**translators:
             * 1. Grade or rank of the liturgical event
             * 2. Name of the liturgical event
             * 3. Requested calendar year
             */
            $message          = _(
                'The %1$s \'%2$s\' either falls between 17 Dec. and 24 Dec., or during the Octave of Christmas, or on the weekdays of the Lenten season in the year %3$d, rank reduced to Commemoration.'
            );
            $this->Messages[] = sprintf(
                $message,
                $this->LitGrade->i18n($row->grade, false),
                $row->name,
                $this->CalendarParams->Year
            );
        }
    }

    /**
     * If a weekday of Epiphany is overridden by a Memorial, remove the weekday of Epiphany
     *
     * @param string $event_key the event_key of the liturgical event that may be overriding a weekday of Epiphany
     */
    private function removeWeekdaysEpiphanyOverridenByMemorials(string $event_key): void
    {
        $litEvent = $this->Cal->getLiturgicalEvent($event_key);
        if ($this->Cal->inWeekdaysEpiphany($litEvent->date)) {
            $key = $this->Cal->weekdayEpiphanyKeyFromDate($litEvent->date);
            if (false !== $key) {
                /**translators:
                 * 1. Grade or rank of the liturgical event that has been superseded
                 * 2. Name of the liturgical event that has been superseded
                 * 3. Grade or rank of the liturgical event that is superseding
                 * 4. Name of the liturgical event that is superseding
                 * 5. Requested calendar year
                 */
                $message          = _('The %1$s \'%2$s\' is superseded by the %3$s \'%4$s\' in the year %5$d.');
                $this->Messages[] = sprintf(
                    $message,
                    $this->LitGrade->i18n($this->Cal->getLiturgicalEvent($key)->grade),
                    $this->Cal->getLiturgicalEvent($key)->name,
                    $this->LitGrade->i18n($litEvent->grade, false),
                    $litEvent->name,
                    $this->CalendarParams->Year
                );
                $this->Cal->removeLiturgicalEvent($key);
            }
        }
    }


    /**
     * If a weekday of Advent is overridden by a Memorial, remove the weekday of Advent
     * and assign the psalter week of the weekday of Advent to the Memorial.
     *
     * @param string $event_key the event_key of the liturgical event that may be overriding a weekday of Advent
     */
    private function removeWeekdaysAdventOverridenByMemorials(string $event_key): void
    {
        $litEvent = $this->Cal->getLiturgicalEvent($event_key);
        $Dec17    = \DateTime::createFromFormat(
            'Y-m-d',
            $this->CalendarParams->Year . '-12-17',
            new \DateTimeZone('UTC')
        );
        if (
            $litEvent->date > $this->Cal->getLiturgicalEvent('Advent1')->date
            && $litEvent->date < $Dec17
        ) {
            $key = $this->Cal->weekdayAdventBeforeDec17KeyFromDate($litEvent->date);
            if (false !== $key) {
                /**translators:
                 * 1. Grade or rank of the liturgical event that has been superseded
                 * 2. Name of the liturgical event that has been superseded
                 * 3. Grade or rank of the liturgical event that is superseding
                 * 4. Name of the liturgical event that is superseding
                 * 5. Requested calendar year
                 */
                $message          = _('The %1$s \'%2$s\' is superseded by the %3$s \'%4$s\' in the year %5$d.');
                $this->Messages[] = sprintf(
                    $message,
                    $this->LitGrade->i18n($this->Cal->getLiturgicalEvent($key)->grade),
                    $this->Cal->getLiturgicalEvent($key)->name,
                    $this->LitGrade->i18n($litEvent->grade, false),
                    $litEvent->name,
                    $this->CalendarParams->Year
                );
                $psalter_week     = $this->Cal->getLiturgicalEvent($key)->psalter_week;
                $this->Cal->setProperty($event_key, 'psalter_week', $psalter_week);
                $this->Cal->removeLiturgicalEvent($key);
            }
        }
    }

    /**
     * Handles a coincidence of a liturgical event with a Sunday Solemnity or Feast.
     *
     * @param \stdClass $row the liturgical event that may be coinciding with a Sunday Solemnity or Feast
     * @param string $missal the edition of the Roman Missal to check against
     */
    private function handleCoincidence(\stdClass $row, string $missal = RomanMissal::EDITIO_TYPICA_1970): void
    {
        $coincidingLiturgicalEvent = $this->Cal->determineSundaySolemnityOrFeast($row->date);
        switch ($missal) {
            case RomanMissal::EDITIO_TYPICA_1970:
                $YEAR   = 1970;
                $lang   = in_array(LitLocale::$PRIMARY_LANGUAGE, ['de', 'en', 'it', 'la', 'pt']) ? LitLocale::$PRIMARY_LANGUAGE : 'en';
                $DECREE = "<a href=\"https://www.vatican.va/content/paul-vi/$lang/apost_constitutions/documents/hf_p-vi_apc_19690403_missale-romanum.html\">"
                    . _('Apostolic Constitution Missale Romanum')
                    . '</a>';
                break;
            case RomanMissal::EDITIO_TYPICA_TERTIA_2002:
                $YEAR   = 2002;
                $DECREE = '<a href="https://press.vatican.va/content/salastampa/it/bollettino/pubblico/2002/03/22/0150/00449.html">'
                    . _('Vatican Press conference: Presentation of the Editio Typica Tertia of the Roman Missal')
                    . '</a>';
                break;
            case RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008:
                $YEAR   = 2008;
                $DECREE = '';
                break;
            default:
                $YEAR   = 0; // Default value, should not be used
                $DECREE = '';
        }
        /**translators:
         * 1. Grade or rank of the liturgical event that has been superseded
         * 2. Name of the liturgical event that has been superseded
         * 3. Edition of the Roman Missal
         * 4. Year in which the Edition of the Roman Missal was published
         * 5. Any possible decrees or sources about the edition of the Roman Missal
         * 6. Date in which the superseded liturgical event is usually celebrated
         * 7. Grade or rank of the liturgical event that is superseding
         * 8. Name of the liturgical event that is superseding
         * 9. Requested calendar year
         */
        $locale           = LitLocale::$PRIMARY_LANGUAGE;
        $message          = _('The %1$s \'%2$s\', added in the %3$s of the Roman Missal since the year %4$d (%5$s) and usually celebrated on %6$s, is suppressed by the %7$s \'%8$s\' in the year %9$d.');
        $this->Messages[] = sprintf(
            $message,
            $this->LitGrade->i18n($row->grade, false),
            $row->name,
            RomanMissal::getName($missal),
            $YEAR,
            $DECREE,
            $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                ? ( $row->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[  (int)$row->date->format('n') ] )
                : ( $locale === 'en'
                    ? $row->date->format('F jS')
                    : $this->dayAndMonth->format($row->date->format('U'))
                ),
            $coincidingLiturgicalEvent->grade,
            $coincidingLiturgicalEvent->event->name,
            $this->CalendarParams->Year
        );

        // Add the celebration to LiturgicalEventCollection::suppressedEvents
        $suppressedEvent = new LiturgicalEvent(
            $row->name,
            $row->date,
            $row->color,
            LitFeastType::FIXED,
            $row->grade,
            $row->common
        );
        $this->Cal->addSuppressedEvent($row->event_key, $suppressedEvent);
    }

    /**
     * Adds a message to the list of messages for the calendar indicating that
     * a liturgical event that would have been added to the calendar via a Decree of the Congregation for Divine Worship
     * is however superseded by a Sunday Solemnity or Feast.
     *
     * @param object $row A row from the database containing the information
     *                    about the liturgical event that has been superseded.
     * @return void
     */
    private function handleCoincidenceDecree(object $row): void
    {
        $url = $row->metadata->url;
        if (property_exists($row->metadata, 'url_lang_map') && str_contains($url, '%s')) {
            $lang = $this->getBestLangFromMap($row->metadata->url_lang_map);
            $url  = sprintf($url, $lang);
        }
        $decree                    = '<a href="' . $url . '">' . _('Decree of the Congregation for Divine Worship') . '</a>';
        $coincidingLiturgicalEvent = $this->Cal->determineSundaySolemnityOrFeast($row->liturgical_event->date);
        $locale                    = LitLocale::$PRIMARY_LANGUAGE;
        $this->Messages[]          = sprintf(
            /**translators:
             * 1. Grade or rank of the liturgical event
             * 2. Name of the liturgical event
             * 3. Day of the liturgical event
             * 4. Year from which the liturgical event has been added
             * 5. Source of the information
             * 6. Grade or rank of the superseding liturgical event
             * 7. Name of the superseding liturgical event
             * 8. Requested calendar year
             */
            _('The %1$s \'%2$s\', added on %3$s since the year %4$d (%5$s), is however superseded by a %6$s \'%7$s\' in the year %8$d.'),
            $this->LitGrade->i18n($row->liturgical_event->grade),
            $row->liturgical_event->name,
            $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                ? ( $row->liturgical_event->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[ (int)$row->liturgical_event->date->format('n') ] )
                : ( $locale === 'en'
                    ? $row->liturgical_event->date->format('F jS')
                    : $this->dayAndMonth->format($row->liturgical_event->date->format('U'))
                ),
            $row->metadata->since_year,
            $decree,
            $coincidingLiturgicalEvent->grade,
            $coincidingLiturgicalEvent->event->name,
            $this->CalendarParams->Year
        );
    }

    /**
     * Checks if the liturgical event in $row coincides with the Immaculate Heart of Mary.
     * If it does, it reduces both in rank to optional memorials.
     *
     * @param \stdClass $row The row of data of the liturgical event to check
     * @return bool True if the liturgical event coincides with the Immaculate Heart of Mary, false otherwise
     */
    private function checkImmaculateHeartCoincidence(\stdClass $row): bool
    {
        $coincidence = false;
        //IMMACULATEHEART: in years when the memorial of the Immaculate Heart of Mary coincides with another obligatory memorial,
        //as happened in 2014 [ 28 June, Saint Irenaeus ] and 2015 [ 13 June, Saint Anthony of Padua ], both must be considered optional for that year
        //source: https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
        $ImmaculateHeart = $this->Cal->getLiturgicalEvent('ImmaculateHeart');
        if ($ImmaculateHeart !== null) {
            if ((int)$row->grade === LitGrade::MEMORIAL) {
                if ($row->date->format('U') === $ImmaculateHeart->date->format('U')) {
                    $this->Cal->setProperty('ImmaculateHeart', 'grade', LitGrade::MEMORIAL_OPT);
                    $litEvent = $this->Cal->getLiturgicalEvent($row->event_key);
                    if ($litEvent === null) {
                        $litEvent = new LiturgicalEvent($row->name, $row->date, $row->color, LitFeastType::FIXED, LitGrade::MEMORIAL_OPT, $row->common);
                        $this->Cal->addLiturgicalEvent($row->event_key, $litEvent);
                    } else {
                        $this->Cal->setProperty($row->event_key, 'grade', LitGrade::MEMORIAL_OPT);
                    }
                    $this->Messages[] = sprintf(
                        /**translators:
                         * 1. Name of the first coinciding Memorial
                         * 2. Name of the second coinciding Memorial
                         * 3. Requested calendar year
                         * 4. Source of the information
                         */
                        _('The Memorial \'%1$s\' coincides with another Memorial \'%2$s\' in the year %3$d. They are both reduced in rank to optional memorials (%4$s).'),
                        $ImmaculateHeart->name,
                        $litEvent->name,
                        $this->CalendarParams->Year,
                        '<a href="https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html">'
                            . _('Decree of the Congregation for Divine Worship')
                            . '</a>'
                    );
                    $coincidence = true;
                }
            }
        }
        return $coincidence;
    }

    /**
     * Handle a mobile liturgical event whose date is specified using the "strtotime" property
     *
     * @param object $row the row containing data for the mobile liturgical event from the JSON file
     * @return void
     */
    private function handleLiturgicalEventDecreeTypeMobile(object $row): void
    {
        //we won't have a date defined for mobile festivites, we'll have to calculate them here case by case
        //otherwise we'll have to create a language that we can interpret in an automated fashion...
        //for example we can use strtotime
        if (property_exists($row->metadata, 'strtotime')) {
            switch (gettype($row->metadata->strtotime)) {
                case 'object':
                    if (
                        property_exists($row->metadata->strtotime, 'day_of_the_week')
                        && property_exists($row->metadata->strtotime, 'relative_time')
                        && property_exists($row->metadata->strtotime, 'event_key')
                    ) {
                        $litEvent = $this->Cal->getLiturgicalEvent($row->metadata->strtotime->event_key);
                        if ($litEvent !== null) {
                            $relString                   = '';
                            $row->liturgical_event->date = clone( $litEvent->date );

                            switch ($row->metadata->strtotime->relative_time) {
                                case 'before':
                                    $row->liturgical_event->date->modify("previous {$row->metadata->strtotime->day_of_the_week}");
                                        /**translators: e.g. 'Monday before Palm Sunday' */
                                    $relString = _('before');
                                    break;
                                case 'after':
                                    $row->liturgical_event->date->modify("next {$row->metadata->strtotime->day_of_the_week}");
                                    /**translators: e.g. 'Monday after Pentecost' */
                                    $relString = _('after');
                                    break;
                                default:
                                    $this->Messages[] = sprintf(
                                        /**translators: 1. Name of the mobile liturgical event being created, 2. Name of the liturgical event that it is relative to */
                                        _('Cannot create mobile liturgical event \'%1$s\': can only be relative to liturgical event with key \'%2$s\' using keywords %3$s'),
                                        $row->liturgical_event->name,
                                        $row->metadata->strtotime->event_key,
                                        implode(', ', ['\'before\'', '\'after\''])
                                    );
                                    break;
                            }

                            $dayOfTheWeek = $this->CalendarParams->Locale === LitLocale::LATIN
                                ? LatinUtils::LATIN_DAYOFTHEWEEK[ $row->liturgical_event->date->format('w') ]
                                : ucfirst($this->dayOfTheWeek->format($row->liturgical_event->date->format('U')));

                            $row->metadata->added_when = $dayOfTheWeek . ' ' . $relString . ' ' . $litEvent->name;
                            if (true === $this->checkCoincidencesNewMobileLiturgicalEvent($row)) {
                                $this->createMobileLiturgicalEvent($row);
                            }
                        } else {
                            $this->Messages[] = sprintf(
                                /**translators: 1. Name of the mobile liturgical event being created, 2. Name of the liturgical event that it is relative to */
                                _('Cannot create mobile liturgical event \'%1$s\' relative to liturgical event with key \'%2$s\''),
                                $row->liturgical_event->name,
                                $row->metadata->strtotime->event_key
                            );
                        }
                    } else {
                        $this->Messages[] = sprintf(
                            /**translators: Do not translate 'strtotime'! 1. Name of the mobile liturgical event being created 2. list of properties */
                            _('Cannot create mobile liturgical event \'%1$s\': when the \'strtotime\' property is an object, it must have properties %2$s'),
                            $row->liturgical_event->name,
                            implode(', ', ['\'day_of_the_week\'', '\'relative_time\'', '\'event_key\''])
                        );
                    }
                    break;
                case 'string':
                    if ($row->metadata->strtotime !== '') {
                        $litEventDateTS              = strtotime($row->metadata->strtotime . ' ' . $this->CalendarParams->Year . ' UTC');
                        $row->liturgical_event->date = new DateTime("@$litEventDateTS");
                        $row->liturgical_event->date->setTimeZone(new \DateTimeZone('UTC'));
                        $row->metadata->added_when = $row->metadata->strtotime;
                        if (true === $this->checkCoincidencesNewMobileLiturgicalEvent($row)) {
                            $this->createMobileLiturgicalEvent($row);
                        }
                    }
                    break;
                default:
                    $this->Messages[] = sprintf(
                        /**translators: Do not translate 'strtotime'! 1. Name of the mobile liturgical event being created */
                        _('Cannot create mobile liturgical event \'%1$s\': \'strtotime\' property must be either an object or a string! Currently it has type \'%2$s\''),
                        $row->liturgical_event->name,
                        gettype($row->metadata->strtotime)
                    );
            }
        } else {
            $this->Messages[] = sprintf(
                /**translators: Do not translate 'strtotime'! 1. Name of the mobile liturgical event being created */
                _('Cannot create mobile liturgical event \'%1$s\' without a \'strtotime\' property!'),
                $row->liturgical_event->name
            );
        }
    }

    /**
     * Handle a fixed liturgical event whose date is specified using the "day" and "month" properties
     *
     * @param object $row the row containing data for the fixed liturgical event from the JSON file
     * @return void
     */
    private function handleLiturgicalEventDecreeTypeFixed(object $row): void
    {
        $row->liturgical_event->date = DateTime::createFromFormat(
            '!j-n-Y',
            "{$row->liturgical_event->day}-{$row->liturgical_event->month}-{$this->CalendarParams->Year}",
            new \DateTimeZone('UTC')
        );

        $decree = $this->elaborateDecreeSource($row);
        $locale = LitLocale::$PRIMARY_LANGUAGE;

        if ($row->liturgical_event->grade === LitGrade::MEMORIAL_OPT) {
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($row->liturgical_event->date)) {
                $litEvent = new LiturgicalEvent(
                    $row->liturgical_event->name,
                    $row->liturgical_event->date,
                    $row->liturgical_event->color,
                    LitFeastType::FIXED,
                    $row->liturgical_event->grade,
                    $row->liturgical_event->common
                );
                $this->Cal->addLiturgicalEvent($row->liturgical_event->event_key, $litEvent);
                $this->Messages[] = sprintf(
                    /**translators:
                     * 1. Grade or rank of the liturgical event
                     * 2. Name of the liturgical event
                     * 3. Day of the liturgical event
                     * 4. Year from which the liturgical event has been added
                     * 5. Source of the information
                     * 6. Requested calendar year
                     */
                    _('The %1$s \'%2$s\' has been added on %3$s since the year %4$d (%5$s), applicable to the year %6$d.'),
                    $this->LitGrade->i18n($row->liturgical_event->grade, false),
                    $row->liturgical_event->name,
                    $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                        ? ( $row->liturgical_event->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[ (int)$row->liturgical_event->date->format('n') ] )
                        : ( $locale === 'en' ? $row->liturgical_event->date->format('F jS') :
                            $this->dayAndMonth->format($row->liturgical_event->date->format('U'))
                        ),
                    $row->metadata->since_year,
                    $decree,
                    $this->CalendarParams->Year
                );
            } else {
                $this->handleCoincidenceDecree($row);
            }
        }
    }


    /**
     * Creates a LiturgicalEvent object based on information from a Decree of the Congregation for Divine Worship
     * and adds it to the calendar.
     * @param object $row The row from the database containing the information about the liturgical event
     * @return void
     */
    private function createLiturgicalEventFromDecree(object $row): void
    {
        $litEventType = $row->liturgical_event->type;
        if ('mobile' === $litEventType) {
            $this->handleLiturgicalEventDecreeTypeMobile($row);
        } else {
            $this->handleLiturgicalEventDecreeTypeFixed($row);
        }
    }

    /**
     * Sets a property of a liturgical event (name, grade) based on a Decree of the Congregation for Divine Worship
     * and adds a message to the list of messages for the calendar indicating that the property has been changed.
     * @param object $row A row from the database containing the information
     *                    about the liturgical event whose property is being changed.
     * @return void
     */
    private function setPropertyBasedOnDecree(object $row): void
    {
        $litEvent = $this->Cal->getLiturgicalEvent($row->liturgical_event->event_key);
        if ($litEvent !== null) {
            $decree = $this->elaborateDecreeSource($row);
            switch ($row->metadata->property) {
                case 'name':
                    //example: StMartha becomes Martha, Mary and Lazarus in 2021
                    $this->Cal->setProperty($row->liturgical_event->event_key, 'name', $row->liturgical_event->name);
                    /**translators:
                     * 1. Grade or rank of the liturgical event
                     * 2. Name of the liturgical event
                     * 3. New name of the liturgical event
                     * 4. Year from which the grade has been changed
                     * 5. Requested calendar year
                     * 6. Source of the information
                     */
                    $message          = _('The name of the %1$s \'%2$s\' has been changed to %3$s since the year %4$d, applicable to the year %5$d (%6$s).');
                    $this->Messages[] = sprintf(
                        $message,
                        $this->LitGrade->i18n($litEvent->grade, false),
                        '<i>' . $litEvent->name . '</i>',
                        '<i>' . $row->liturgical_event->name . '</i>',
                        $row->metadata->since_year,
                        $this->CalendarParams->Year,
                        $decree
                    );
                    break;
                case 'grade':
                    if ($row->liturgical_event->grade > $litEvent->grade) {
                        //example: StMaryMagdalene raised to Feast in 2016
                        /**translators:
                         * 1. Grade or rank of the liturgical event
                         * 2. Name of the liturgical event
                         * 3. New grade of the liturgical event
                         * 4. Year from which the grade has been changed
                         * 5. Requested calendar year
                         * 6. Source of the information
                         */
                        $message = _('The %1$s \'%2$s\' has been raised to the rank of %3$s since the year %4$d, applicable to the year %5$d (%6$s).');
                    } else {
                        /**translators:
                         * 1. Grade or rank of the liturgical event
                         * 2. Name of the liturgical event
                         * 3. New grade of the liturgical event
                         * 4. Year from which the grade has been changed
                         * 5. Requested calendar year
                         * 6. Source of the information
                         */
                        $message = _('The %1$s \'%2$s\' has been lowered to the rank of %3$s since the year %4$d, applicable to the year %5$d (%6$s).');
                    }
                    $this->Messages[] = sprintf(
                        $message,
                        $this->LitGrade->i18n($litEvent->grade, false),
                        $litEvent->name,
                        $this->LitGrade->i18n($row->liturgical_event->grade, false),
                        $row->metadata->since_year,
                        $this->CalendarParams->Year,
                        $decree
                    );
                    $this->Cal->setProperty($row->liturgical_event->event_key, 'grade', $row->liturgical_event->grade);
                    break;
            }
        }
    }

    /**
     * This function takes the list of Decrees of the Congregation for Divine Worship
     * which have elevated a liturgical event to the rank of Doctor of the Church and
     * updates the calendar accordingly.
     *
     * In particular, it checks if the year of the decree is not later than
     * the year of the calendar being generated, and if the liturgical event is
     * already present in the calendar. If so, it updates the name of the
     * liturgical event and adds a message to the list of messages for the calendar
     * indicating that the property has been changed.
     *
     * @return void
     */
    private function createDoctorsFromDecrees(): void
    {
        $DoctorsDecrees = array_filter(
            $this->tempCal[ 'MEMORIALS_FROM_DECREES' ],
            function ($row) {
                return $row->metadata->action === 'makeDoctor';
            }
        );

        foreach ($DoctorsDecrees as $row) {
            if ($this->CalendarParams->Year >= $row->metadata->since_year) {
                $litEvent = $this->Cal->getLiturgicalEvent($row->liturgical_event->event_key);
                if ($litEvent !== null) {
                    $decree = $this->elaborateDecreeSource($row);
                    /**translators:
                     * 1. Name of the liturgical event
                     * 2. Year in which was declared Doctor
                     * 3. Requested calendar year
                     * 4. Source of the information
                     */
                    $message          = _('\'%1$s\' has been declared a Doctor of the Church since the year %2$d, applicable to the year %3$d (%4$s).');
                    $this->Messages[] = sprintf(
                        $message,
                        '<i>' . $litEvent->name . '</i>',
                        $row->metadata->since_year,
                        $this->CalendarParams->Year,
                        $decree
                    );
                    $etDoctor         = $this->CalendarParams->Locale === LitLocale::LATIN ? ' et Ecclesiæ doctoris' : ' ' . _('and Doctor of the Church');
                    $this->Cal->setProperty($row->liturgical_event->event_key, 'name', $litEvent->name . $etDoctor);
                }
            }
        }
    }

    /**
     * Given an object with language codes as properties, returns the string
     * associated with the best language code available.
     *
     * The best language code is determined as follows:
     *
     * 1. If the object has a property with the value of
     *    {@see LitLocale::$PRIMARY_LANGUAGE}, that value is returned.
     * 2. If the object has a "la" property, that value is returned.
     * 3. If the object has an "en" property, that value is returned.
     * 4. Otherwise, the key of the first property in the object is returned.
     *
     * @param object $map The object with language codes as properties
     *
     * @return string The string associated with the best language code
     */
    private function getBestLangFromMap(object $map): string
    {
        if (property_exists($map, LitLocale::$PRIMARY_LANGUAGE)) {
            return $map->{LitLocale::$PRIMARY_LANGUAGE};
        } elseif (property_exists($map, 'la')) {
            return $map->la;
        } elseif (property_exists($map, 'en')) {
            return $map->en;
        } else {
            $mapArray = (array)$map;
            return reset($mapArray);
        }
    }

    /**
     * Given a decree object, returns an HTML string representing the decree source,
     * with a link to the original decree document.
     *
     * If the decree URL contains a language placeholder, it is replaced with the
     * best language code available from the language map.
     *
     * @param object $row The decree object
     *
     * @return string The HTML string representing the decree source
     */
    private function elaborateDecreeSource(object $row): string
    {
        $url = $row->metadata->url;
        if (property_exists($row->metadata, 'url_lang_map') && str_contains($url, '%s')) {
            $lang = $this->getBestLangFromMap($row->metadata->url_lang_map);
            $url  = sprintf($url, $lang);
        }
        return '<a href="' . $url . '">' . _('Decree of the Congregation for Divine Worship') . '</a>';
    }

    /**
     * Applies memorials based on Decrees of the Congregation for Divine Worship to the calendar.
     *
     * @param int|string $grade The grade of the liturgical event (e.g. 'memorial', 'feast', etc.)
     *                          or the special string "DOCTORS" to apply the Decrees related to Doctors of the Church.
     *                          Defaults to LitGrade::MEMORIAL if not provided.
     * @return void
     */
    private function applyDecrees(int|string $grade = LitGrade::MEMORIAL): void
    {
        if (!isset($this->tempCal[ 'MEMORIALS_FROM_DECREES' ]) || !is_array($this->tempCal[ 'MEMORIALS_FROM_DECREES' ])) {
            $message = 'We seem to be missing data for Memorials based on Decrees: array data was not found!';
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
        }
        if (gettype($grade) === 'integer') {
            $MemorialsFromDecrees = array_filter(
                $this->tempCal[ 'MEMORIALS_FROM_DECREES' ],
                function ($row) use ($grade) {
                    return $row->metadata->action !== 'makeDoctor' && $row->liturgical_event->grade === $grade;
                }
            );

            foreach ($MemorialsFromDecrees as $row) {
                if ($this->CalendarParams->Year >= $row->metadata->since_year) {
                    switch ($row->metadata->action) {
                        case 'createNew':
                            //example: MaryMotherChurch in 2018
                            $this->createLiturgicalEventFromDecree($row);
                            break;
                        case 'setProperty':
                            $this->setPropertyBasedOnDecree($row);
                            break;
                    }
                }
            }

            if ($this->CalendarParams->Year === 2009) {
                //Conversion of St. Paul falls on a Sunday in the year 2009
                //Faculty to celebrate as optional memorial
                $this->applyOptionalMemorialDecree2009();
            }
        } elseif ($grade === 'DOCTORS') {
            $this->createDoctorsFromDecrees();
        }
    }

    /**
     * Creates a mobile LiturgicalEvent object based on information from a Decree of the Congregation for Divine Worship
     * and adds it to the calendar.
     * @param object $row The row from the database containing the information about the liturgical event
     * @return void
     */
    private function createMobileLiturgicalEvent(object $row): void
    {
        $litEvent = new LiturgicalEvent(
            $row->liturgical_event->name,
            $row->liturgical_event->date,
            $row->liturgical_event->color,
            LitFeastType::MOBILE,
            $row->liturgical_event->grade,
            $row->liturgical_event->common
        );
        $this->Cal->addLiturgicalEvent($row->liturgical_event->event_key, $litEvent);
        $url = $row->metadata->url;
        if (property_exists($row->metadata, 'url_lang_map') && str_contains($url, '%s')) {
            $lang = $this->getBestLangFromMap($row->metadata->url_lang_map);
            $url  = sprintf($url, $lang);
        }
        $decree = '<a href="' . $url . '">' . _('Decree of the Congregation for Divine Worship') . '</a>';

        $this->Messages[] = sprintf(
            /**translators:
             * 1. Grade or rank of the liturgical event being created
             * 2. Name of the liturgical event being created
             * 3. Indication of the mobile date for the liturgical event being created
             * 4. Year from which the liturgical event has been added
             * 5. Source of the information
             * 6. Requested calendar year
             */
            _('The %1$s \'%2$s\' has been added on %3$s since the year %4$d (%5$s), applicable to the year %6$d.'),
            $this->LitGrade->i18n($row->liturgical_event->grade, false),
            $row->liturgical_event->name,
            $row->metadata->added_when,
            $row->metadata->since_year,
            $decree,
            $this->CalendarParams->Year
        );
    }

    /**
     * Checks if a newly added mobile liturgical event of grade Memorial has any coincidences
     * with existing Solemnities or Feasts in the calendar, and if so, removes the
     * coinciding liturgical event from the calendar and adds a message to the list of messages
     * indicating what has happened.
     *
     * @param object $row The row from the database containing the information about the liturgical event
     * @return bool True if the mobile liturgical event can be added to the calendar, false if it has been
     *              superseded by a Solemnity or Feast.
     */
    private function checkCoincidencesNewMobileLiturgicalEvent(object $row): bool
    {
        if ($row->liturgical_event->grade === LitGrade::MEMORIAL) {
            $url = $row->metadata->url;
            if (property_exists($row->metadata, 'url_lang_map') && str_contains($url, '%s')) {
                $lang = $this->getBestLangFromMap($row->metadata->url_lang_map);
                $url  = sprintf($url, $lang);
            }
            $decree = '<a href="' . $url . '">' . _('Decree of the Congregation for Divine Worship') . '</a>';

            //A Memorial is superseded by Solemnities and Feasts, but not by Memorials of Saints
            if ($this->Cal->inSolemnities($row->liturgical_event->date) || $this->Cal->inFeasts($row->liturgical_event->date)) {
                if ($this->Cal->inSolemnities($row->liturgical_event->date)) {
                    $coincidingLiturgicalEvent = $this->Cal->solemnityFromDate($row->liturgical_event->date);
                } else {
                    $coincidingLiturgicalEvent = $this->Cal->feastOrMemorialFromDate($row->liturgical_event->date);
                }

                $this->Messages[] = sprintf(
                    /**translators:
                     * 1. Grade or rank of the liturgical event being created
                     * 2. Name of the liturgical event being created
                     * 3. Indication of the mobile date for the liturgical event being created
                     * 4. Year from which the liturgical event has been added
                     * 5. Source of the information
                     * 6. Grade or rank of superseding liturgical event
                     * 7. Name of superseding liturgical event
                     * 8. Requested calendar year
                     */
                    _('The %1$s \'%2$s\', added on %3$s since the year %4$d (%5$s), is however superseded by the %6$s \'%7$s\' in the year %8$d.'),
                    $this->LitGrade->i18n($row->liturgical_event->grade, false),
                    '<i>' . $row->liturgical_event->name . '</i>',
                    $row->metadata->added_when,
                    $row->metadata->since_year,
                    $decree,
                    $coincidingLiturgicalEvent->grade,
                    '<i>' . $coincidingLiturgicalEvent->name . '</i>',
                    $this->CalendarParams->Year
                );
                return false;
            } else {
                if ($this->Cal->inCalendar($row->liturgical_event->date)) {
                    $coincidingLiturgicalEvents = $this->Cal->getCalEventsFromDate($row->liturgical_event->date);
                    if (count($coincidingLiturgicalEvents) > 0) {
                        foreach ($coincidingLiturgicalEvents as $coincidingLiturgicalEventKey => $coincidingLiturgicalEvent) {
                            $this->Messages[] = sprintf(
                                /**translators:
                                 * 1. Requested calendar year
                                 * 2. Grade or rank of suppressed liturgical event
                                 * 3. Name of suppressed liturgical event
                                 * 4. Grade or rank of the liturgical event being created
                                 * 5. Name of the liturgical event being created
                                 * 6. Indication of the mobile date for the liturgical event being created
                                 * 7. Year from which the liturgical event has been added
                                 * 8. Source of the information
                                 */
                                _('In the year %1$d, the %2$s \'%3$s\' has been suppressed by the %4$s \'%5$s\', added on %6$s since the year %7$d (%8$s).'),
                                $this->CalendarParams->Year,
                                $this->LitGrade->i18n($coincidingLiturgicalEvent->grade, false),
                                '<i>' . $coincidingLiturgicalEvent->name . '</i>',
                                $this->LitGrade->i18n($row->liturgical_event->grade, false),
                                '<i>' . $row->liturgical_event->name . '</i>',
                                $row->metadata->added_when,
                                $row->metadata->since_year,
                                $decree
                            );
                            $this->Cal->removeLiturgicalEvent($coincidingLiturgicalEventKey);
                        }
                    }
                }
                return true;
            }
        }
        return false;
    }

    /**
     * Creates the memorial of the Immaculate Heart of Mary, if it does not fall on a Solemnity or Feast.
     * If it does not, we check if it coincides with another obligatory memorial in which case both are reduced to optional memorials.
     *
     * @return void
     *
     * @see https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
     */
    private function createImmaculateHeart()
    {
        $row       = new \stdClass();
        $row->date = Utilities::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P' . ( 7 * 9 + 6 ) . 'D'));
        if ($this->Cal->notInSolemnitiesFeastsOrMemorials($row->date)) {
            //Immaculate Heart of Mary fixed on the Saturday following the second Sunday after Pentecost
            //( see Calendarium Romanum Generale in Missale Romanum Editio Typica 1970 )
            //Pentecost = Utilities::calcGregEaster( $this->CalendarParams->Year )->add( new \DateInterval( 'P'.( 7*7 ).'D' ) )
            //Second Sunday after Pentecost = Utilities::calcGregEaster( $this->CalendarParams->Year )->add( new \DateInterval( 'P'.( 7*9 ).'D' ) )
            //Following Saturday = Utilities::calcGregEaster( $this->CalendarParams->Year )->add( new \DateInterval( 'P'.( 7*9+6 ).'D' ) )
            $this->Cal->addLiturgicalEvent(
                'ImmaculateHeart',
                new LiturgicalEvent(
                    $this->PropriumDeTempore[ 'ImmaculateHeart' ][ 'name' ],
                    $row->date,
                    LitColor::WHITE,
                    LitFeastType::MOBILE,
                    LitGrade::MEMORIAL
                )
            );

            //In years when this memorial coincides with another obligatory memorial, as happened in 2014 [ 28 June, Saint Irenaeus ] and 2015 [ 13 June, Saint Anthony of Padua ],
            // both must be considered optional for that year
            //source: https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
            //This is taken care of in the next code cycle, see tag IMMACULATEHEART: in the code comments ahead
        } else {
            $row            = (object)$this->PropriumDeTempore[ 'ImmaculateHeart' ];
            $row->event_key = 'ImmaculateHeart';
            $row->grade     = LitGrade::MEMORIAL;
            $row->date      = Utilities::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P' . ( 7 * 9 + 6 ) . 'D'));
            $row->common    = [];
            $this->handleCoincidence($row, RomanMissal::EDITIO_TYPICA_1970);
        }
    }

    /**
     * In the Tertia Editio Typica (2002),
     * Saint Jane Frances de Chantal was moved from December 12 to August 12,
     * probably to allow local bishop's conferences to insert Our Lady of Guadalupe as an optional memorial on December 12
     * seeing that with the decree of March 25th 1999 of the Congregation of Divine Worship
     * Our Lady of Guadalupe was granted as a Feast day for all dioceses and territories of the Americas
     * source: https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_lt.html
     */
    private function handleSaintJaneFrancesDeChantal(): void
    {
        $StJaneFrancesNewDate = DateTime::createFromFormat('!j-n-Y', '12-8-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));

        $langs = ['la' => 'lt', 'es' => 'es'];
        $lang  = in_array(LitLocale::$PRIMARY_LANGUAGE, array_keys($langs)) ? $langs[LitLocale::$PRIMARY_LANGUAGE] : 'lt';

        if (self::dateIsNotSunday($StJaneFrancesNewDate) && $this->Cal->notInSolemnitiesFeastsOrMemorials($StJaneFrancesNewDate)) {
            $litEvent = $this->Cal->getLiturgicalEvent('StJaneFrancesDeChantal');
            if ($litEvent !== null) {
                $this->Cal->moveLiturgicalEventDate('StJaneFrancesDeChantal', $StJaneFrancesNewDate);
                $this->Messages[] = sprintf(
                    /**translators: 1: LiturgicalEvent name, 2: Source of the information, 3: Requested calendar year  */
                    _('The optional memorial \'%1$s\' has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d.'),
                    $litEvent->name,
                    "<a href=\"https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_$lang.html\">"
                        . _('Decree of the Congregation for Divine Worship')
                        . '</a>',
                    $this->CalendarParams->Year
                );
            } else {
                //perhaps it wasn't created on December 12th because it was superseded by a Sunday, Solemnity or Feast
                //but seeing that there is no problem for August 12th, let's go ahead and try creating it again
                $row      = $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ][ 'StJaneFrancesDeChantal' ];
                $litEvent = new LiturgicalEvent($row->name, $StJaneFrancesNewDate, $row->color, LitFeastType::FIXED, $row->grade, $row->common);
                $this->Cal->addLiturgicalEvent('StJaneFrancesDeChantal', $litEvent);
                $this->Messages[] = sprintf(
                    /**translators: 1: LiturgicalEvent name, 2: Source of the information, 3: Requested calendar year  */
                    _('The optional memorial \'%1$s\', which would have been superseded this year by a Sunday or Solemnity were it on Dec. 12, has however been transferred to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d.'),
                    $litEvent->name,
                    "<a href=\"https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_$lang.html\">"
                        . _('Decree of the Congregation for Divine Worship')
                        . '</a>',
                    $this->CalendarParams->Year
                );
            }
        } else {
            $coincidingLiturgicalEvent = $this->Cal->determineSundaySolemnityOrFeast($StJaneFrancesNewDate);
            $litEvent                  = $this->Cal->getLiturgicalEvent('StJaneFrancesDeChantal');
            //we can't move it, but we still need to remove it from Dec 12th if it's there!!!
            if ($litEvent !== null) {
                $this->Cal->removeLiturgicalEvent('StJaneFrancesDeChantal');
            }
            $row              = $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ][ 'StJaneFrancesDeChantal' ];
            $this->Messages[] = sprintf(
                _('The optional memorial \'%1$s\' has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d. However, it is superseded by a Sunday, a Solemnity, or a Feast \'%4$s\' in the year %3$d.'),
                $row->name,
                "<a href=\"https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_$lang.html\">"
                    . _('Decree of the Congregation for Divine Worship')
                    . '</a>',
                $this->CalendarParams->Year,
                $coincidingLiturgicalEvent->event->name
            );
        }
    }


    /**
     * The Conversion of St. Paul falls on a Sunday in the year 2009.
     * However, considering that it is the Year of Saint Paul,
     * with decree of Jan 25 2008 the Congregation for Divine Worship gave faculty to the single churches
     * to celebrate the Conversion of St. Paul anyways. So let's re-insert it as an optional memorial?
     * https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20080125_san-paolo_la.html
     */
    private function applyOptionalMemorialDecree2009(): void
    {
        $litEvent = $this->Cal->getLiturgicalEvent('ConversionStPaul');
        if ($litEvent === null) {
            $row      = $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ][ 'ConversionStPaul' ];
            $litEvent = new LiturgicalEvent(
                $row->name,
                DateTime::createFromFormat(
                    '!j-n-Y',
                    '25-1-2009',
                    new \DateTimeZone('UTC')
                ),
                LitColor::WHITE,
                LitFeastType::FIXED,
                LitGrade::MEMORIAL_OPT,
                LitCommon::PROPRIO
            );
            $this->Cal->addLiturgicalEvent('ConversionStPaul', $litEvent);
            $langs            = ['fr' => 'fr', 'en' => 'en', 'it' => 'it', 'la' => 'lt', 'pt' => 'pt', 'es' => 'sp', 'de' => 'ge'];
            $lang             = in_array(LitLocale::$PRIMARY_LANGUAGE, array_keys($langs)) ? $langs[LitLocale::$PRIMARY_LANGUAGE] : 'en';
            $this->Messages[] = sprintf(
                /**translators: 1: LiturgicalEvent name, 2: Source of the information  */
                _('The Feast \'%1$s\' would have been suppressed this year ( 2009 ) since it falls on a Sunday, however being the Year of the Apostle Paul, as per the %2$s it has been reinstated so that local churches can optionally celebrate the memorial.'),
                '<i>' . $row->name . '</i>',
                "<a href=\"https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20080125_san-paolo_$lang.html\">"
                    . _('Decree of the Congregation for Divine Worship')
                    . '</a>'
            );
        }
    }

    //13. Weekdays of Advent up until Dec. 16 included ( already calculated and defined together with weekdays 17 Dec. - 24 Dec., @see calculateWeekdaysAdvent() )
    //    Weekdays of Christmas season from 2 Jan. until the Saturday after Epiphany (@see calculateWeekdaysChristmasOctave())
    //    Weekdays of the Easter season, from the Monday after the Octave of Easter to the Saturday before Pentecost
    private function calculateWeekdaysEaster(): void
    {
        $DoMEaster   = $this->Cal->getLiturgicalEvent('Easter')->date->format('j');      //day of the month of Easter
        $MonthEaster = $this->Cal->getLiturgicalEvent('Easter')->date->format('n');    //month of Easter
        //let's start cycling dates one at a time starting from Easter itself
        $weekdayEaster    = DateTime::createFromFormat('!j-n-Y', $DoMEaster . '-' . $MonthEaster . '-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
        $weekdayEasterCnt = 1;
        while ($weekdayEaster >= $this->Cal->getLiturgicalEvent('Easter')->date && $weekdayEaster < $this->Cal->getLiturgicalEvent('Pentecost')->date) {
            $weekdayEaster = DateTime::createFromFormat(
                '!j-n-Y',
                $DoMEaster . '-' . $MonthEaster . '-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            )->add(new \DateInterval('P' . $weekdayEasterCnt . 'D'));
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($weekdayEaster) && self::dateIsNotSunday($weekdayEaster)) {
                $upper                  = (int)$weekdayEaster->format('z');
                $diff                   = $upper - (int)$this->Cal->getLiturgicalEvent('Easter')->date->format('z'); //day count between current day and Easter Sunday
                $currentEasterWeek      = ( ( $diff - $diff % 7 ) / 7 ) + 1; //week count between current day and Easter Sunday
                $ordinal                = ucfirst(Utilities::getOrdinal($currentEasterWeek, $this->CalendarParams->Locale, $this->formatterFem, LatinUtils::LATIN_ORDINAL_FEM_GEN));
                $dayOfTheWeek           = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? LatinUtils::LATIN_DAYOFTHEWEEK[ $weekdayEaster->format('w') ]
                    : ucfirst($this->dayOfTheWeek->format($weekdayEaster->format('U')));
                $t                      = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? sprintf('Hebdomadæ %s Temporis Paschali', $ordinal)
                    : sprintf(_('of the %s Week of Easter'), $ordinal);
                $name                   = $dayOfTheWeek . ' ' . $t;
                $litEvent               = new LiturgicalEvent($name, $weekdayEaster, LitColor::WHITE, LitFeastType::MOBILE);
                $litEvent->psalter_week = $this->Cal::psalterWeek($currentEasterWeek);
                $this->Cal->addLiturgicalEvent('EasterWeekday' . $weekdayEasterCnt, $litEvent);
            }
            $weekdayEasterCnt++;
        }
    }

    //    Weekdays of Ordinary time
    private function calculateWeekdaysOrdinaryTime(): void
    {

        //In the first part of the year, weekdays of ordinary time begin the day after the Baptism of the Lord
        $FirstWeekdaysLowerLimit = $this->Cal->getLiturgicalEvent('BaptismLord')->date;
        //and end with Ash Wednesday
        $FirstWeekdaysUpperLimit = $this->Cal->getLiturgicalEvent('AshWednesday')->date;

        $ordWeekday     = 1;
        $currentOrdWeek = 1;
        $firstOrdinary  = DateTime::createFromFormat('!j-n-Y', $this->BaptismLordFmt, new \DateTimeZone('UTC'))->modify($this->BaptismLordMod);
        $firstSunday    = DateTime::createFromFormat('!j-n-Y', $this->BaptismLordFmt, new \DateTimeZone('UTC'))->modify($this->BaptismLordMod)->modify('next Sunday');
        $dayFirstSunday =  (int)$firstSunday->format('z');

        while ($firstOrdinary >= $FirstWeekdaysLowerLimit && $firstOrdinary < $FirstWeekdaysUpperLimit) {
            $firstOrdinary = DateTime::createFromFormat(
                '!j-n-Y',
                $this->BaptismLordFmt,
                new \DateTimeZone('UTC')
            )->modify($this->BaptismLordMod)->add(new \DateInterval('P' . $ordWeekday . 'D'));
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($firstOrdinary)) {
                //The Baptism of the Lord is the First Sunday, so the weekdays following are of the First Week of Ordinary Time
                //After the Second Sunday, let's calculate which week of Ordinary Time we're in
                if ($firstOrdinary > $firstSunday) {
                    $upper          = (int)$firstOrdinary->format('z');
                    $diff           = $upper - $dayFirstSunday;
                    $currentOrdWeek = ( ( $diff - $diff % 7 ) / 7 ) + 2;
                }
                $ordinal                = ucfirst(Utilities::getOrdinal($currentOrdWeek, $this->CalendarParams->Locale, $this->formatterFem, LatinUtils::LATIN_ORDINAL_FEM_GEN));
                $dayOfTheWeek           = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? LatinUtils::LATIN_DAYOFTHEWEEK[ $firstOrdinary->format('w') ]
                    : ucfirst($this->dayOfTheWeek->format($firstOrdinary->format('U')));
                $nthStr                 = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? sprintf('Hebdomadæ %s Temporis Ordinarii', $ordinal)
                    : sprintf(_('of the %s Week of Ordinary Time'), $ordinal);
                $name                   = $dayOfTheWeek . ' ' . $nthStr;
                $litEvent               = new LiturgicalEvent($name, $firstOrdinary, LitColor::GREEN, LitFeastType::MOBILE);
                $litEvent->psalter_week = $this->Cal::psalterWeek($currentOrdWeek);
                $this->Cal->addLiturgicalEvent('FirstOrdWeekday' . $ordWeekday, $litEvent);
            }
            $ordWeekday++;
        }

        //In the second part of the year, weekdays of ordinary time begin the day after Pentecost
        $SecondWeekdaysLowerLimit = $this->Cal->getLiturgicalEvent('Pentecost')->date;
        //and end with the Feast of Christ the King
        $SecondWeekdaysUpperLimit = DateTime::createFromFormat(
            '!j-n-Y',
            '25-12-' . $this->CalendarParams->Year,
            new \DateTimeZone('UTC')
        )->modify('last Sunday')->sub(new \DateInterval('P' . ( 3 * 7 ) . 'D'));

        //$currentOrdWeek = 1;
        $ordWeekday    = 1;
        $lastOrdinary  = Utilities::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P' . ( 7 * 7 ) . 'D'));
        $dayLastSunday =  (int)DateTime::createFromFormat(
            '!j-n-Y',
            '25-12-' . $this->CalendarParams->Year,
            new \DateTimeZone('UTC')
        )->modify('last Sunday')->sub(new \DateInterval('P' . ( 3 * 7 ) . 'D'))->format('z');

        while ($lastOrdinary >= $SecondWeekdaysLowerLimit && $lastOrdinary < $SecondWeekdaysUpperLimit) {
            $lastOrdinary = Utilities::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P' . ( 7 * 7 + $ordWeekday ) . 'D'));
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($lastOrdinary)) {
                $lower          = (int)$lastOrdinary->format('z');
                $diff           = $dayLastSunday - $lower; //day count between current day and Christ the King Sunday
                $weekDiff       = ( ( $diff - $diff % 7 ) / 7 ); //week count between current day and Christ the King Sunday;
                $currentOrdWeek = 34 - $weekDiff;
                $ordinal        = ucfirst(Utilities::getOrdinal($currentOrdWeek, $this->CalendarParams->Locale, $this->formatterFem, LatinUtils::LATIN_ORDINAL_FEM_GEN));
                $dayOfTheWeek   = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? LatinUtils::LATIN_DAYOFTHEWEEK[ $lastOrdinary->format('w') ]
                    : ucfirst($this->dayOfTheWeek->format($lastOrdinary->format('U')));
                $nthStr         = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? sprintf('Hebdomadæ %s Temporis Ordinarii', $ordinal)
                    : sprintf(_('of the %s Week of Ordinary Time'), $ordinal);
                $name           = $dayOfTheWeek . ' ' . $nthStr;
                $litEvent       = new LiturgicalEvent($name, $lastOrdinary, LitColor::GREEN, LitFeastType::MOBILE);

                $litEvent->psalter_week = $this->Cal::psalterWeek($currentOrdWeek);
                $this->Cal->addLiturgicalEvent('LastOrdWeekday' . $ordWeekday, $litEvent);
            }
            $ordWeekday++;
        }
    }

    //On Saturdays in Ordinary Time when there is no obligatory memorial, an optional memorial of the Blessed Virgin Mary is allowed.
    //So we have to cycle through all Saturdays of the year checking if there isn't an obligatory memorial
    //First we'll find the first Saturday of the year ( to do this we actually have to find the last Saturday of the previous year,
    // so that our cycle using "next Saturday" logic will actually start from the first Saturday of the year ),
    // and then continue for every next Saturday until we reach the last Saturday of the year
    private function calculateSaturdayMemorialBVM(): void
    {
        $currentSaturday = new DateTime("previous Saturday January {$this->CalendarParams->Year}", new \DateTimeZone('UTC'));
        $lastSatDT       = new DateTime("last Saturday December {$this->CalendarParams->Year}", new \DateTimeZone('UTC'));
        $SatMemBVM_cnt   = 0;
        while ($currentSaturday <= $lastSatDT) {
            $currentSaturday = DateTime::createFromFormat('!j-n-Y', $currentSaturday->format('j-n-Y'), new \DateTimeZone('UTC'))->modify('next Saturday');
            if ($this->Cal->inOrdinaryTime($currentSaturday) && $this->Cal->notInSolemnitiesFeastsOrMemorials($currentSaturday)) {
                $memID    = 'SatMemBVM' . ++$SatMemBVM_cnt;
                $name     = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? 'Memoria Sanctæ Mariæ in Sabbato'
                    : _('Saturday Memorial of the Blessed Virgin Mary');
                $litEvent = new LiturgicalEvent($name, $currentSaturday, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::MEMORIAL_OPT, LitCommon::BEATAE_MARIAE_VIRGINIS);
                $this->Cal->addLiturgicalEvent($memID, $litEvent);
            }
        }
    }

    /**
     * Loads wider region data into the calendar.
     * This method is responsible for adding liturgical events to the calendar that are applicable in a broader geographic area such as a Continent.
     *
     * @return void
     */
    private function loadWiderRegionData(): void
    {
        $widerRegionDataFile = strtr(
            JsonData::WIDER_REGIONS_FILE,
            ['{wider_region}' => $this->NationalData->metadata->wider_region]
        );
        if (file_exists($widerRegionDataFile)) {
            $this->WiderRegionData = json_decode(file_get_contents($widerRegionDataFile));
            if (json_last_error() !== JSON_ERROR_NONE || false === property_exists($this->WiderRegionData, 'litcal')) {
                $this->Messages[] = sprintf(
                    _('Error retrieving and decoding Wider Region data from file %s.'),
                    $widerRegionDataFile
                ) . ': ' . json_last_error_msg();
            }
        }
    }

    /**
     * Loads the JSON data for the specified National calendar.
     *
     * @return void
     */
    private function loadNationalCalendarData(): void
    {
        $NationalDataFile = strtr(
            JsonData::NATIONAL_CALENDARS_FILE,
            [ '{nation}' => $this->CalendarParams->NationalCalendar ]
        );
        if (file_exists($NationalDataFile)) {
            $this->NationalData = json_decode(file_get_contents($NationalDataFile));
            if (json_last_error() === JSON_ERROR_NONE) {
                if (
                    property_exists($this->NationalData->metadata, 'locales')
                    && LitLocale::areValid($this->NationalData->metadata->locales)
                ) {
                    if (count($this->NationalData->metadata->locales) === 1) {
                        $this->CalendarParams->Locale = $this->NationalData->metadata->locales[0];
                    } else {
                        // If multiple locales are available for the national calendar,
                        // the desired locale should be set in the Accept-Language header.
                        // We should however check that this is an available locale for the current National Calendar,
                        // and if not use the first valid value.
                        if (false === in_array($this->CalendarParams->Locale, $this->NationalData->metadata->locales)) {
                            $this->CalendarParams->Locale = $this->NationalData->metadata->locales[0];
                        }
                    }
                }

                if (
                    property_exists($this->NationalData, 'metadata')
                    && property_exists($this->NationalData->metadata, 'wider_region')
                ) {
                    $this->loadWiderRegionData();
                } else {
                    $this->Messages[] = sprintf(
                        _('Could not find a %1$s property in the %2$s for the National Calendar %3$s.'),
                        '`wider_region`',
                        '`metadata`',
                        $this->CalendarParams->NationalCalendar
                    );
                }
            } else {
                $this->Messages[] = sprintf(
                    _('Error retrieving and decoding National Calendar data from file %s.'),
                    $NationalDataFile
                ) . ': ' . json_last_error_msg();
            }
        }
    }

    /**
     * Handles a liturgical event for a National calendar that is missing from the calendar.
     * If the liturgical event is suppressed by a Sunday or a Solemnity,
     * a message is added to the Messages array, indicating what has happened.
     *
     * If the liturgical event coincides with another liturgical event, it is added to the calendar,
     * but the message is still added to the Messages array.
     *
     * @param object $row the row of data from the JSON file containing the information about the liturgical event
     * @return void
     */
    private function handleMissingLiturgicalEvent(object $row): void
    {
        if ($this->Cal->isSuppressed($row->liturgical_event->event_key)) {
            $suppressedEvent = $this->Cal->getSuppressedEventByKey($row->liturgical_event->event_key);
            // Let's check if it was suppressed by a Solemnity, Feast, Memorial or Sunday,
            // so we can give some feedback and maybe even recreate the liturgical event if applicable
            if ($this->Cal->inSolemnitiesFeastsOrMemorials($suppressedEvent->date) || self::dateIsSunday($suppressedEvent->date)) {
                $coincidingLiturgicalEvent = $this->Cal->determineSundaySolemnityOrFeast($suppressedEvent->date);
                // If it was suppressed by a Feast or Memorial, we should be able to create it
                // so we'll get the required properties back from the suppressed event
                if ($this->Cal->inFeastsOrMemorials($suppressedEvent->date)) {
                    $this->Cal->addLiturgicalEvent(
                        $row->liturgical_event->event_key,
                        new LiturgicalEvent(
                            $row->liturgical_event->name,
                            $suppressedEvent->date,
                            $suppressedEvent->color,
                            LitFeastType::FIXED,
                            $row->liturgical_event->grade,
                            LitCommon::PROPRIO
                        )
                    );
                    $this->Messages[] =  '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                        /**translators:
                         * 1. Grade of the liturgical event
                         * 2. Name of the liturgical event
                         * 3. Date on which the liturgical event is usually celebrated
                         * 4. Grade of the superseding liturgical event
                         * 5. Name of the superseding liturgical event
                         * 6. Requested calendar year
                         * 7. National or wider region calendar
                         */
                        _('The %1$s \'%2$s\', usually celebrated on %3$s, was suppressed by the %4$s \'%5$s\' in the year %6$d, however being elevated to a Patronal festivity for the Calendar %7$s, it has been reinstated.'),
                        $this->LitGrade->i18n($row->liturgical_event->grade, false),
                        $row->liturgical_event->name,
                        $this->dayAndMonth->format($suppressedEvent->date->format('U')),
                        $coincidingLiturgicalEvent->grade,
                        $coincidingLiturgicalEvent->event->name,
                        $this->CalendarParams->Year,
                        $this->CalendarParams->NationalCalendar
                    );
                } else {
                    $this->Messages[] =  '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                        /**translators:
                         * 1. Grade of the liturgical event
                         * 2. Name of the liturgical event
                         * 3. Date on which the liturgical event is usually celebrated
                         * 4. Grade of the superseding liturgical event
                         * 5. Name of the superseding liturgical event
                         * 6. Requested calendar year
                         * 7. National or wider region calendar
                         */
                        _('The %1$s \'%2$s\', usually celebrated on %3$s, was suppressed by the %4$s \'%5$s\' in the year %6$d, and though it would be elevated to a Patronal festivity for the Calendar %7$s, it has not been reinstated.'),
                        $this->LitGrade->i18n($row->liturgical_event->grade, false),
                        $row->liturgical_event->name,
                        $this->dayAndMonth->format($suppressedEvent->date->format('U')),
                        $coincidingLiturgicalEvent->grade,
                        $coincidingLiturgicalEvent->event->name,
                        $this->CalendarParams->Year,
                        $this->CalendarParams->NationalCalendar
                    );
                }
            }
        }
    }

    /**
     * Checks if a LiturgicalEvent can be created on a given date
     * This means that the LiturgicalEvent is not superseded by a Solemnity or a Feast of higher rank
     * @param object $row the row of data from the JSON file containing the information about the liturgical event
     * @return bool true if the liturgical event can be created, false if it is superseded by a Solemnity or a Feast
     */
    private function liturgicalEventCanBeCreated(object $row): bool
    {
        switch ($row->liturgical_event->grade) {
            case LitGrade::MEMORIAL_OPT:
                return $this->Cal->notInSolemnitiesFeastsOrMemorials($row->liturgical_event->date);
            case LitGrade::MEMORIAL:
                return $this->Cal->notInSolemnitiesOrFeasts($row->liturgical_event->date);
                //however we still have to handle possible coincidences with another memorial
            case LitGrade::FEAST:
                return $this->Cal->notInSolemnities($row->liturgical_event->date);
                //however we still have to handle possible coincidences with another feast
            case LitGrade::SOLEMNITY:
                return true;
                //however we still have to handle possible coincidences with another solemnity
        }
        return false;
    }

    /**
     * Checks if a LiturgicalEvent does not coincide with another LiturgicalEvent of equal or higher rank
     * @param object $row the row of data from the JSON file containing the information about the liturgical event
     * @return bool true if the liturgical event does not coincide with another liturgical event of equal or higher rank, false if it does
     */
    private function liturgicalEventDoesNotCoincide(object $row): bool
    {
        switch ($row->liturgical_event->grade) {
            case LitGrade::MEMORIAL_OPT:
                return true;
                //optional memorials never have problems as regards coincidence with another optional memorial
            case LitGrade::MEMORIAL:
                return $this->Cal->notInMemorials($row->liturgical_event->date);
            case LitGrade::FEAST:
                return $this->Cal->notInFeasts($row->liturgical_event->date);
            case LitGrade::SOLEMNITY:
                return $this->Cal->notInSolemnities($row->liturgical_event->date);
        }
        return true;
    }

    /**
     * Handles a liturgical event that coincides with another liturgical event of equal or higher rank in the calendar.
     * If the coinciding liturgical event is a Memorial, both are reduced in rank to optional memorials.
     * If the coinciding liturgical event is a Feast or a Solemnity, a message is added to the Messages array.
     * @param object $row the row of data from the JSON file containing the information about the liturgical event
     * @return void
     */
    private function handleLiturgicalEventCreationWithCoincidence(object $row): void
    {
        switch ($row->liturgical_event->grade) {
            case LitGrade::MEMORIAL:
                //both memorials become optional memorials
                $coincidingLiturgicalEvents = $this->Cal->getCalEventsFromDate($row->liturgical_event->date);
                $coincidingMemorials        = array_filter($coincidingLiturgicalEvents, function ($el) {
                    return $el->grade === LitGrade::MEMORIAL;
                });
                $coincidingMemorialName     = '';
                foreach ($coincidingMemorials as $key => $value) {
                    $this->Cal->setProperty($key, 'grade', LitGrade::MEMORIAL_OPT);
                    $coincidingMemorialName = $value->name;
                }
                $litEvent = new LiturgicalEvent(
                    $row->liturgical_event->name,
                    $row->liturgical_event->date,
                    $row->liturgical_event->color,
                    LitFeastType::FIXED,
                    LitGrade::MEMORIAL_OPT,
                    $row->liturgical_event->common
                );
                $this->Cal->addLiturgicalEvent($row->liturgical_event->event_key, $litEvent);
                $this->Messages[] = sprintf(
                    /**translators:
                     * 1. Name of the first coinciding Memorial
                     * 2. Name of the second coinciding Memorial
                     * 3. Requested calendar year
                     * 4. Source of the information
                     */
                    _('The Memorial \'%1$s\' coincides with another Memorial \'%2$s\' in the year %3$d. They are both reduced in rank to optional memorials.'),
                    $row->liturgical_event->name,
                    $coincidingMemorialName,
                    $this->CalendarParams->Year
                );
                break;
            case LitGrade::FEAST:
                //there seems to be a coincidence with a different Feast on the same day!
                //what should we do about this? perhaps move one of them?
                $coincidingLiturgicalEvents = $this->Cal->getCalEventsFromDate($row->liturgical_event->date);
                $coincidingFeasts           = array_filter($coincidingLiturgicalEvents, function ($el) {
                    return $el->grade === LitGrade::FEAST;
                });
                $coincidingFeastName        = '';
                foreach ($coincidingFeasts as $key => $value) {
                    //$this->Cal->setProperty( $key, "grade", LitGrade::MEMORIAL_OPT );
                    $coincidingFeastName = $value->name;
                }
                $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> '
                    . $this->CalendarParams->NationalCalendar . ': '
                    . sprintf(
                        /**translators: 1. LiturgicalEvent name, 2. LiturgicalEvent date, 3. Coinciding liturgical event name, 4. Requested calendar year */
                        _('The Feast \'%1$s\', usually celebrated on %2$s, coincides with another Feast \'%3$s\' in the year %4$d! Does something need to be done about this?'),
                        '<b>' . $row->liturgical_event->name . '</b>',
                        '<b>' . $this->dayAndMonth->format($row->liturgical_event->date->format('U')) . '</b>',
                        '<b>' . $coincidingFeastName . '</b>',
                        $this->CalendarParams->Year
                    );
                break;
            case LitGrade::SOLEMNITY:
                //there seems to be a coincidence with a different Solemnity on the same day!
                //should we attempt to move to the next open slot?
                $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> '
                    . $this->CalendarParams->NationalCalendar . ': '
                    . sprintf(
                        /**translators: 1. LiturgicalEvent name, 2. LiturgicalEvent date, 3. Coinciding liturgical event name, 4. Requested calendar year */
                        _('The Solemnity \'%1$s\', usually celebrated on %2$s, coincides with the Sunday or Solemnity \'%3$s\' in the year %4$d! Does something need to be done about this?'),
                        '<i>' . $row->liturgical_event->name . '</i>',
                        '<b>' . $this->dayAndMonth->format($row->liturgical_event->date->format('U')) . '</b>',
                        '<i>' . $this->Cal->solemnityFromDate($row->liturgical_event->date)->name . '</i>',
                        $this->CalendarParams->Year
                    );
                break;
        }
    }

    /**
     * Creates a new regional or national liturgical event and adds it to the calendar.
     *
     * @param object $row The row from the database containing the information about the liturgical event
     *
     * @return void
     */
    private function createNewRegionalOrNationalLiturgicalEvent(object $row): void
    {
        if (
            property_exists($row->liturgical_event, 'strtotime')
            && $row->liturgical_event->strtotime !== ''
        ) {
            $litEventDateTS              = strtotime($row->liturgical_event->strtotime . ' ' . $this->CalendarParams->Year . ' UTC');
            $row->liturgical_event->date = new DateTime("@$litEventDateTS");
            $row->liturgical_event->date->setTimeZone(new \DateTimeZone('UTC'));
        } elseif (
            property_exists($row->liturgical_event, 'month')
            && $row->liturgical_event->month >= 1
            && $row->liturgical_event->month <= 12
            && property_exists($row->liturgical_event, 'day')
            && $row->liturgical_event->day >= 1
            && $row->liturgical_event->day <= cal_days_in_month(CAL_GREGORIAN, $row->liturgical_event->month, $this->CalendarParams->Year)
        ) {
            $row->liturgical_event->date = DateTime::createFromFormat(
                '!j-n-Y',
                "{$row->liturgical_event->day}-{$row->liturgical_event->month}-{$this->CalendarParams->Year}",
                new \DateTimeZone('UTC')
            );
        } else {
            ob_start();
            var_dump($row);
            $a = ob_get_contents();
            ob_end_clean();
            $this->Messages[] = _('We should be creating a new liturgical event, however we do not seem to have the correct date information in order to proceed') . ' :: ' . $a;
            return;
        }
        if ($this->liturgicalEventCanBeCreated($row)) {
            if ($this->liturgicalEventDoesNotCoincide($row)) {
                if (!property_exists($row->liturgical_event, 'type') || !LitFeastType::isValid($row->liturgical_event->type)) {
                    $row->liturgical_event->type = property_exists($row->liturgical_event, 'strtotime') ? LitFeastType::MOBILE : LitFeastType::FIXED;
                }
                $litEvent = new LiturgicalEvent(
                    $row->liturgical_event->name,
                    $row->liturgical_event->date,
                    $row->liturgical_event->color,
                    $row->liturgical_event->type,
                    $row->liturgical_event->grade,
                    $row->liturgical_event->common
                );
                $this->Cal->addLiturgicalEvent($row->liturgical_event->event_key, $litEvent);
            } else {
                $this->handleLiturgicalEventCreationWithCoincidence($row);
            }
            $infoSource = 'unknown';
            if (property_exists($row->metadata, 'url')) {
                $infoSource = $this->elaborateDecreeSource($row);
            } elseif (property_exists($row->metadata, 'missal')) {
                $infoSource = RomanMissal::getName($row->metadata->missal);
            }

            $locale           = LitLocale::$PRIMARY_LANGUAGE;
            $formattedDateStr = $this->CalendarParams->Locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                ? ( $row->liturgical_event->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[ (int)$row->liturgical_event->date->format('n') ] )
                : ( $locale === 'en'
                    ? $row->liturgical_event->date->format('F jS')
                    : $this->dayAndMonth->format($row->liturgical_event->date->format('U'))
                );
            $dateStr          = property_exists($row->liturgical_event, 'strtotime') && $row->liturgical_event->strtotime !== ''
                ? '<i>' . $row->liturgical_event->strtotime . '</i>'
                : $formattedDateStr;
            $this->Messages[] = sprintf(
                /**translators:
                 * 1. Grade or rank of the liturgical event
                 * 2. Name of the liturgical event
                 * 3. Day of the liturgical event
                 * 4. Year from which the liturgical event has been added
                 * 5. Source of the information
                 * 6. Requested calendar year
                 */
                _('The %1$s \'%2$s\' has been added on %3$s since the year %4$d (%5$s), applicable to the year %6$d.'),
                $this->LitGrade->i18n($row->liturgical_event->grade, false),
                $row->liturgical_event->name,
                $dateStr,
                $row->metadata->since_year,
                $infoSource,
                $this->CalendarParams->Year
            );
        }// else {
            //$this->handleCoincidenceDecree( $row );
        //}
    }

    /**
     * Handles an array of rows from the national calendar JSON file that
     * describe changes to the calendar that should be applied.
     *
     * These changes may be:
     * - create a new regional or national liturgical event
     * - change the grade of a liturgical event
     * - change the name of a liturgical event
     * - move a liturgical event to a different date
     *
     * Each row in the array is processed in the order in which it appears in
     * the JSON file. If the row describes a change that is outside the
     * applicable year, the row is skipped.
     *
     * @param array<NationalCalendarLiturgicalEventItem> $rows The array of rows from the national calendar JSON file
     */
    private function handleNationalCalendarRows(array $rows): void
    {
        foreach ($rows as $row) {
            if ($this->CalendarParams->Year >= $row->metadata->since_year) {
                // Until year is exclusive with this logic
                if (property_exists($row->metadata, 'until_year') && $this->CalendarParams->Year >= $row->metadata->until_year) {
                    continue;
                }
                $action = CalEventAction::from($row->metadata->action);
                switch ($action) {
                    case CalEventAction::MakePatron:
                        $litEvent = $this->Cal->getLiturgicalEvent($row->liturgical_event->event_key);
                        if ($litEvent !== null) {
                            if ($litEvent->grade !== $row->liturgical_event->grade) {
                                $this->Cal->setProperty($row->liturgical_event->event_key, 'grade', $row->liturgical_event->grade);
                            }
                            $this->Cal->setProperty($row->liturgical_event->event_key, 'name', $row->liturgical_event->name);
                        } else {
                            $this->handleMissingLiturgicalEvent($row);
                        }
                        break;
                    case CalEventAction::CreateNew:
                        $this->createNewRegionalOrNationalLiturgicalEvent($row);
                        break;
                    case CalEventAction::SetProperty:
                        switch ($row->metadata->property) {
                            case 'name':
                                $litEvent = $this->Cal->getLiturgicalEvent($row->liturgical_event->event_key);
                                if (null !== $litEvent) {
                                    $this->Messages[] = sprintf(
                                        /**translators:
                                         * 1. Liturgical grade
                                         * 2. Original name of the liturgical event
                                         * 3. New name of the liturgical event
                                         * 4. ID of the national calendar
                                         * 5. Year from which the name has been changed
                                         * 6. Requested calendar year
                                         */
                                        _('The name of the %1$s \'%2$s\' has been changed to \'%3$s\' in the national calendar \'%4$s\' since the year %5$d, applicable to the year %6$d.'),
                                        $this->LitGrade->i18n($litEvent->grade, false),
                                        $litEvent->name,
                                        $row->liturgical_event->name,
                                        $this->CalendarParams->NationalCalendar,
                                        $row->metadata->since_year,
                                        $this->CalendarParams->Year
                                    );
                                    $this->Cal->setProperty($row->liturgical_event->event_key, 'name', $row->liturgical_event->name);
                                } else {
                                    $this->Messages[] = sprintf(
                                        /**translators:
                                         * 1. Event key of the liturgical event
                                         * 2. New name of the liturgical event
                                         * 3. ID of the national calendar
                                         * 4. Year from which the name has been changed
                                         * 5. Requested calendar year
                                         */
                                        _('The name of the celebration \'%1$s\' has been changed to \'%2$s\' in the national calendar \'%3$s\' since the year %4$d, but could not be applied to the year %5$d because the celebration was not found.'),
                                        $row->liturgical_event->event_key,
                                        $row->liturgical_event->name,
                                        $this->CalendarParams->NationalCalendar,
                                        $row->metadata->since_year,
                                        $this->CalendarParams->Year
                                    );
                                }
                                break;
                            case 'grade':
                                $litEvent = $this->Cal->getLiturgicalEvent($row->liturgical_event->event_key);
                                if (null !== $litEvent) {
                                    $this->Messages[] = sprintf(
                                        /**translators:
                                         * 1. Original liturgical grade
                                         * 2. Name of the liturgical event
                                         * 3. New liturgical grade
                                         * 4. ID of the national calendar
                                         * 5. Year from which the grade has been changed
                                         * 6. Requested calendar year
                                         */
                                        _('The grade of the %1$s \'%2$s\' has been changed to \'%3$s\' in the national calendar \'%4$s\' since the year %5$d, applicable to the year %6$d.'),
                                        $this->LitGrade->i18n($litEvent->grade, false),
                                        $litEvent->name,
                                        $this->LitGrade->i18n($row->liturgical_event->grade, false),
                                        $this->CalendarParams->NationalCalendar,
                                        $row->metadata->since_year,
                                        $this->CalendarParams->Year
                                    );
                                    $this->Cal->setProperty($row->liturgical_event->event_key, 'grade', $row->liturgical_event->grade);
                                } else {
                                    $this->Messages[] = sprintf(
                                        /**translators:
                                         * 1. Event key of the liturgical event
                                         * 2. New name of the liturgical event
                                         * 3. ID of the national calendar
                                         * 4. Year from which the name has been changed
                                         * 5. Requested calendar year
                                         */
                                        _('The grade of the celebration \'%1$s\' has been changed to \'%2$s\' in the national calendar \'%3$s\' since the year %4$d, but could not be applied to the year %5$d because the celebration was not found.'),
                                        $row->liturgical_event->event_key,
                                        $this->LitGrade->i18n($row->liturgical_event->grade, false),
                                        $this->CalendarParams->NationalCalendar,
                                        $row->metadata->since_year,
                                        $this->CalendarParams->Year
                                    );
                                }
                                break;
                        }
                        break;
                    case CalEventAction::MoveEvent:
                        $litEvent        = $this->Cal->getLiturgicalEvent($row->liturgical_event->event_key);
                        $litEventNewDate = DateTime::createFromFormat(
                            '!j-n-Y',
                            $row->liturgical_event->day . '-' . $row->liturgical_event->month . '-' . $this->CalendarParams->Year,
                            new \DateTimeZone('UTC')
                        );
                        if (self::dateIsNotSunday($litEventNewDate) && $this->Cal->notInSolemnitiesFeastsOrMemorials($litEventNewDate)) {
                            if (null === $litEvent) {
                                if ($this->Cal->isSuppressed($row->liturgical_event->event_key)) {
                                    $litEvent         = $this->Cal->getSuppressedEventByKey($row->liturgical_event->event_key);
                                    $this->Messages[] = sprintf(
                                        /**translators:
                                         * 1. Liturgical grade
                                         * 2. Name of the liturgical event
                                         * 3. Original date of the liturgical event
                                         * 4. New date of the liturgical event
                                         * 5. Year from which the date has been changed
                                         * 6. ID of the national calendar
                                         * 7. Requested calendar year
                                         */
                                        _('The %1$s \'%2$s\' has been moved from %3$s to %4$s since the year %5$d in the national calendar \'%6$s\', applicable to the year %7$d.'),
                                        $this->LitGrade->i18n($litEvent->grade, false),
                                        $litEvent->name,
                                        $this->dayAndMonth->format($litEvent->date->format('U')),
                                        $this->dayAndMonth->format($litEventNewDate->format('U')),
                                        $row->metadata->since_year,
                                        $this->CalendarParams->NationalCalendar,
                                        $this->CalendarParams->Year
                                    );
                                    $this->moveLiturgicalEventDate($row->liturgical_event->event_key, $litEventNewDate, $row->metadata->reason, $row->metadata->missal);
                                } else {
                                    $this->Messages[] = sprintf(
                                        /**translators:
                                         * 1. Name of the liturgical event
                                         * 2. New date of the liturgical event
                                         * 3. Year from which the date has been changed
                                         * 4. ID of the national calendar
                                         * 5. Requested calendar year
                                         */
                                        _('The liturgical event \'%1$s\' has been moved to %2$s since the year %3$d in the national calendar \'%4$s\', but cannot be applied in the year %5$d simply because we could not find the data for it from the Roman Missal events.'),
                                        $row->name,
                                        $this->dayAndMonth->format($litEventNewDate->format('U')),
                                        $row->metadata->since_year,
                                        $this->CalendarParams->NationalCalendar,
                                        $this->CalendarParams->Year
                                    );
                                }
                            }
                        } else {
                            if (null !== $litEvent) {
                                $this->Messages[] = sprintf(
                                    /**translators:
                                     * 1. ID of the liturgical event
                                     * 2. New date of the liturgical event
                                     * 3. Year from which the date has been changed
                                     * 4. ID of the national calendar
                                     * 5. Requested calendar year
                                     */
                                    _('The liturgical event \'%1$s\' has been moved to %2$s since the year %3$d in the national calendar \'%4$s\', but this could not take place in the year %5$d since the new date %2$s seems to be a Sunday or a liturgical event of greater rank.'),
                                    $row->liturgical_event->event_key,
                                    $this->dayAndMonth->format($litEventNewDate->format('U')),
                                    $row->metadata->since_year,
                                    $this->CalendarParams->NationalCalendar,
                                    $this->CalendarParams->Year
                                );
                                $this->Cal->removeLiturgicalEvent($row->liturgical_event->event_key);
                            }
                        }
                        break;
                }
            }
        }
    }

    /**
     * Applies any national calendar changes from the national calendar JSON file including any data from a wider region
     *
     * @return void
     */
    private function applyNationalCalendar(): void
    {
        // Apply any new celebrations that the National Calendar introduces via it's Missals
        $requiredProps = ['calendar', 'day', 'month','name','color','grade','common','grade_display', 'event_key', 'readings'];
        if ($this->NationalData !== null && property_exists($this->NationalData, 'metadata') && property_exists($this->NationalData->metadata, 'missals')) {
            $this->Messages[] = "Found Missals for region {$this->NationalData->metadata->nation}: " . implode(', ', $this->NationalData->metadata->missals);
            foreach ($this->NationalData->metadata->missals as $missal) {
                $yearLimits = RomanMissal::getYearLimits($missal);
                if ($this->CalendarParams->Year >= $yearLimits->since_year) {
                    if (property_exists($yearLimits, 'until_year') && $this->CalendarParams->Year >= $yearLimits->until_year) {
                        continue;
                    } else {
                        if (RomanMissal::getSanctoraleFileName($missal) !== false) {
                            $this->Messages[] = sprintf(
                                /**translators: Name of the Roman Missal */
                                _('Found a sanctorale data file for %s'),
                                RomanMissal::getName($missal)
                            );
                            $this->loadPropriumDeSanctisData($missal);
                            foreach ($this->tempCal[ $missal ] as $row) {
                                $rowProps      = get_object_vars($row);
                                $arrayDiffKeys = array_keys(array_diff_key(array_flip($requiredProps), $rowProps));
                                if (count($arrayDiffKeys) > 0) {
                                    self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, sprintf(
                                        'The sanctorale data file for %1$s does not contain the required fields to create a liturgical event: missing keys %2$s.',
                                        RomanMissal::getName($missal),
                                        implode(', ', $arrayDiffKeys)
                                    ));
                                }
                                $currentFeastDate = DateTime::createFromFormat(
                                    '!j-n-Y',
                                    $row->day . '-' . $row->month . '-' . $this->CalendarParams->Year,
                                    new \DateTimeZone('UTC')
                                );
                                if (!$this->Cal->inSolemnitiesOrFeasts($currentFeastDate)) {
                                    $litEvent = new LiturgicalEvent(
                                        "[ {$this->NationalData->metadata->nation} ] " . $row->name,
                                        $currentFeastDate,
                                        $row->color,
                                        LitFeastType::FIXED,
                                        $row->grade,
                                        $row->common,
                                        $row->grade_display
                                    );
                                    $this->Cal->addLiturgicalEvent($row->event_key, $litEvent);
                                } else {
                                    if (self::dateIsSunday($currentFeastDate) && $row->event_key === 'PrayerUnborn') {
                                        $litEvent = new LiturgicalEvent(
                                            '[ USA ] ' . $row->name,
                                            $currentFeastDate->add(new \DateInterval('P1D')),
                                            $row->color,
                                            LitFeastType::FIXED,
                                            $row->grade,
                                            $row->common,
                                            $row->grade_display
                                        );
                                        $this->Cal->addLiturgicalEvent($row->event_key, $litEvent);
                                        $this->Messages[] = sprintf(
                                            'USA: The National Day of Prayer for the Unborn is set to Jan 22 as per the 2011 Roman Missal issued by the USCCB, however since it coincides with a Sunday or a Solemnity in the year %d, it has been moved to Jan 23',
                                            $this->CalendarParams->Year
                                        );
                                    } else {
                                        $coincidingLiturgicalEvent = $this->Cal->determineSundaySolemnityOrFeast($currentFeastDate);
                                        $this->Messages[]          = sprintf(
                                            /**translators:
                                             * 1. LiturgicalEvent grade
                                             * 2. LiturgicalEvent name
                                             * 3. LiturgicalEvent date
                                             * 4. Edition of the Roman Missal
                                             * 5. Superseding liturgical event grade
                                             * 6. Superseding liturgical event name
                                             * 7. Requested calendar year
                                             */
                                            $this->NationalData->metadata->nation . ': ' . _('The %1$s \'%2$s\' (%3$s), added to the national calendar in the %4$s, is superseded by the %5$s \'%6$s\' in the year %7$d'),
                                            $row->grade_display !== null && $row->grade_display !== '' ? $row->grade_display : $this->LitGrade->i18n($row->grade, false),
                                            '<i>' . $row->name . '</i>',
                                            $this->dayAndMonth->format($currentFeastDate->format('U')),
                                            RomanMissal::getName($missal),
                                            $coincidingLiturgicalEvent->grade,
                                            $coincidingLiturgicalEvent->event->name,
                                            $this->CalendarParams->Year
                                        );
                                    }
                                }
                            }
                        } else {
                            $this->Messages[] = sprintf(
                                /**translators: Name of the Roman Missal */
                                _('Could not find a sanctorale data file for %s'),
                                RomanMissal::getName($missal)
                            );
                        }
                    }
                }
            }
        } else {
            if (
                $this->NationalData !== null
                && property_exists($this->NationalData, 'metadata')
                && property_exists($this->NationalData->metadata, 'nation')
            ) {
                $this->Messages[] = 'Did not find any Missals for region ' . $this->NationalData->metadata->nation;
            }
        }

        // Apply any actions that modify celebrations from the General Roman Calendar for the Wider Region (such as Europe, or Americas)
        if ($this->WiderRegionData !== null && property_exists($this->WiderRegionData, 'litcal')) {
            $this->handleNationalCalendarRows($this->WiderRegionData->litcal);
        }

        // Apply any actions that modify celebrations from the General Roman Calendar for the National Calendar
        if ($this->NationalData !== null && property_exists($this->NationalData, 'litcal')) {
            $this->handleNationalCalendarRows($this->NationalData->litcal);
        }
    }

    /**
     * Moves a liturgical event to a new date in the calendar. If the liturgical event doesn't exist at the original date
     * (because it was suppressed by a higher-ranking celebration), it will be recreated on the new date.
     * However, if the new date is already covered by a Solemnity, Feast or Memorial, the liturgical event will be
     * suppressed instead.
     *
     * @param string $event_key The liturgical event key to move
     * @param DateTime $newDate The new date to move the liturgical event to
     * @param string $inFavorOf The name of the liturgical event that is taking over the original date
     * @param string $missal The Roman Missal edition to use
     */
    private function moveLiturgicalEventDate(string $event_key, DateTime $newDate, string $inFavorOf, $missal): void
    {
        $litEvent   = $this->Cal->getLiturgicalEvent($event_key);
        $locale     = LitLocale::$PRIMARY_LANGUAGE;
        $newDateStr = $this->CalendarParams->Locale === LitLocale::LATIN_PRIMARY_LANGUAGE
            ? ( $newDate->format('j') . ' ' . LatinUtils::LATIN_MONTHS[ (int)$newDate->format('n') ] )
            : ( $locale === 'en'
                ? $newDate->format('F jS')
                : $this->dayAndMonth->format($newDate->format('U'))
            );

        if (!$this->Cal->inSolemnitiesFeastsOrMemorials($newDate)) {
            $oldDateStr = '';
            // If the liturgical event exists, we can simply move it to the new date
            // If it does not exist, we should recreate it on the new date
            if ($litEvent !== null) {
                $oldDateStr = $this->CalendarParams->Locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? ( $litEvent->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[ (int)$litEvent->date->format('n') ] )
                    : ( $locale === 'en'
                        ? $litEvent->date->format('F jS')
                        : $this->dayAndMonth->format($litEvent->date->format('U'))
                    );
                $this->Cal->moveLiturgicalEventDate($event_key, $litEvent->date);
            } else {
                // If it was suppressed on the original date because of a higher ranking celebration,
                //    we should recreate it on the new date
                //    except in the case of Saint Vincent Deacon when we're dealing with the Roman Missal USA edition,
                //    since the National Day of Prayer will take over the new date
                // TODO: this logic needs to be generalized to allow for other cases,
                //       and needs to be automated from the national calendar JSON file
                if ($event_key !== 'StVincentDeacon' || $missal !== RomanMissal::USA_EDITION_2011) {
                    if ($this->Cal->isSuppressed($event_key)) {
                        $suppressedEvent = $this->Cal->getSuppressedEventByKey($event_key);
                        $litEvent        = new LiturgicalEvent(
                            $suppressedEvent->name,
                            $newDate,
                            $suppressedEvent->color,
                            LitFeastType::FIXED,
                            $suppressedEvent->grade,
                            $suppressedEvent->common
                        );
                        $this->Cal->addLiturgicalEvent($event_key, $litEvent);
                        // if it was suppressed previously (which it should have been), we should remove from the suppressed events collection
                        $this->Cal->reinstateEvent($event_key);
                        $oldDate    = $suppressedEvent->date;
                        $oldDateStr = $this->CalendarParams->Locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                            ? ( $oldDate->format('j') . ' ' . LatinUtils::LATIN_MONTHS[ (int)$oldDate->format('n') ] )
                            : ( $locale === 'en'
                                ? $oldDate->format('F jS')
                                : $this->dayAndMonth->format($oldDate->format('U'))
                            );
                    } else {
                        die("this is strange, {$event_key} is not suppressed? Where is it?");
                    }
                }
            }

            //If the liturgical event has been successfully recreated, let's make a note about that
            if ($litEvent !== null) {
                $this->Messages[] = sprintf(
                    /**translators: 1. LiturgicalEvent grade, 2. LiturgicalEvent name, 3. New liturgical event name, 4: Requested calendar year, 5. Old date, 6. New date */
                    _('The %1$s \'%2$s\' is transferred from %5$s to %6$s as per the %7$s, to make room for \'%3$s\': applicable to the year %4$d.'),
                    $this->LitGrade->i18n($litEvent->grade),
                    '<i>' . $litEvent->name . '</i>',
                    '<i>' . $inFavorOf . '</i>',
                    $this->CalendarParams->Year,
                    $oldDateStr,
                    $newDateStr,
                    RomanMissal::getName($missal)
                );
                //$this->Cal->setProperty( $event_key, "name", "[ USA ] " . $litEvent->name );
            }
        } else {
            if ($litEvent !== null) {
                $oldDateStr = $this->CalendarParams->Locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? ( $litEvent->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[ (int)$litEvent->date->format('n') ] )
                    : ( $locale === 'en'
                        ? $litEvent->date->format('F jS')
                        : $this->dayAndMonth->format($litEvent->date->format('U'))
                    );

                $coincidingLiturgicalEvent = $this->Cal->determineSundaySolemnityOrFeast($newDate);
                //If the new date is already covered by a Solemnity, Feast or Memorial, then we can't move the celebration, so we simply suppress it
                $this->Messages[] = sprintf(
                    /**translators: 1. LiturgicalEvent grade, 2. LiturgicalEvent name, 3. Old date, 4. New date, 5. Source of the information, 6. New liturgical event name, 7. Superseding liturgical event grade, 8. Superseding liturgical event name, 9: Requested calendar year */
                    _('The %1$s \'%2$s\' would have been transferred from %3$s to %4$s as per the %5$s, to make room for \'%6$s\', however it is suppressed by the %7$s \'%8$s\' in the year %9$d.'),
                    $this->LitGrade->i18n($litEvent->grade),
                    '<i>' . $litEvent->name . '</i>',
                    $oldDateStr,
                    $newDateStr,
                    RomanMissal::getName($missal),
                    '<i>' . $inFavorOf . '</i>',
                    $coincidingLiturgicalEvent->grade,
                    $coincidingLiturgicalEvent->event->name,
                    $this->CalendarParams->Year
                );
                $this->Cal->removeLiturgicalEvent($event_key);
            }
        }
    }


    /**
     * Returns true if the given date is a Sunday.
     *
     * @param DateTime $dt The date to check.
     * @return bool True if the given date is a Sunday, false otherwise.
     */
    private static function dateIsSunday(DateTime $dt): bool
    {
        return (int)$dt->format('N') === 7;
    }

    /**
     * Returns true if the given date is not a Sunday.
     *
     * @param DateTime $dt The date to check.
     * @return bool True if the given date is not a Sunday, false otherwise.
     */
    private static function dateIsNotSunday(DateTime $dt): bool
    {
        return (int)$dt->format('N') !== 7;
    }

    /**
     * Calculates the liturgical events based on the order of precedence of liturgical days
     * as per the General Norms for the Liturgical Year and the Calendar ( issued on Feb. 14 1969 )
     *
     * The following events are calculated:
     * 1. Easter Triduum of the Lord's Passion and Resurrection
     * 2. Christmas, Epiphany, Ascension, and Pentecost
     * 3. Solemnities of the Lord, of the Blessed Virgin Mary, and of saints listed in the General Calendar
     * 4. Sundays of Advent, Lent, and Easter Time
     * 5. Weekdays of Advent from 17 December to 24 December inclusive
     * 6. Weekdays of the Octave of Christmas
     * 7. Weekdays of Lent
     * 8. Obligatory memorials in the General Calendar
     * 9. Weekdays of Ordinary Time
     *
     * @return void
     */
    private function calculateUniversalCalendar(): void
    {
        $this->loadPropriumDeTemporeData();
        /**
         *  CALCULATE LITURGICAL EVENTS BASED ON THE ORDER OF PRECEDENCE OF LITURGICAL DAYS ( LY 59 )
         *  General Norms for the Liturgical Year and the Calendar ( issued on Feb. 14 1969 )
         */

        //I.
        //1. Easter Triduum of the Lord's Passion and Resurrection
        $this->calculateEasterTriduum();
        //2. Christmas, Epiphany, Ascension, and Pentecost
        $this->calculateChristmasEpiphany();
        $this->calculateAscensionPentecost();
        //Sundays of Advent, Lent, and Easter Time
        $this->calculateSundaysMajorSeasons();
        $this->calculateAshWednesday();
        $this->calculateWeekdaysHolyWeek();
        $this->calculateEasterOctave();
        //3. Solemnities of the Lord, of the Blessed Virgin Mary, and of saints listed in the General Calendar
        $this->calculateMobileSolemnitiesOfTheLord();
        $this->loadPropriumDeSanctisData(RomanMissal::EDITIO_TYPICA_1970);
        $this->calculateFixedSolemnities(); //this will also handle All Souls Day

        //4. PROPER SOLEMNITIES:
        //these will be dealt with later when loading Local Calendar Data

        //II.
        //5. FEASTS OF THE LORD IN THE GENERAL CALENDAR
        $this->calculateFeastsOfTheLord();
        //6. SUNDAYS OF CHRISTMAS TIME AND SUNDAYS IN ORDINARY TIME
        $this->calculateSundaysChristmasOrdinaryTime();
        //7. FEASTS OF THE BLESSED VIRGIN MARY AND OF THE SAINTS IN THE GENERAL CALENDAR
        $this->calculateFeastsMarySaints();

        //8. PROPER FEASTS:
        //a ) feast of the principal patron of the Diocese - for pastoral reasons can be celebrated as a solemnity ( PC 8, 9 )
        //b ) feast of the anniversary of the Dedication of the cathedral church
        //c ) feast of the principal Patron of the region or province, of a nation or a wider territory - for pastoral reasons can be celebrated as a solemnity ( PC 8, 9 )
        //d ) feast of the titular, of the founder, of the principal patron of an Order or Congregation and of the religious province, without prejudice to the prescriptions of n. 4 d
        //e ) other feasts proper to an individual church
        //f ) other feasts inscribed in the calendar of a diocese or of a religious order or congregation
        //these will be dealt with later when loading Local Calendar Data, {@see Calendar::applyNationalCalendarData()}

        //9. WEEKDAYS of ADVENT FROM 17 DECEMBER TO 24 DECEMBER INCLUSIVE
        $this->calculateWeekdaysAdvent();
        //WEEKDAYS of the Octave of Christmas
        $this->calculateWeekdaysChristmasOctave();
        //WEEKDAYS of LENT
        $this->calculateWeekdaysLent();
        //III.
        //10. Obligatory memorials in the General Calendar
        $this->calculateMemorials(LitGrade::MEMORIAL, RomanMissal::EDITIO_TYPICA_1970);

        if ($this->CalendarParams->Year >= 2002) {
            $this->loadPropriumDeSanctisData(RomanMissal::EDITIO_TYPICA_TERTIA_2002);
            $this->calculateMemorials(LitGrade::MEMORIAL, RomanMissal::EDITIO_TYPICA_TERTIA_2002);
        }

        if ($this->CalendarParams->Year >= 2008) {
            $this->loadPropriumDeSanctisData(RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008);
            $this->calculateMemorials(LitGrade::MEMORIAL, RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008);
        }

        // :MEMORIALS_FROM_DECREES
        $this->loadMemorialsFromDecreesData();
        $this->applyDecrees(LitGrade::MEMORIAL);

        //11. Proper obligatory memorials, and that is:
        //a ) obligatory memorial of the seconday Patron of a place, of a diocese, of a region or religious province
        //b ) other obligatory memorials in the calendar of a single diocese, order or congregation
        //these will be dealt with later when loading Local Calendar Data

        //12. Optional memorials ( a proper memorial is to be preferred to a general optional memorial ( PC, 23 c ) )
        //  which however can be celebrated even in those days listed at n. 9,
        //  in the special manner described by the General Instructions of the Roman Missal and of the Liturgy of the Hours ( cf pp. 26-27, n. 10 )

        $this->calculateMemorials(LitGrade::MEMORIAL_OPT, RomanMissal::EDITIO_TYPICA_1970);

        if ($this->CalendarParams->Year >= 2002) {
            $this->calculateMemorials(LitGrade::MEMORIAL_OPT, RomanMissal::EDITIO_TYPICA_TERTIA_2002);
        }

        if ($this->CalendarParams->Year >= 2008) {
            $this->calculateMemorials(LitGrade::MEMORIAL_OPT, RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008);
        }

        $this->applyDecrees(LitGrade::MEMORIAL_OPT);

        //Doctors will often have grade of Memorial, but not always
        //so let's go ahead and just apply these decrees after all memorials and optional memorials have been defined
        //so that we're sure they all exist
        $this->applyDecrees('DOCTORS');

        //13. Weekdays of Advent up until Dec. 16 included ( already calculated and defined together with weekdays 17 Dec. - 24 Dec. )
        //    Weekdays of Christmas season from 2 Jan. until the Saturday after Epiphany
        //    Weekdays of the Easter season, from the Monday after the Octave of Easter to the Saturday before Pentecost
        //    Weekdays of Ordinary time
        $this->calculateChristmasWeekdaysThroughEpiphany();
        $this->calculateChristmasWeekdaysAfterEpiphany();
        $this->calculateWeekdaysEaster();
        $this->calculateWeekdaysOrdinaryTime();

        //15. On Saturdays in Ordinary Time when there is no obligatory memorial, an optional memorial of the Blessed Virgin Mary is allowed.
        // We will handle this after having set National and Diocesan calendar data
        // see :SATURDAY_MEMORIAL_BVM
    }

    /**
     * Validates that a strtotime object or string is correctly formatted
     *
     * If the strtotime is a string, it must not be empty.
     * If the strtotime is an object, it must have the following properties:
     *  - day_of_the_week
     *  - relative_time
     *  - event_key
     *
     * @param object|string $strtotime the strtotime object or string to validate
     * @return bool true if the strtotime is valid, false otherwise
     */
    private function validateStrToTime(object|string $strtotime): bool
    {
        if (is_string($strtotime)) {
            return $strtotime !== '';
        } else {
            return (
                property_exists($strtotime, 'day_of_the_week')
                && property_exists($strtotime, 'relative_time')
                && property_exists($strtotime, 'event_key')
            );
        }
    }

    /**
     * Handles a mobile liturgical event whose date is specified using a strtotime object
     *
     * The strtotime object must have the following properties:
     *  - day_of_the_week (e.g. 'monday', 'tuesday', etc.)
     *  - relative_time (either 'before' or 'after')
     *  - event_key (the key of the liturgical event that this mobile liturgical event is relative to)
     *
     * If the strtotime object is invalid, or if the liturgical event that it is relative to does not exist,
     * an error message will be added to the Messages array and false will be returned.
     *
     * @param object $row the row containing data for the mobile liturgical event from the JSON file
     * @return DateTime|false the date of the mobile liturgical event, or false if there was an error
     */
    private function handleObjectStrtotime(object $row): DateTime|false
    {
        if (false === $this->validateStrToTime($row->metadata->strtotime)) {
            $this->Messages[] = sprintf(
                /**translators: Do not translate 'strtotime'! 1. Name of the mobile liturgical event being created 2. list of properties */
                _('Cannot create mobile liturgical event \'%1$s\': when the \'strtotime\' property is an object, it must have properties %2$s'),
                $row->liturgical_event->name,
                implode(', ', ['\'day_of_the_week\'', '\'relative_time\'', '\'event_key\''])
            );
            return false;
        }

        $litEvent = $this->Cal->getLiturgicalEvent($row->metadata->strtotime->event_key);
        if (null === $litEvent) {
            $this->Messages[] = sprintf(
                /**translators: 1. Name of the mobile liturgical event being created, 2. Name of the liturgical event that it is relative to */
                _('Cannot create mobile liturgical event \'%1$s\' relative to liturgical event with key \'%2$s\''),
                $row->liturgical_event->name,
                $row->metadata->strtotime->event_key
            );
            return false;
        }

        $DATE = clone( $litEvent->date );
        switch ($row->metadata->strtotime->relative_time) {
            case 'before':
                return $DATE->modify("previous {$row->metadata->strtotime->day_of_the_week}");
            case 'after':
                return $DATE->modify("next {$row->metadata->strtotime->day_of_the_week}");
            default:
                $this->Messages[] = sprintf(
                    /**translators: 1. Name of the mobile liturgical event being created, 2. Name of the liturgical event that it is relative to */
                    _('Cannot create mobile liturgical event \'%1$s\': can only be relative to liturgical event with key \'%2$s\' using keywords %3$s'),
                    $row->liturgical_event->name,
                    $row->metadata->strtotime->event_key,
                    implode(', ', ['\'before\'', '\'after\''])
                );
                return false;
        }
    }


    /**
     * Handle a mobile liturgical event whose date is specified using the "strtotime" property
     *
     * The "strtotime" property must be a string that can be interpreted by PHP's strtotime function.
     * If the string contains the word 'before' or 'after', it will be interpreted as being relative to
     * another liturgical event. If it does not contain either of these words, it will be interpreted as an
     * absolute date.
     *
     * @param object $row the row containing data for the mobile liturgical event from the JSON file
     * @return DateTime the date of the mobile liturgical event, or false if there was an error
     */
    private function handleStringStrtotime(object $row): DateTime|false
    {
        if (false === $this->validateStrToTime($row->metadata->strtotime)) {
            return false;
        }

        if (preg_match('/(before|after)/', $row->metadata->strtotime)) {
            $match = preg_split('/(before|after)/', $row->metadata->strtotime, -1, PREG_SPLIT_DELIM_CAPTURE);
            if (false === $match || count($match) !== 3) {
                $this->Messages[] = sprintf(
                    /**translators: Do not translate 'strtotime'! */
                    'Could not interpret the \'strtotime\' property with value %1$s into a timestamp. Splitting failed: %2$s',
                    $row->metadata->strtotime,
                    json_encode($match)
                );
                return false;
            }

            $litEventDateTS = strtotime($match[2] . ' ' . $this->CalendarParams->Year . ' UTC');
            if (false === $litEventDateTS) {
                $this->Messages[] = sprintf(
                    /**translators: Do not translate 'strtotime'! */
                    'Could not interpret the \'strtotime\' property with value %1$s into a timestamp',
                    $row->metadata->strtotime
                );
                return false;
            }

            $DATE = new DateTime("@$litEventDateTS");
            $DATE->setTimeZone(new \DateTimeZone('UTC'));
            if ($match[1] === 'before') {
                $DATE->modify("previous {$match[0]}");
            } elseif ($match[1] === 'after') {
                $DATE->modify("next {$match[0]}");
            }
            return $DATE;
        } else {
            $litEventDateTS = strtotime($row->metadata->strtotime . ' ' . $this->CalendarParams->Year . ' UTC');
            if (false === $litEventDateTS) {
                $this->Messages[] = sprintf(
                    /**translators: Do not translate 'strtotime'! */
                    'Could not interpret the \'strtotime\' property with value %1$s into a timestamp',
                    $row->metadata->strtotime
                );
                return false;
            }

            $DATE = new DateTime("@$litEventDateTS");
            $DATE->setTimeZone(new \DateTimeZone('UTC'));
            return $DATE;
        }
    }

    /**
     * Interpret the 'strtotime' property of a mobile liturgical event into a date.
     *
     * The 'strtotime' property can be either an object or a string.
     * If it is an object, it must have the following properties:
     *  - day_of_the_week (e.g. 'monday', 'tuesday', etc.)
     *  - relative_time (either 'before' or 'after')
     *  - event_key (the key of the liturgical event that this mobile liturgical event is relative to)
     * If it is a string, it must be a string that can be interpreted by PHP's strtotime function.
     * If the string contains the word 'before' or 'after', it will be interpreted as being relative to
     * another liturgical event. If it does not contain either of these words, it will be interpreted as an
     * absolute date.
     *
     * If the 'strtotime' property is invalid, an error message will be added to the Messages array and false will be returned.
     *
     * @param object $row The row containing data for the mobile liturgical event from the JSON file
     * @return DateTime|false The date of the mobile liturgical event, or false if there was an error
     */
    private function interpretStrtotime(object $row): DateTime|false
    {
        $strtotime     = $row->liturgical_event->strtotime;
        $strtotimeType = gettype($strtotime);

        if ($strtotimeType === 'object') {
            return $this->handleObjectStrtotime($row);
        } elseif ($strtotimeType === 'string') {
            return $this->handleStringStrtotime($row);
        } else {
            $this->Messages[] = sprintf(
                /**translators: Do not translate 'strtotime'! 1. Name of the mobile liturgical event being created */
                _('Cannot create mobile liturgical event \'%1$s\': \'strtotime\' property must be either an object or a string! Currently it has type \'%2$s\''),
                $row->liturgical_event->name,
                gettype($row->liturgical_event->strtotime)
            );
            return false;
        }
    }

    /**
     * Apply the diocesan calendar specified in the calendar parameters.
     *
     * The diocesan calendar is applied by iterating over the litcal array of the diocesan calendar data.
     * For each liturgical event found in the array, the following is done:
     *  - If the 'sinceYear' property is undefined or null or empty, the liturgical event is created in any case.
     *    Otherwise, the liturgical event is only created if the current year is greater or equal to the 'sinceYear' value.
     *  - If the 'untilYear' property is undefined or null or empty, the liturgical event is created in any case.
     *    Otherwise, the liturgical event is only created if the current year is less or equal to the 'untilYear' value.
     *  - If the liturgical event has a 'strtotime' property, the date of the liturgical event is calculated using the interpretStrtotime method.
     *    Otherwise, the date of the liturgical event is calculated using the format '!j-n-Y' and the day, month and year are taken from the liturgical event data.
     *  - If the liturgical event has a grade greater than FEAST, and there is a coincidence with a different Solemnity on the same day, a message is added to the Messages array.
     *  - If the liturgical event has a grade less or equal to FEAST and there is no coincidence with a Solemnity on the same day, the liturgical event is added to the calendar.
     *  - If the liturgical event has a grade less or equal to FEAST and there is a coincidence with a Solemnity on the same day, the liturgical event is suppressed and a message is added to the Messages array.
     */
    private function applyDiocesanCalendar(): void
    {
        foreach ($this->DiocesanData->litcal as $idx => $obj) {
            //if sinceYear is undefined or null or empty, let's go ahead and create the event in any case
            //creation will be restricted only if explicitly defined by the sinceYear property
            if (
                (
                    $this->CalendarParams->Year >= $obj->metadata->since_year
                    || $obj->metadata->since_year === null
                    || $obj->metadata->since_year === 0
                )
                &&
                (
                    false === property_exists($obj->metadata, 'until_year')
                    || $obj->metadata->until_year === null
                    || $this->CalendarParams->Year <= $obj->metadata->until_year
                    || $obj->metadata->until_year === 0
                )
            ) {
                if (property_exists($obj->liturgical_event, 'strtotime')) {
                    $currentFeastDate = $this->interpretStrtotime($obj);
                } else {
                    $currentFeastDate = DateTime::createFromFormat(
                        '!j-n-Y',
                        $obj->liturgical_event->day . '-' . $obj->liturgical_event->month . '-' . $this->CalendarParams->Year,
                        new \DateTimeZone('UTC')
                    );
                }
                if ($currentFeastDate !== false) {
                    if ($obj->liturgical_event->grade > LitGrade::FEAST) {
                        if ($this->Cal->inSolemnities($currentFeastDate) && $obj->liturgical_event->event_key != $this->Cal->solemnityKeyFromDate($currentFeastDate)) {
                            //there seems to be a coincidence with a different Solemnity on the same day!
                            //should we attempt to move to the next open slot?
                            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> '
                                . $this->CalendarParams->DiocesanCalendar . ': '
                                .  sprintf(
                                    /**translators: 1: LiturgicalEvent name, 2: Name of the diocese, 3: LiturgicalEvent date, 4: Coinciding liturgical event name, 5: Requested calendar year */
                                    _('The Solemnity \'%1$s\', proper to the calendar of the %2$s and usually celebrated on %3$s, coincides with the Sunday or Solemnity \'%4$s\' in the year %5$d! Does something need to be done about this?'),
                                    '<i>' . $obj->liturgical_event->name . '</i>',
                                    $this->DioceseName,
                                    '<b>' . $this->dayAndMonth->format($currentFeastDate->format('U')) . '</b>',
                                    '<i>' . $this->Cal->solemnityFromDate($currentFeastDate)->name . '</i>',
                                    $this->CalendarParams->Year
                                );
                        }
                        $this->Cal->addLiturgicalEvent(
                            $this->CalendarParams->DiocesanCalendar . '_' . $obj->liturgical_event->event_key,
                            new LiturgicalEvent(
                                '[ ' . $this->DioceseName . ' ] ' . $obj->liturgical_event->name,
                                $currentFeastDate,
                                $obj->liturgical_event->color,
                                LitFeastType::FIXED,
                                $obj->liturgical_event->grade,
                                $obj->liturgical_event->common
                            )
                        );
                    } elseif ($obj->liturgical_event->grade <= LitGrade::FEAST && !$this->Cal->inSolemnities($currentFeastDate)) {
                        $this->Cal->addLiturgicalEvent(
                            $this->CalendarParams->DiocesanCalendar . '_' . $obj->liturgical_event->event_key,
                            new LiturgicalEvent(
                                '[ ' . $this->DioceseName . ' ] ' . $obj->liturgical_event->name,
                                $currentFeastDate,
                                $obj->liturgical_event->color,
                                LitFeastType::FIXED,
                                $obj->liturgical_event->grade,
                                $obj->liturgical_event->common
                            )
                        );
                    } else {
                        $this->Messages[] = $this->CalendarParams->DiocesanCalendar . ': ' . sprintf(
                            /**translators: 1: LiturgicalEvent grade, 2: LiturgicalEvent name, 3: Name of the diocese, 4: LiturgicalEvent date, 5: Coinciding liturgical event name, 6: Requested calendar year */
                            _('The %1$s \'%2$s\', proper to the calendar of the %3$s and usually celebrated on %4$s, is suppressed by the Sunday or Solemnity %5$s in the year %6$d'),
                            $this->LitGrade->i18n($obj->liturgical_event->grade, false),
                            '<i>' . $obj->liturgical_event->name . '</i>',
                            $this->DioceseName,
                            '<b>' . $this->dayAndMonth->format($currentFeastDate->format('U')) . '</b>',
                            '<i>' . $this->Cal->solemnityFromDate($currentFeastDate)->name . '</i>',
                            $this->CalendarParams->Year
                        );
                    }
                }
            }
        }
    }

    /**
     * Returns the latest release information from the Liturgical Calendar API
     * on Github. The response is cached for the amount of time specified in the
     * CacheDuration property of the class.
     *
     * If the cache file does not exist, it will make a GET request to the Github
     * API to retrieve the latest release. The response is then cached to the
     * file.
     *
     * @return \stdClass containing the status of the operation and either the
     *         Github API response or an error message.
     */
    private function getGithubReleaseInfo(): \stdClass
    {
        $returnObj          = new \stdClass();
        $ghReleaseCacheFile = $this->CachePath . 'GHRelease' . $this->CacheDuration . '.json';

        if (file_exists($ghReleaseCacheFile)) {
            $ghReleaseJsonStr  = file_get_contents($ghReleaseCacheFile);
            $GitHubReleasesObj = json_decode($ghReleaseJsonStr);
        } else {
            // We always create a cache of the Github Release, even for localhost development,
            // to avoid sending too many requests
            if (false === realpath($this->CachePath)) {
                if (false === mkdir($this->CachePath, 0755, true)) {
                    $message = sprintf(
                        'Could not create cache directory: %s.',
                        $this->CachePath
                    );
                    header('Content-Type: text/html; charset=utf-8');
                    self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
                }
            }

            $GithubReleasesAPI = 'https://api.github.com/repos/Liturgical-Calendar/LiturgicalCalendarAPI/releases/latest';
            /*$GhRequestHeaders  = [
                'Accept: application/vnd.github+json',
                'User-Agent: Liturgical-Calendar/LiturgicalCalendarAPI',
                'X-GitHub-Api-Version: 2022-11-28'
            ];*/

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $GithubReleasesAPI);
            curl_setopt($ch, CURLOPT_USERAGENT, 'LiturgicalCalendar');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            //curl_setopt($ch, CURLOPT_HTTPHEADER, $GhRequestHeaders);
            $ghCurrentReleaseInfo = curl_exec($ch);

            if (curl_errno($ch)) {
                $returnObj->status  = 'error';
                $returnObj->message = curl_error($ch);
            }

            curl_close($ch);
            file_put_contents($ghReleaseCacheFile, $ghCurrentReleaseInfo);
            $GitHubReleasesObj = json_decode($ghCurrentReleaseInfo);
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            $returnObj->status  = 'error';
            $returnObj->message = json_last_error_msg();
        } else {
            $returnObj->status = 'success';
            $returnObj->obj    = $GitHubReleasesObj;
        }
        return $returnObj;
    }

    /**
     * Given a SerializeableLitCal object and a GithubReleases object,
     * constructs and returns a string representing the contents of an iCal
     * file for the requested Liturgical Calendar for the given year.
     *
     * Each event in the iCal file is specific to the requested year and
     * calendar type, so the UID is constructed by hashing the name of the
     * liturgical event, the year, and the date of the liturgical event. This ensures that
     * next year's event will not cancel this year's event.
     *
     * The event created in the calendar is specific to this year, next year
     * it may be different. So UID must take into account the year
     *
     * @param \stdClass $SerializeableLitCal
     * @param \stdClass $GitHubReleasesObj
     *
     * @return string
     */
    private function produceIcal(\stdClass $SerializeableLitCal, \stdClass $GitHubReleasesObj): string
    {
        $ical  = "BEGIN:VCALENDAR\r\n";
        $ical .= "PRODID:-//John Romano D'Orazio//Liturgical Calendar V1.0//EN\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "METHOD:PUBLISH\r\n";
        $ical .= "X-MS-OLK-FORCEINSPECTOROPEN:FALSE\r\n";
        $ical .= 'X-WR-CALNAME:Roman Catholic Liturgical Calendar ' . strtoupper(substr($this->CalendarParams->Locale, 0, 2)) . "\r\n";
        $ical .= "X-WR-TIMEZONE:Europe/Vatican\r\n"; //perhaps allow this to be set through a GET or POST?
        $ical .= "X-PUBLISHED-TTL:PT1D\r\n";

        /** @var EventCollectionItem $CalEvent */
        foreach ($SerializeableLitCal->litcal as $CalEvent) {
            $displayGrade     = '';
            $displayGradeHTML = '';
            if ($CalEvent['grade_display'] !== null) {
                $displayGrade = $CalEvent['grade_display'];
            }
            if ($CalEvent['event_key'] === 'DedicationLateran' || $CalEvent['event_key'] === 'DedicationLateran_vigil') {
                $displayGradeHTML = $this->LitGrade->i18n(LitGrade::FEAST, true);
            } elseif ($CalEvent['grade_display'] === null) {
                $displayGradeHTML = $this->LitGrade->i18n((int)$CalEvent['grade'], true);
            } else {
                if ($CalEvent['grade_display'] === '') {
                    $displayGradeHTML = '';
                } elseif ((int)$CalEvent['grade'] >= LitGrade::FEAST) {
                    $displayGradeHTML = '<B>' . $CalEvent['grade_display'] . '</B>';
                } else {
                    $displayGradeHTML = $CalEvent['grade_display'];
                }
            }

            $description  = $this->LitCommon->c($CalEvent['common']);
            $description .=  '\n' . $displayGrade;
            $description .= (is_array($CalEvent['color']) && count($CalEvent['color']) > 0)
                ? '\n' . Utilities::parseColorString($CalEvent['color'], $this->CalendarParams->Locale, false)
                : '';
            $description .= (
                    isset($CalEvent['liturgical_year']) // no need to check for null value, isset will fail for a null value
                    && $CalEvent['liturgical_year'] !== ''
                )
                ? '\n' . $CalEvent['liturgical_year']
                : '';

            $htmlDescription  = '<P DIR=LTR>' . $this->LitCommon->c($CalEvent['common']);
            $htmlDescription .=  '<BR>' . $displayGradeHTML;
            $htmlDescription .= (is_array($CalEvent['color']) && count($CalEvent['color']) > 0)
                ? '<BR>' . Utilities::parseColorString($CalEvent['color'], $this->CalendarParams->Locale, true)
                : '';
            $htmlDescription .= (
                    isset($CalEvent['liturgical_year']) // no need to check for null value, isset will fail for a null value
                    && $CalEvent['liturgical_year'] != ''
                )
                ? '<BR>' . $CalEvent['liturgical_year'] . '</P>'
                : '</P>';

            $ical .= "BEGIN:VEVENT\r\n";
            $ical .= "CLASS:PUBLIC\r\n";

            $publishDate = $GitHubReleasesObj->published_at;
            $icalDate    = \DateTime::createFromFormat('U', (string)$CalEvent['date'], new \DateTimeZone('UTC'));

            $ical .= 'DTSTART;VALUE=DATE:' . $icalDate->format('Ymd') . "\r\n";// . "T" . $icalDate->format( 'His' ) . "Z\r\n";
            //$CalEvent['date']->add( new \DateInterval( 'P1D' ) );
            //$ical .= "DTEND:" . $icalDate->format( 'Ymd' ) . "T" . $icalDate->format( 'His' ) . "Z\r\n";
            $ical .= 'DTSTAMP:' . date('Ymd') . 'T' . date('His') . "Z\r\n";
            /** The event created in the calendar is specific to this year, next year it may be different.
             *  So UID must take into account the year
             *  Next year's event should not cancel this year's event, they are different events
             **/
            $ical .= 'UID:' . md5('LITCAL-' . $CalEvent['event_key'] . '-' . $icalDate->format('Y')) . "\r\n";
            $ical .= 'CREATED:' . str_replace(':', '', str_replace('-', '', $publishDate)) . "\r\n";

            $desc  = 'DESCRIPTION:' . str_replace(',', '\,', $description);
            $ical .= strlen($desc) > 75 ? rtrim(chunk_split($desc, 71, "\r\n\t")) . "\r\n" : "$desc\r\n";
            $ical .= 'LAST-MODIFIED:' . str_replace(':', '', str_replace('-', '', $publishDate)) . "\r\n";

            $summaryLang = ';LANGUAGE=' . strtolower(preg_replace('/_/', '-', $this->CalendarParams->Locale));
            $summary     = 'SUMMARY' . $summaryLang . ':' . str_replace(',', '\,', str_replace("\r\n", ' ', $CalEvent['name']));

            $ical .= strlen($summary) > 75 ? rtrim(chunk_split($summary, 75, "\r\n\t")) . "\r\n" : $summary . "\r\n";
            $ical .= "TRANSP:TRANSPARENT\r\n";
            $ical .= "X-MICROSOFT-CDO-ALLDAYEVENT:TRUE\r\n";
            $ical .= "X-MICROSOFT-DISALLOW-COUNTER:TRUE\r\n";

            $xAltDesc  = 'X-ALT-DESC;FMTTYPE=text/html:<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 3.2//EN">\n<HTML>\n<BODY>\n\n';
            $xAltDesc .= str_replace(',', '\,', $htmlDescription);
            $xAltDesc .= '\n\n</BODY>\n</HTML>';

            $ical .= strlen($xAltDesc) > 75 ? rtrim(chunk_split($xAltDesc, 71, "\r\n\t")) . "\r\n" : "$xAltDesc\r\n";
            $ical .= "END:VEVENT\r\n";
        }
        $ical .= 'END:VCALENDAR';
        return $ical;
    }

    /**
     * This function generates the response for the requested Liturgical Calendar.
     *
     * Depending on the value of $this->CalendarParams->ReturnType, it will either return a JSON object,
     * a string containing an XML representation of the Liturgical Calendar, a YAML representation of the
     * Liturgical Calendar, or an iCal representation of the Liturgical Calendar.
     *
     * The response is cached for the duration of CacheDuration::LITURGICAL_CALENDAR. If the user requests the
     * same Liturgical Calendar within this time period, the cached response is returned instead of re-calculating
     * the Liturgical Calendar. If the file does not exist or is stale, the function will re-calculate the Liturgical
     * Calendar and cache the response.
     */
    private function generateResponse(): void
    {
        $SerializeableLitCal                                = new \stdClass();
        $SerializeableLitCal->litcal                        = $this->Cal->getLiturgicalEventsCollection();
        $SerializeableLitCal->settings                      = new \stdClass();
        $SerializeableLitCal->settings->year                = $this->CalendarParams->Year;
        $SerializeableLitCal->settings->epiphany            = $this->CalendarParams->Epiphany;
        $SerializeableLitCal->settings->ascension           = $this->CalendarParams->Ascension;
        $SerializeableLitCal->settings->corpus_christi      = $this->CalendarParams->CorpusChristi;
        $SerializeableLitCal->settings->locale              = $this->CalendarParams->Locale;
        $SerializeableLitCal->settings->return_type         = $this->CalendarParams->ReturnType;
        $SerializeableLitCal->settings->year_type           = $this->CalendarParams->YearType;
        $SerializeableLitCal->settings->eternal_high_priest = $this->CalendarParams->EternalHighPriest;
        if ($this->CalendarParams->NationalCalendar !== null) {
            $SerializeableLitCal->settings->national_calendar = $this->CalendarParams->NationalCalendar;
        }
        if ($this->CalendarParams->DiocesanCalendar !== null) {
            $SerializeableLitCal->settings->diocesan_calendar = $this->CalendarParams->DiocesanCalendar;
        }

        $SerializeableLitCal->metadata                         = new \stdClass();
        $SerializeableLitCal->metadata->version                = self::API_VERSION;
        $SerializeableLitCal->metadata->timestamp              = time();
        $SerializeableLitCal->metadata->date_time              = date(DATE_ATOM);
        $SerializeableLitCal->metadata->request_headers        = self::$Core->getRequestHeaders();
        $SerializeableLitCal->metadata->solemnities            = $this->Cal->getSolemnitiesCollection();
        $SerializeableLitCal->metadata->solemnities_keys       = array_column($this->Cal->getSolemnitiesCollection(), 'event_key');
        $SerializeableLitCal->metadata->feasts                 = $this->Cal->getFeastsCollection();
        $SerializeableLitCal->metadata->feasts_keys            = array_column($this->Cal->getFeastsCollection(), 'event_key');
        $SerializeableLitCal->metadata->memorials              = $this->Cal->getMemorialsCollection();
        $SerializeableLitCal->metadata->memorials_keys         = array_column($this->Cal->getMemorialsCollection(), 'event_key');
        $SerializeableLitCal->metadata->suppressed_events      = $this->Cal->getSuppressedEvents();
        $SerializeableLitCal->metadata->suppressed_events_keys = $this->Cal->getSuppressedKeys();
        $SerializeableLitCal->metadata->reinstated_events      = $this->Cal->getReinstatedEvents();
        $SerializeableLitCal->metadata->reinstated_events_keys = $this->Cal->getReinstatedKeys();
        if ($this->CalendarParams->DiocesanCalendar !== null) {
            $SerializeableLitCal->metadata->diocese_name = $this->DioceseName;
        }

        $SerializeableLitCal->messages = $this->Messages;

        // Ensure cache folder exists, except for localhost
        if (false === Router::isLocalhost()) {
            if (false === realpath($this->CachePath)) {
                if (false === mkdir($this->CachePath, 0755, true)) {
                    $message = sprintf(
                        'Could not create cache directory: %s.',
                        $this->CachePath
                    );
                    self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
                }
            }
        }

        $response = null;
        switch ($this->CalendarParams->ReturnType) {
            case ReturnType::JSON:
                $response = json_encode($SerializeableLitCal);
                break;
            case ReturnType::XML:
                // first convert the Object to an Array
                $jsonStr = json_encode($SerializeableLitCal);
                $jsonObj = json_decode($jsonStr, true);

                // then create an XML representation from the Array
                $ns             = 'http://www.bibleget.io/catholicliturgy';
                $schemaLocation = API_BASE_PATH . '/' . JsonData::SCHEMAS_FOLDER . '/LiturgicalCalendar.xsd';
                $xml            = new \SimpleXMLElement(
                    '<?xml version="1.0" encoding="UTF-8"?' . '><LiturgicalCalendar xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
                    . " xsi:schemaLocation=\"$ns $schemaLocation\""
                    . " xmlns=\"$ns\"/>"
                );
                Utilities::convertArray2XML($jsonObj, $xml);
                $rawXML = $xml->asXML(); //this gives us non pretty XML, basically a single long string

                // finally let's pretty print the XML to make the cached file more readable
                $dom                     = new \DOMDocument();
                $dom->preserveWhiteSpace = false;
                $dom->formatOutput       = true;
                $dom->loadXML($rawXML);

                // in the response we return the pretty printed version
                $response = $dom->saveXML();
                break;
            case ReturnType::YAML:
                // first convert the Object to an Array
                $jsonStr = json_encode($SerializeableLitCal);
                $jsonObj = json_decode($jsonStr, true);

                // then create a YAML representation from the Array
                $response = yaml_emit($jsonObj, YAML_UTF8_ENCODING);
                break;
            case ReturnType::ICS:
                $infoObj = $this->getGithubReleaseInfo();
                if ($infoObj->status === 'success') {
                    $response = $this->produceIcal($SerializeableLitCal, $infoObj->obj);
                } else {
                    // if we cannot get the latest release info, we return an error
                    // and we do not produce the iCal file
                    $message = sprintf(
                        _('Error receiving or parsing info from github about latest release: %s.'),
                        $infoObj->message
                    );
                    header('Content-Type: text/html; charset=utf-8');
                    self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
                }
                break;
            default:
                $response = json_encode($SerializeableLitCal);
        }

        if (false === Router::isLocalhost()) {
            file_put_contents($this->CacheFile, $response);
        }
        $responseHash = md5($response);

        $this->endTime = hrtime(true);
        $executionTime = $this->endTime - $this->startTime;
        header('X-LitCal-Starttime: ' . $this->startTime);
        header('X-LitCal-Endtime: ' . $this->endTime);
        header('X-LitCal-Executiontime: ' . $executionTime);

        header("Etag: \"{$responseHash}\"");
        if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $responseHash) {
            header($_SERVER[ 'SERVER_PROTOCOL' ] . ' 304 Not Modified');
            header('Content-Length: 0');
            header('X-LitCal-Generated: ClientCache');
        } else {
            header('X-LitCal-Generated: Calculation');
            echo $response;
        }
        die();
    }

    /**
     * Set up the locale for this API request.
     *
     * Uses the passed-in locale to set the locale for PHP's built-in
     * internationalization functions. Also sets up the formatters for the
     * dates and ordinals, and loads the translation files for the
     * requested locale.
     *
     * @return string|false The locale set by this function, or false
     *                      if the locale could not be set.
     */
    private function prepareL10N(): string|false
    {
        $baseLocale                  = \Locale::getPrimaryLanguage($this->CalendarParams->Locale);
        LitLocale::$PRIMARY_LANGUAGE = $baseLocale;
        $localeArray                 = [
            $this->CalendarParams->Locale . '.utf8',
            $this->CalendarParams->Locale . '.UTF-8',
            $this->CalendarParams->Locale,
            $baseLocale . '_' . strtoupper($baseLocale) . '.utf8',
            $baseLocale . '_' . strtoupper($baseLocale) . '.UTF-8',
            $baseLocale . '_' . strtoupper($baseLocale),
            $baseLocale . '.utf8',
            $baseLocale . '.UTF-8',
            $baseLocale
        ];
        $localeThatWasSet            = setlocale(LC_ALL, $localeArray);

        $this->createFormatters();
        bindtextdomain('litcal', 'i18n');
        textdomain('litcal');

        $this->Cal       = new LiturgicalEventCollection($this->CalendarParams);
        $this->LitCommon = new LitCommon($this->CalendarParams->Locale);
        $this->LitGrade  = new LitGrade($this->CalendarParams->Locale);
        return $localeThatWasSet;
    }

    /**
     * Set the cache duration to use for this calendar.
     *
     * Sets the cache duration for the calendar to one of the predefined
     * values in \LiturgicalCalendar\Api\Enum\CacheDuration.
     *
     * @param CacheDuration $duration The cache duration to use.
     */
    public function setCacheDuration(CacheDuration $duration): void
    {
        switch ($duration) {
            case CacheDuration::DAY:
                $this->CacheDuration = '_' . $duration->value . date('z'); //The day of the year ( starting from 0 through 365 )
                break;
            case CacheDuration::WEEK:
                $this->CacheDuration = '_' . $duration->value . date('W'); //ISO-8601 week number of year, weeks starting on Monday
                break;
            case CacheDuration::MONTH:
                $this->CacheDuration = '_' . $duration->value . date('m'); //Numeric representation of a month, with leading zeros
                break;
            case CacheDuration::YEAR:
                $this->CacheDuration = '_' . $duration->value . date('Y'); //A full numeric representation of a year, 4 digits
                break;
        }
    }

    /**
     * Set the allowed return types.
     *
     * The allowed return types are used to determine which types of responses
     * can be returned by the API.
     *
     * @param array<string> $returnTypes The return types to allow.
     */
    public function setAllowedReturnTypes(array $returnTypes = [ReturnType::JSON]): void
    {
        if (false === empty($this->AllowedReturnTypes)) {
            return; //if we already have the allowed return types, do not change them
        }
        $this->AllowedReturnTypes = array_values(array_intersect(ReturnType::$values, $returnTypes));
    }


    /**
     * Applies the i18n data for the current calendar and the current locale,
     * determined either by the Accept-Language header or the first valid locale
     * for the calendar requested.
     *
     * If we are requesting a national calendar,
     * this method will apply the i18n data for the national calendar.
     *
     * If the national calendar belongs to a wider region,
     * this method will apply the i18n data for the wider region.
     *
     * If we are requesting a diocesan calendar, this method will apply the i18n
     * data for the diocesan calendar and for the related national calendar (and wider region if applicable).
     *
     * @return void
     */
    private function applyCalendarI18nData(): void
    {
        if ($this->CalendarParams->DiocesanCalendar !== null && $this->DiocesanData !== null) {
            $DiocesanDataI18nFile = strtr(
                JsonData::DIOCESAN_CALENDARS_I18N_FILE,
                [
                    '{nation}'  => $this->CalendarParams->NationalCalendar,
                    '{diocese}' => $this->CalendarParams->DiocesanCalendar,
                    '{locale}'  => $this->CalendarParams->Locale
                ]
            );
            $DiocesanDataI18nData = json_decode(file_get_contents($DiocesanDataI18nFile));
            foreach ($this->DiocesanData->litcal as $idx => $value) {
                $event_key                                                = $value->liturgical_event->event_key;
                $this->DiocesanData->litcal[$idx]->liturgical_event->name = $DiocesanDataI18nData->{ $event_key };
            }
        }

        if ($this->CalendarParams->NationalCalendar !== null && $this->NationalData !== null) {
            $NationalDataI18nFile = strtr(
                JsonData::NATIONAL_CALENDARS_I18N_FILE,
                [
                    '{nation}' => $this->CalendarParams->NationalCalendar,
                    '{locale}' => $this->CalendarParams->Locale
                ]
            );
            $NationalDataI18nData = json_decode(file_get_contents($NationalDataI18nFile));
            foreach ($this->NationalData->litcal as $idx => $value) {
                $event_key = $value->liturgical_event->event_key;
                if (property_exists($NationalDataI18nData, $event_key)) {
                    $this->NationalData->litcal[$idx]->liturgical_event->name = $NationalDataI18nData->{ $event_key };
                }
            }
        }

        if ($this->WiderRegionData !== null && property_exists($this->WiderRegionData, 'litcal')) {
            $WiderRegionDataI18nFile = strtr(
                JsonData::WIDER_REGIONS_I18N_FILE,
                [
                    '{wider_region}' => $this->NationalData->metadata->wider_region,
                    '{locale}'       => $this->CalendarParams->Locale
                ]
            );
            $WiderRegionI18nData     = json_decode(file_get_contents($WiderRegionDataI18nFile));
            foreach ($this->WiderRegionData->litcal as $idx => $value) {
                $event_key                                                   = $value->liturgical_event->event_key;
                $this->WiderRegionData->litcal[$idx]->liturgical_event->name = $WiderRegionI18nData->{ $event_key };
            }
        }
    }

    /**
     * The LitCalEngine will only work once you call the public init() method.
     * Do not change the order of the methods that follow,
     * each one can depend on the one before it in order to function correctly!
     * @param array<string|int> $requestPathParts
     */
    public function init(array $requestPathParts = []): void
    {
        self::$Core->init();
        if (self::$Core->getRequestMethod() === RequestMethod::OPTIONS) {
            die();
        }
        $this->initParameterData($requestPathParts);
        $this->loadDiocesanCalendarData();
        $this->loadNationalCalendarData();
        $this->updateSettingsBasedOnNationalCalendar();
        $this->updateSettingsBasedOnDiocesanCalendar();
        $this->applyCalendarI18nData();
        self::$Core->setResponseContentTypeHeader();
        $this->CachePath = 'engineCache/v' . str_replace('.', '_', self::API_VERSION) . '/';

        if (false === Router::isLocalhost() && $this->cacheFileIsAvailable()) {
            //If we already have done the calculation
            //and stored the results in a cache file
            //then we're done, just output this and die
            //or better, make the client use it's own cache copy!
            $response      = file_get_contents($this->CacheFile);
            $responseHash  = md5($response);
            $this->endTime = hrtime(true);
            $executionTime = $this->endTime - $this->startTime;
            header('X-LitCal-Starttime: ' . $this->startTime);
            header('X-LitCal-Endtime: ' . $this->endTime);
            header('X-LitCal-Executiontime: ' . $executionTime);

            header("Etag: \"{$responseHash}\"");
            if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $responseHash) {
                header($_SERVER[ 'SERVER_PROTOCOL' ] . ' 304 Not Modified');
                header('Content-Length: 0');
                header('X-LitCal-Generated: ClientCache');
            } else {
                header('X-LitCal-Generated: ServerCache');
                echo $response;
            }
            die();
        } else {
            $this->dieIfBeforeMinYear();
            $localeThatWasSet = $this->prepareL10N();
            LiturgicalEvent::setLocale($this->CalendarParams->Locale);
            $this->calculateUniversalCalendar();
            if ($this->CalendarParams->NationalCalendar !== null && $this->NationalData !== null) {
                $this->applyNationalCalendar();
            }

            // :SATURDAY_MEMORIAL_BVM
            $this->calculateSaturdayMemorialBVM();

            if ($this->CalendarParams->DiocesanCalendar !== null && $this->DiocesanData !== null) {
                $this->applyDiocesanCalendar();
            }

            $this->Cal->setCyclesVigilsSeasons();
            // For any celebrations that do not yet have a psalter_week property, make an attempt to calculate the value if applicable
            $this->Cal->calculatePsalterWeek();

            if ($this->CalendarParams->YearType === YearType::LITURGICAL) {
                // Save the state of the current Calendar calculation
                $this->Cal->sortLiturgicalEvents();
                $CalBackup      = clone( $this->Cal );
                $Messages       = $this->Messages;
                $this->Messages = [];

                // Calculate the calendar for the previous year
                $this->CalendarParams->Year--;
                $this->Cal = new LiturgicalEventCollection($this->CalendarParams);

                $this->calculateUniversalCalendar();

                if ($this->CalendarParams->NationalCalendar !== null && $this->NationalData !== null) {
                    $this->applyNationalCalendar();
                }

                // :SATURDAY_MEMORIAL_BVM
                $this->calculateSaturdayMemorialBVM();

                if ($this->CalendarParams->DiocesanCalendar !== null && $this->DiocesanData !== null) {
                    $this->applyDiocesanCalendar();
                }

                $this->Cal->setCyclesVigilsSeasons();
                $this->Cal->calculatePsalterWeek();
                $this->Cal->sortLiturgicalEvents();

                $this->Cal->purgeDataBeforeAdvent();
                $CalBackup->purgeDataAdventChristmas();

                // Now we have to combine the two.
                // The backup (which represents the main portion) should be appended to the calendar that was just generated
                $this->Cal->mergeLiturgicalEventCollection($CalBackup);

                // Reset the year back to the original request before outputting results
                $this->CalendarParams->Year++;

                // Append the messages from the backup calendar (current year) to the current messages (previous year)
                array_push($this->Messages, ...$Messages);

                $this->generateResponse();
            } else {
                $this->Cal->sortLiturgicalEvents();
                $this->generateResponse();
            }
        }
    }
}
