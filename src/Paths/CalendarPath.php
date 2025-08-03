<?php

namespace LiturgicalCalendar\Api\Paths;

use LiturgicalCalendar\Api\Core;
use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\LatinUtils;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Utilities;
use LiturgicalCalendar\Api\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\CacheDuration;
use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Enum\CorpusChristi;
use LiturgicalCalendar\Api\Enum\DateRelation;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitCommon;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\RequestContentType;
use LiturgicalCalendar\Api\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\ReturnType;
use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Enum\StatusCode;
use LiturgicalCalendar\Api\Enum\YearType;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsMap;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItem;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemCollection;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemCreateNewMobile;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemMakeDoctor;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemMakeDoctorMetadata;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemSetPropertyGrade;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemSetPropertyGradeMetadata;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemSetPropertyName;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemSetPropertyNameMetadata;
use LiturgicalCalendar\Api\Models\LitCalItem;
use LiturgicalCalendar\Api\Models\LitCalItemCollection;
use LiturgicalCalendar\Api\Models\MissalsMap;
use LiturgicalCalendar\Api\Models\PropriumDeSanctisEvent;
use LiturgicalCalendar\Api\Models\PropriumDeSanctisMap;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeMap;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeEvent;
use LiturgicalCalendar\Api\Models\RelativeLiturgicalDate;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanData;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\LitCalItemCreateNewFixed as DiocesanLitCalItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\LitCalItemCreateNewMobile as DiocesanLitCalItemCreateNewMobile;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewMetadata;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewMobile;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemMakePatron;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemMakePatronMetadata;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemMoveEvent;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemMoveEventMetadata;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemSetPropertyGrade;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemSetPropertyGradeMetadata;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemSetPropertyName;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemSetPropertyNameMetadata;
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData\WiderRegionData;
use LiturgicalCalendar\Api\Params\CalendarParams;

/**
 * Class Calendar
 *
 * This class is responsible for generating the Liturgical Calendar.
 *
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
 */
final class CalendarPath
{
    public static Core $Core;
    /** @var ReturnType[] */ private array $AllowedReturnTypes; // can only be set once, after which it will be read-only
    /** @var CatholicDiocesesLatinRite */ private array $worldDiocesesLatinRite; // can only be set once, after which it will be read-only
    private CalendarParams $CalendarParams;
    private \NumberFormatter $formatter;
    private \NumberFormatter $formatterFem;
    private \IntlDateFormatter $dayAndMonth;
    private \IntlDateFormatter $dayOfTheWeek;
    private \IntlDateFormatter $dayOfTheWeekEnglish;
    private LiturgicalEventCollection $Cal;
    private int $startTime;
    private int $endTime;
    private string $BaptismLordFmt;
    private string $BaptismLordMod;

    public const API_VERSION                  = '4.5';
    private string $CachePath                 = '';
    private string $CacheFile                 = '';
    private string $CacheDuration             = '';
    private ?string $DioceseName              = null;
    private ?DiocesanData $DiocesanData       = null;
    private ?NationalData $NationalData       = null;
    private ?WiderRegionData $WiderRegionData = null;
    private PropriumDeTemporeMap $PropriumDeTempore;
    private MissalsMap $missalsMap;
    private DecreeItemCollection $decreeItems;
    /** @var string[] */
    private array $Messages = [];


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
            self::$Core->setResponseContentType(self::$Core->getAllowedAcceptHeaders()[0]);
            self::$Core->setResponseContentTypeHeader();
        }
        header($_SERVER['SERVER_PROTOCOL'] . StatusCode::toString($statusCode), true, $statusCode);
        $message              = new \stdClass();
        $message->status      = 'ERROR';
        $message->description = $description;
        $response             = json_encode($message);
        if (JSON_ERROR_NONE !== json_last_error() || false === $response) {
            $response = '{"status": "ERROR", "description": ' . json_last_error_msg() . '}';
        }
        switch (self::$Core->getResponseContentType()) {
            case AcceptHeader::YAML:
                $responseObj = json_decode($response, true);
                if (JSON_ERROR_NONE !== json_last_error()) {
                    $responseObj = [ 'status' => 'ERROR', 'description' => json_last_error_msg() ];
                }
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
        /** @var array<string,mixed> $params */
        $params = [];

        // Any request with URL parameters (a query string) will populate the $_GET global associative array
        if (!empty($_GET)) {
            /** @var array<string,mixed> $params */
            $params = $_GET;
        }

        // Merge any URL parameters with the data from the request body
        // Body parameters will override URL parameters
        if (self::$Core->getRequestContentType() === RequestContentType::JSON) {
            $bodyParams = self::$Core->readJsonBody(false, true);
            if ($bodyParams !== null) {
                /**
                 * @var array<string,mixed> $bodyParams
                 * @var array<string,mixed> $params
                 */
                $params = array_merge(
                    $params,
                    $bodyParams
                );
            }
        }
        elseif (self::$Core->getRequestContentType() === RequestContentType::YAML) {
            $bodyParams = self::$Core->readYamlBody(false, true);
            if ($bodyParams !== null) {
                /**
                 * @var array<string,mixed> $bodyParams
                 * @var array<string,mixed> $params
                 */
                $params = array_merge(
                    $params,
                    $bodyParams
                );
            }
        }
        elseif (self::$Core->getRequestContentType() === RequestContentType::FORMDATA) {
            if (!empty($_POST)) {
                /**
                 * @var array<string,mixed> $params
                 * @var array<string,mixed> $_POST
                 */
                $params = array_merge(
                    $params,
                    $_POST
                );
            }
        }
        /** @var array<string,mixed> $params */
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
                    $params['year'] = (int) $requestPathParts[0];
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
                        $params['national_calendar'] = (string) $requestPathParts[1];
                    } elseif ($requestPathParts[0] === 'diocese') {
                        $params['diocesan_calendar'] = (string) $requestPathParts[1];
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
                        $params['year'] = (int) $requestPathParts[2];
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
                    . implode(' and ', array_column($this->AllowedReturnTypes, 'value'))
                    . ', but you have issued a parameter requesting a Content Type of '
                    . $this->CalendarParams->ReturnType->value;
                self::produceErrorResponse(StatusCode::NOT_ACCEPTABLE, $description);
            }
            self::$Core->setResponseContentType(
                self::$Core->getAllowedAcceptHeaders()[array_search($this->CalendarParams->ReturnType, $this->AllowedReturnTypes)]
            );
        } else {
            if (self::$Core->hasAcceptHeader()) {
                if (self::$Core->isAllowedAcceptHeader()) {
                    $this->CalendarParams->ReturnType = $this->AllowedReturnTypes[self::$Core->getIdxAcceptHeaderInAllowed()];
                    $acceptHeader                     = AcceptHeader::from(self::$Core->getAcceptHeader());
                    self::$Core->setResponseContentType($acceptHeader);
                } else {
                    //Requests from browser windows using the address bar will probably have an Accept header of text/html
                    //In order to not be too drastic, let's treat text/html as though it were application/json
                    $acceptHeaders = explode(',', self::$Core->getAcceptHeader());
                    if (in_array('text/html', $acceptHeaders) || in_array('text/plain', $acceptHeaders) || in_array('*/*', $acceptHeaders)) {
                        $this->CalendarParams->ReturnType = ReturnType::JSON;
                        self::$Core->setResponseContentType(AcceptHeader::JSON);
                    } else {
                        $description = 'You are requesting a content type which this API cannot produce. Allowed Accept headers are '
                            . implode(' and ', array_column(self::$Core->getAllowedAcceptHeaders(), 'value'))
                            . ', but you have issued an request with an Accept header of '
                            . self::$Core->getAcceptHeader();
                        self::produceErrorResponse(StatusCode::NOT_ACCEPTABLE, $description);
                    }
                }
            } else {
                $this->CalendarParams->ReturnType = $this->AllowedReturnTypes[0];
                self::$Core->setResponseContentType(self::$Core->getAllowedAcceptHeaders()[0]);
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
        // We don't check if ($this->NationalData === null) because in the case of the Vatican, we won't have $this->NationalData
        if ($this->CalendarParams->NationalCalendar === null) {
            return;
        }

        if ($this->CalendarParams->NationalCalendar === 'VA') {
            $this->CalendarParams->Epiphany          = Epiphany::JAN6;
            $this->CalendarParams->Ascension         = Ascension::THURSDAY;
            $this->CalendarParams->CorpusChristi     = CorpusChristi::THURSDAY;
            $this->CalendarParams->Locale            = LitLocale::LATIN;
            $this->CalendarParams->EternalHighPriest = false;
        } else {
            $this->CalendarParams->Epiphany          = $this->NationalData->settings->epiphany;
            $this->CalendarParams->Ascension         = $this->NationalData->settings->ascension;
            $this->CalendarParams->CorpusChristi     = $this->NationalData->settings->corpus_christi;
            $this->CalendarParams->EternalHighPriest = $this->NationalData->settings->eternal_high_priest;
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
        if ($this->CalendarParams->DiocesanCalendar === null || $this->DiocesanData === null) {
            return;
        }

        if ($this->DiocesanData->hasSettings()) {
            foreach ($this->DiocesanData->settings as $key => $value) {
                switch ($key) {
                    case 'epiphany':
                        /** @var Epiphany $value */
                        $this->CalendarParams->Epiphany = $value;
                        break;
                    case 'ascension':
                        /** @var Ascension $value */
                        $this->CalendarParams->Ascension = $value;
                        break;
                    case 'corpus_christi':
                        /** @var CorpusChristi $value */
                        $this->CalendarParams->CorpusChristi = $value;
                        break;
                }
            }
        }

        if (count($this->DiocesanData->metadata->locales) === 1) {
            $this->CalendarParams->Locale = $this->DiocesanData->metadata->locales[0];
        } else {
            // If multiple locales are available for the diocesan calendar,
            // the desired locale should be set in the Accept-Language header.
            // We should however check that this is an available locale for the current Diocesan Calendar,
            // and if not use the first valid value.
            if (false === in_array($this->CalendarParams->Locale, $this->DiocesanData->metadata->locales)) {
                $this->CalendarParams->Locale = $this->DiocesanData->metadata->locales[0];
            }
        }
    }

    /**
     * Takes a diocese ID and returns the corresponding diocese name.
     * If the diocese ID is not found, returns null.
     *
     * @param string $id The diocese ID.
     * @return array{diocese_name:string,nation:string}|null The diocese name and nation, or null if not found.
     */
    private function dioceseIdToName(string $id): ?array
    {
        if (empty($this->worldDiocesesLatinRite)) {
            $worldDiocesesFile = JsonData::FOLDER . '/world_dioceses.json';
            $worldDiocesesRaw  = file_get_contents($worldDiocesesFile);
            if ($worldDiocesesRaw === false) {
                return null;
            }
            $worldDiocesesJson = json_decode($worldDiocesesRaw);
            if (JSON_ERROR_NONE !== json_last_error()) {
                return null;
            }
            $this->worldDiocesesLatinRite = $worldDiocesesJson->catholic_dioceses_latin_rite;
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
        if ($this->CalendarParams->DiocesanCalendar === null) {
            return;
        }

        $idTransform = CalendarPath::dioceseIdToName($this->CalendarParams->DiocesanCalendar);
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
                JsonData::DIOCESAN_CALENDAR_FILE,
                [
                    '{nation}'       => $this->CalendarParams->NationalCalendar,
                    '{diocese}'      => $this->CalendarParams->DiocesanCalendar,
                    '{diocese_name}' => $dioceseName
                ]
            );

            $diocesanDataJson = Utilities::jsonFileToObject($diocesanDataFile);
            if (is_array($diocesanDataJson)) {
                throw new \Error('The diocesan calendar data file ' . $diocesanDataFile . ' should have produced an object, but instead produced an array.');
            }
            $this->DiocesanData = DiocesanData::fromObject($diocesanDataJson);
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
        $cacheFileName           = $paramsHash . $this->CacheDuration . '.' . strtolower($this->CalendarParams->ReturnType->value);
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
        $this->dayAndMonth = \IntlDateFormatter::create(
            LitLocale::$PRIMARY_LANGUAGE,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            'd MMMM'
        );

        $this->dayOfTheWeek = \IntlDateFormatter::create(
            LitLocale::$PRIMARY_LANGUAGE,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            'EEEE'
        );

        $this->dayOfTheWeekEnglish = \IntlDateFormatter::create(
            'en',
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            'EEEE'
        );

        $this->formatter = new \NumberFormatter(
            LitLocale::$PRIMARY_LANGUAGE,
            \NumberFormatter::SPELLOUT
        );

        //follow rules as indicated here:
        // https://www.saxonica.com/html/documentation11/extensibility/localizing/ICU-numbering-dates/ICU-numbering.html
        if (in_array(LitLocale::$PRIMARY_LANGUAGE, self::GENERIC_SPELLOUT_ORDINAL)) {
            $this->formatter->setTextAttribute(\NumberFormatter::DEFAULT_RULESET, '%spellout-ordinal');
            //feminine version will be the same as masculine
            $this->formatterFem = $this->formatter;
        } elseif (in_array(LitLocale::$PRIMARY_LANGUAGE, self::MASC_FEM_SPELLOUT_ORDINAL) || in_array(LitLocale::$PRIMARY_LANGUAGE, self::MASC_FEM_NEUT_SPELLOUT_ORDINAL)) {
            $this->formatter->setTextAttribute(\NumberFormatter::DEFAULT_RULESET, '%spellout-ordinal-masculine');
            $this->formatterFem = new \NumberFormatter(LitLocale::$PRIMARY_LANGUAGE, \NumberFormatter::SPELLOUT);
            $this->formatterFem->setTextAttribute(\NumberFormatter::DEFAULT_RULESET, '%spellout-ordinal-feminine');
        } elseif (in_array(LitLocale::$PRIMARY_LANGUAGE, self::COMMON_NEUT_SPELLOUT_ORDINAL)) {
            $this->formatter->setTextAttribute(\NumberFormatter::DEFAULT_RULESET, '%spellout-ordinal-common');
            //feminine version will be the same as masculine
            $this->formatterFem = $this->formatter;
        } else {
            $this->formatter = new \NumberFormatter(LitLocale::$PRIMARY_LANGUAGE, \NumberFormatter::ORDINAL);
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
     * @return array<string,string> The loaded data, or null if there was an error.
     */
    private function loadPropriumDeTemporeI18nData(): array
    {
        $locale                    = LitLocale::$PRIMARY_LANGUAGE;
        $propriumDeTemporeI18nFile = strtr(
            JsonData::MISSAL_I18N_FILE,
            ['{missal_folder}' => 'propriumdetempore', '{locale}' => $locale]
        );

        $propriumDeTemporeI18nArr = Utilities::jsonFileToArray($propriumDeTemporeI18nFile);
        if (array_filter(array_keys($propriumDeTemporeI18nArr), 'is_string') !== array_keys($propriumDeTemporeI18nArr)) {
            throw new \Exception('We expected all the keys of the array to be strings.');
        }
        if (array_filter($propriumDeTemporeI18nArr, 'is_string') !== $propriumDeTemporeI18nArr) {
            throw new \Exception('We expected all the values of the array to be strings.');
        }
        /** @var array<string,string> $propriumDeTemporeI18nArr */
        return $propriumDeTemporeI18nArr;
    }

    /**
     * Retrieve Higher Ranking Solemnities from Proprium de Tempore
     */
    private function loadPropriumDeTemporeData(): void
    {
        $propriumDeTemporeFile = strtr(
            JsonData::MISSAL_FILE,
            ['{missal_folder}' => 'propriumdetempore']
        );

        $PropriumDeTempore = Utilities::jsonFileToObject($propriumDeTemporeFile);
        if (false === is_array($PropriumDeTempore)) {
            throw new \Exception('We expected the Proprium de Tempore data to be an array of Proprium de Tempore objects.');
        }
        $PropriumDeTemporeI18n   = $this->loadPropriumDeTemporeI18nData();
        $this->PropriumDeTempore = PropriumDeTemporeMap::fromObject($PropriumDeTempore);
        $this->PropriumDeTempore->setNames($PropriumDeTemporeI18n);
    }

    /**
     * Loads the Proprium de Sanctis (Sanctorale) data from JSON files for the given Roman Missal,
     * along with its i18n data and lectionary data.
     *
     * Will produce an Http Status code of 503 Service Unavailable for the API response if it encounters an error
     * while parsing the JSON data.
     *
     * @param string $missal The name of the Roman Missal to load the data for.
     */
    private function loadPropriumDeSanctisData(string $missal): void
    {
        $propriumdesanctisFile           = RomanMissal::getSanctoraleFileName($missal);
        $propriumdesanctisI18nPath       = RomanMissal::getSanctoraleI18nFilePath($missal);
        $propriumdesanctisLectionaryPath = RomanMissal::getLectionaryFilePath($missal);
        $i18nData                        = null;

        if (null === $propriumdesanctisFile || null === $propriumdesanctisI18nPath) {
            throw new \InvalidArgumentException('Invalid Roman Missal id: ' . $missal);
        }

        if (false === $propriumdesanctisFile || false === $propriumdesanctisI18nPath) {
            if (str_starts_with($missal, 'EDITIO_TYPICA_')) {
                throw new \Exception('RomanMissal enum did not give the file with Proprium de Sanctis data for the sanctorale from ' . RomanMissal::getName($missal));
            }
            // If a language edition Roman Missal has no associated data or translation data,
            // we just skip it, nothing to do
            return;
        }

        /**
         * Load the Sanctorale data for the Roman Missal
         */
        $propriumDeSanctis = Utilities::jsonFileToObject($propriumdesanctisFile);
        if (false === is_array($propriumDeSanctis)) {
            throw new \Exception('We expected the Proprium de Sanctis data to be an array of Proprium de Sanctis objects.');
        }

        if (false === isset($this->missalsMap)) {
            // Initialize this->missalsMap if not yet initialized
            $missalsMap          = [];
            $missalsMap[$missal] = PropriumDeSanctisMap::fromObject($propriumDeSanctis);
            $this->missalsMap    = MissalsMap::initWithMissals($missalsMap);
        } else {
            $this->missalsMap[$missal] = PropriumDeSanctisMap::fromObject($propriumDeSanctis);
        }

        /**
         * If the Roman Missal has sanctorale data, we must ensure that translation data is also available,
         * even if only for a single language.
         * For latin edition Roman Missals, the language data is not specific to any country,
         * so the language files are named by the primary language without any kind of country identifier.
         * For language edition Roman Missals, the language data is specific to the country,
         * so the language files aare named with both primary language and country identifier.
         */
        if (str_starts_with($missal, 'EDITIO_TYPICA_')) {
            $propriumdesanctisI18nFile = $propriumdesanctisI18nPath . LitLocale::$PRIMARY_LANGUAGE . '.json';
        } else {
            $propriumdesanctisI18nFile = $propriumdesanctisI18nPath . $this->CalendarParams->Locale . '.json';
        }

        $i18nData = Utilities::jsonFileToArray($propriumdesanctisI18nFile);
        if (array_filter(array_keys($i18nData), 'is_string') !== array_keys($i18nData)) {
            throw new \Exception('We expected all the keys of the array to be strings.');
        }
        if (array_filter($i18nData, 'is_string') !== $i18nData) {
            throw new \Exception('We expected all the values of the array to be strings.');
        }
        /** @var array<string,string> $i18nData */
        $this->missalsMap[$missal]->setNames($i18nData);

        /**
         * If the Roman Missal has lectionary data, we load that too.
         * Latin edition Roman Missals do not have lectionary data,
         * because that is taken care already by the `jsondata/sourcedata/lectionary` folder,
         * which corresponds to the most recent edition of the Lectionaries
         * (at least as published in Italy, which is kind of the main reference when it comes to Lectionaries?).
         * Therefore we can safely use the fully identified locale (primary language with country identifier)
         * when looking up the lectionary file.
         */
        if ($propriumdesanctisLectionaryPath !== false) {
            $lectionary = $propriumdesanctisLectionaryPath . $this->CalendarParams->Locale . '.json';
            if (file_exists($lectionary)) {
                $this->Cal::$lectionary->addSanctoraleReadingsFromFile($lectionary);
            }
        }
    }

    /**
     * Loads the Decrees of the Congregation for Divine Worship from the relative JSON file.
     *
     * Decrees establish new memorials, optional memorials, or modify existing liturgical events.
     * Decrees can also declare Doctors of the Church, whether as new liturgical events,
     * or modifying existing liturgical events.
     */
    private function loadMemorialsFromDecreesData(): void
    {
        $locale          = LitLocale::$PRIMARY_LANGUAGE;
        $decreesI18nFile = strtr(
            JsonData::DECREES_I18N_FILE,
            ['{locale}' => $locale]
        );

        $names   = Utilities::jsonFileToArray($decreesI18nFile);
        $decrees = Utilities::jsonFileToObject(JsonData::DECREES_FILE);
        if (false === is_array($decrees)) {
            throw new \Exception('We expected the Decrees data to be an array of Decree objects.');
        }
        if (array_filter(array_keys($names), 'is_string') !== array_keys($names)) {
            throw new \Exception('We expected all the keys of the array to be strings.');
        }
        if (array_filter($names, 'is_string') !== $names) {
            throw new \Exception('We expected all the values of the array to be strings.');
        }
        /** @var array<string,string> $names */
        DecreeItemCollection::setNames($decrees, $names);
        $this->decreeItems = DecreeItemCollection::fromObject($decrees);
    }

    /**
     * Creates a LiturgicalEvent object from an entry in the Proprium de Tempore and adds it to the calendar
     * @param string $key The key of the LiturgicalEvent in the Proprium de Tempore
     * @return LiturgicalEvent The new LiturgicalEvent object
     */
    private function createPropriumDeTemporeLiturgicalEventByKey(?string $key = null): LiturgicalEvent
    {
        if (null === $key || false === $this->PropriumDeTempore->offsetExists($key)) {
            die("createPropriumDeTemporeLiturgicalEventByKey requires a key from the Proprium de Tempore, instead got $key");
        }
        $event = LiturgicalEvent::fromObject($this->PropriumDeTempore[$key]);
        $this->Cal->addLiturgicalEvent($key, $event);
        return $event;
    }

    /**
     * Calculates the dates for Holy Thursday, Good Friday, Easter Vigil and Easter Sunday
     * and creates the corresponding LiturgicalEvents in the calendar
     *
     * **General Norms for the Liturgical Year and the Calendar**
     *
     * I.
     * 1. ***Easter Triduum of the Lord's Passion and Resurrection***
     * 2. Christmas, Epiphany, Ascension, and Pentecost
     */
    private function calculateEasterTriduum(): void
    {
        $this->PropriumDeTempore['HolyThurs']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)->sub(new \DateInterval('P3D')));
        $this->PropriumDeTempore['GoodFri']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)->sub(new \DateInterval('P2D')));
        $this->PropriumDeTempore['EasterVigil']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)->sub(new \DateInterval('P1D')));
        $this->PropriumDeTempore['Easter']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year));
        $this->createPropriumDeTemporeLiturgicalEventByKey('HolyThurs');
        $this->createPropriumDeTemporeLiturgicalEventByKey('GoodFri');
        $this->createPropriumDeTemporeLiturgicalEventByKey('EasterVigil');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter');
    }


    /**
     * Calculates the dates for Christmas and Epiphany
     * and creates the corresponding LiturgicalEvents in the calendar
     *
     * **General Norms for the Liturgical Year and the Calendar**
     *
     * I.
     * 1. Easter Triduum of the Lord's Passion and Resurrection
     * 2. ***Christmas, Epiphany, Ascension, and Pentecost***
     */
    private function calculateChristmasEpiphany(): void
    {
        // Calculate Christmas
        $this->PropriumDeTempore['Christmas']->setDate(DateTime::fromFormat('25-12-' . $this->CalendarParams->Year));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Christmas');

        // Calculate Epiphany (and the "Second Sunday of Christmas" if applicable)
        switch ($this->CalendarParams->Epiphany) {
            case Epiphany::JAN6:
                $this->PropriumDeTempore['Epiphany']->setDate(DateTime::fromFormat('6-1-' . $this->CalendarParams->Year));
                $this->createPropriumDeTemporeLiturgicalEventByKey('Epiphany');

                // if a Sunday falls between Jan. 2 and Jan. 5, it is called the "Second Sunday of Christmas"
                for ($i = 2; $i < 6; $i++) {
                    $dateTime = DateTime::fromFormat($i . '-1-' . $this->CalendarParams->Year);
                    if (self::dateIsSunday($dateTime)) {
                        $this->PropriumDeTempore['Christmas2']->setDate($dateTime);
                        $this->createPropriumDeTemporeLiturgicalEventByKey('Christmas2');
                        break;
                    }
                }
                break;
            case Epiphany::SUNDAY_JAN2_JAN8:
                //If January 2nd is a Sunday, then go with Jan 2nd
                $dateTime = DateTime::fromFormat('2-1-' . $this->CalendarParams->Year);
                if (self::dateIsSunday($dateTime)) {
                    $this->PropriumDeTempore['Epiphany']->setDate($dateTime);
                    $this->createPropriumDeTemporeLiturgicalEventByKey('Epiphany');
                } else {
                    //otherwise find the Sunday following Jan 2nd
                    $SundayOfEpiphany = $dateTime->modify('next Sunday');
                    $this->PropriumDeTempore['Epiphany']->setDate($SundayOfEpiphany);
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
        $DayOfEpiphany = (int) $Epiphany->date->format('j');
        for ($i = 2; $i < $DayOfEpiphany; $i++) {
            $dateTime = DateTime::fromFormat($i . '-1-' . $this->CalendarParams->Year);
            if (false === self::dateIsSunday($dateTime) && $this->Cal->notInSolemnitiesFeastsOrMemorials($dateTime)) {
                $nth++;
                $locale       = LitLocale::$PRIMARY_LANGUAGE;
                $dayOfTheWeek = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? LatinUtils::LATIN_DAYOFTHEWEEK[$dateTime->format('w')]
                    : ( $locale === 'it'
                        ? Utilities::ucfirst($this->dayAndMonth->format($dateTime->format('U')))
                        : Utilities::ucfirst($this->dayOfTheWeek->format($dateTime->format('U')))
                    );
                $name         = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? sprintf('%s temporis Nativitatis', $dayOfTheWeek)
                    : ( $locale === 'it'
                        ? sprintf('Feria propria del %s', $dayOfTheWeek)
                        : sprintf(
                            /**translators: days before Epiphany (not useful in Italian!) */
                            _('%s - Christmas Weekday'),
                            $dayOfTheWeek
                        )
                    );
                $dayOfTheMonth = $dateTime->format('j');
                $event_key     = 'ChristmasWeekdayJan' . $dayOfTheMonth;
                $litEvent      = new LiturgicalEvent(
                    $name,
                    $dateTime,
                    LitColor::WHITE,
                    LitEventType::MOBILE,
                    LitGrade::WEEKDAY,
                    LitCommon::NONE,
                    null
                );
                $this->Cal->addLiturgicalEvent($event_key, $litEvent);
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
        $DayOfEpiphany    = (int) $Epiphany->date->format('j');
        $BaptismLord      = $this->Cal->getLiturgicalEvent('BaptismLord');
        $DayOfBaptismLord = (int) $BaptismLord->date->format('j');
        $nth              = 0;
        for ($i = $DayOfEpiphany + 1; $i < $DayOfBaptismLord; $i++) {
            $nth++;
            $dateTime = DateTime::fromFormat($i . '-1-' . $this->CalendarParams->Year);
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($dateTime)) {
                $locale         = LitLocale::$PRIMARY_LANGUAGE;
                $dayOfTheWeek   = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? LatinUtils::LATIN_DAYOFTHEWEEK[$dateTime->format('w')]
                    : ( $locale === 'it'
                        ? Utilities::ucfirst($this->dayAndMonth->format($dateTime->format('U')))
                        : Utilities::ucfirst($this->dayOfTheWeek->format($dateTime->format('U')))
                    );
                $dayOfTheWeekEn = $this->dayOfTheWeekEnglish->format($dateTime->format('U'));
                $name           = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? sprintf('%s temporis Nativitatis', $dayOfTheWeek)
                    : ( $locale === 'it'
                        ? sprintf('Feria propria del %s', $dayOfTheWeek)
                        : sprintf(
                            /**translators: days after Epiphany when Epiphany falls on Jan 6 (not useful in Italian!) */
                            _('%s - Christmas Weekday'),
                            $dayOfTheWeek
                        )
                    );
                $dayOfTheMonth = $dateTime->format('j');
                $event_key     = $this->CalendarParams->Epiphany === Epiphany::SUNDAY_JAN2_JAN8 ? 'DayAfterEpiphany' . $dayOfTheWeekEn : 'DayAfterEpiphanyJan' . $dayOfTheMonth;
                $litEvent      = new LiturgicalEvent(
                    $name,
                    $dateTime,
                    LitColor::WHITE,
                    LitEventType::MOBILE,
                    LitGrade::WEEKDAY,
                    LitCommon::NONE,
                    null
                );
                $this->Cal->addLiturgicalEvent($event_key, $litEvent);
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
     * **General Norms for the Liturgical Year and the Calendar**
     *
     * I.
     * 1. Easter Triduum of the Lord's Passion and Resurrection
     * 2. ***Christmas, Epiphany, Ascension, and Pentecost***
     *
     * @return void
     */
    private function calculateAscensionPentecost(): void
    {
        if ($this->CalendarParams->Ascension === Ascension::THURSDAY) {
            $this->PropriumDeTempore['Ascension']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P39D')));
            $this->createPropriumDeTemporeLiturgicalEventByKey('Ascension');
            $this->PropriumDeTempore['Easter7']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
                ->add(new \DateInterval('P' . ( 7 * 6 ) . 'D')));
            $this->createPropriumDeTemporeLiturgicalEventByKey('Easter7');
        } elseif ($this->CalendarParams->Ascension === Ascension::SUNDAY) {
            $this->PropriumDeTempore['Ascension']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
                ->add(new \DateInterval('P' . ( 7 * 6 ) . 'D')));
            $this->createPropriumDeTemporeLiturgicalEventByKey('Ascension');
        }

        $this->PropriumDeTempore['Pentecost']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 7 ) . 'D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Pentecost');
    }

    /**
     * Calculates the dates for Sundays of Advent, Lent, Easter, Ordinary Time, and special Sundays like Palm Sunday, Corpus Christi, and Trinity Sunday
     * and creates the corresponding LiturgicalEvents in the calendar
     *
     * **General Norms for the Liturgical Year and the Calendar**
     *
     * I.
     * 1. Easter Triduum of the Lord's Passion and Resurrection
     * 2. Christmas, Epiphany, Ascension, and Pentecost;
     *    ***Sundays of Advent, Lent and Easter***
     *
     * @return void
     */
    private function calculateSundaysMajorSeasons(): void
    {
        //We calculate Sundays of Advent based on Christmas
        $christmasDateStr = '25-12-' . $this->CalendarParams->Year;

        $this->PropriumDeTempore['Advent1']->setDate(DateTime::fromFormat($christmasDateStr)
            ->modify('last Sunday')->sub(new \DateInterval('P' . ( 3 * 7 ) . 'D')));
        $this->PropriumDeTempore['Advent2']->setDate(DateTime::fromFormat($christmasDateStr)
            ->modify('last Sunday')->sub(new \DateInterval('P' . ( 2 * 7 ) . 'D')));
        $this->PropriumDeTempore['Advent3']->setDate(DateTime::fromFormat($christmasDateStr)
            ->modify('last Sunday')->sub(new \DateInterval('P7D')));
        $this->PropriumDeTempore['Advent4']->setDate(DateTime::fromFormat($christmasDateStr)
            ->modify('last Sunday'));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Advent1');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Advent2');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Advent3');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Advent4');

        //We calculate Sundays of Lent, Palm Sunday, Sundays of Easter, Trinity Sunday and Corpus Christi based on Easter
        $this->PropriumDeTempore['Lent1']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P' . ( 6 * 7 ) . 'D')));
        $this->PropriumDeTempore['Lent2']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P' . ( 5 * 7 ) . 'D')));
        $this->PropriumDeTempore['Lent3']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P' . ( 4 * 7 ) . 'D')));
        $this->PropriumDeTempore['Lent4']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P' . ( 3 * 7 ) . 'D')));
        $this->PropriumDeTempore['Lent5']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P' . ( 2 * 7 ) . 'D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Lent1');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Lent2');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Lent3');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Lent4');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Lent5');
        $this->PropriumDeTempore['PalmSun']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P7D')));
        $this->PropriumDeTempore['Easter2']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P7D')));
        $this->PropriumDeTempore['Easter3']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 2 ) . 'D')));
        $this->PropriumDeTempore['Easter4']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 3 ) . 'D')));
        $this->PropriumDeTempore['Easter5']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 4 ) . 'D')));
        $this->PropriumDeTempore['Easter6']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 5 ) . 'D')));
        $this->PropriumDeTempore['Trinity']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 8 ) . 'D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('PalmSun');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter2');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter3');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter4');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter5');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter6');
        $this->createPropriumDeTemporeLiturgicalEventByKey('Trinity');
        if ($this->CalendarParams->CorpusChristi === CorpusChristi::THURSDAY) {
            $this->PropriumDeTempore['CorpusChristi']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
                ->add(new \DateInterval('P' . ( 7 * 8 + 4 ) . 'D')));
            $this->createPropriumDeTemporeLiturgicalEventByKey('CorpusChristi');
            //Seeing the Sunday is not taken by Corpus Christi, it should be later taken by a Sunday of Ordinary Time
            // (they are calculated back to Pentecost)
        } elseif ($this->CalendarParams->CorpusChristi === CorpusChristi::SUNDAY) {
            $this->PropriumDeTempore['CorpusChristi']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
                ->add(new \DateInterval('P' . ( 7 * 9 ) . 'D')));
            $this->createPropriumDeTemporeLiturgicalEventByKey('CorpusChristi');
        }

        if ($this->CalendarParams->Year >= 2000) {
            // Modify name of the second Sunday of Easter to include Divine Mercy Sunday
            $easter2Name = $this->PropriumDeTempore['Easter2']->name;
            if (LitLocale::$PRIMARY_LANGUAGE === LitLocale::LATIN_PRIMARY_LANGUAGE) {
                $divineMercySunday = $easter2Name . ' vel Dominica Divinæ Misericordiæ';
            } else {
                /**translators: context alternate name for a liturgical event, e.g. Second Sunday of Easter `or` Divine Mercy Sunday*/
                $or                = _('or');
                $divineMercySunday = $easter2Name
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
        $this->PropriumDeTempore['AshWednesday']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P46D')));
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
        $this->PropriumDeTempore['MonHolyWeek']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P6D')));
        $this->PropriumDeTempore['TueHolyWeek']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P5D')));
        $this->PropriumDeTempore['WedHolyWeek']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P4D')));
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
        $this->PropriumDeTempore['MonOctaveEaster']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P1D')));
        $this->PropriumDeTempore['TueOctaveEaster']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P2D')));
        $this->PropriumDeTempore['WedOctaveEaster']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P3D')));
        $this->PropriumDeTempore['ThuOctaveEaster']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P4D')));
        $this->PropriumDeTempore['FriOctaveEaster']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P5D')));
        $this->PropriumDeTempore['SatOctaveEaster']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P6D')));
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
     * **General Norms for the Liturgical Year and the Calendar**
     *
     * I.
     * 1. Easter Triduum of the Lord's Passion and Resurrection
     * 2. Christmas, Epiphany, Ascension, and Pentecost
     * 3. ***Solemnities of the Lord, of the Blessed Virgin Mary, and of saints listed in the General Calendar***
     *
     * @return void
     */
    private function calculateMobileSolemnitiesOfTheLord(): void
    {
        $this->PropriumDeTempore['SacredHeart']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 9 + 5 ) . 'D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('SacredHeart');

        //Christ the King is calculated backwards from the first sunday of advent
        $this->PropriumDeTempore['ChristKing']->setDate(DateTime::fromFormat('25-12-' . $this->CalendarParams->Year)->modify('last Sunday')->sub(new \DateInterval('P' . ( 4 * 7 ) . 'D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('ChristKing');
    }

    /**
     * Calculates the dates for fixed date Solemnities and creates the corresponding LiturgicalEvents in the calendar.
     *
     * Solemnities are celebrations of the highest rank in the Liturgical Calendar. They are days of special importance
     * in the Roman Rite, and are usually observed with a Vigil, proper readings, and a special Mass formulary.
     * Fixed date Solemnities, as the name implies, are Solemnities that fall on the same date every year.
     *
     * **General Norms for the Liturgical Year and the Calendar**
     *
     * I.
     * 1. Easter Triduum of the Lord's Passion and Resurrection
     * 2. Christmas, Epiphany, Ascension, and Pentecost
     * 3. ***Solemnities of the Lord, of the Blessed Virgin Mary, and of saints listed in the General Calendar***
     *
     * @return void
     */
    private function calculateFixedSolemnities(): void
    {
        // Even though Mary Mother of God is a fixed date solemnity,
        // it is however found in the Proprium de Tempore and not in the Proprium de Sanctis
        $this->PropriumDeTempore['MotherGod']->setDate(DateTime::fromFormat('1-1-' . $this->CalendarParams->Year));
        $this->createPropriumDeTemporeLiturgicalEventByKey('MotherGod');

        $propriumDeSanctisSolemnities = $this->missalsMap[RomanMissal::EDITIO_TYPICA_1970]->filterByGrade(LitGrade::SOLEMNITY);

        foreach ($propriumDeSanctisSolemnities as $propriumDeSanctisEvent) {
            $currentLitEventDate = DateTime::fromFormat($propriumDeSanctisEvent->day . '-' . $propriumDeSanctisEvent->month . '-' . $this->CalendarParams->Year);
            $propriumDeSanctisEvent->setDate($currentLitEventDate);
            $tempLiturgicalEvent = LiturgicalEvent::fromObject($propriumDeSanctisEvent);

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

            if ($this->Cal->inSolemnities($currentLitEventDate)) {
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
                    $propriumDeSanctisEvent->event_key === 'StJoseph'
                    && $currentLitEventDate >= $this->Cal->getLiturgicalEvent('PalmSun')->date
                    && $currentLitEventDate <= $this->Cal->getLiturgicalEvent('Easter')->date
                ) {
                    $tempLiturgicalEvent->date = Utilities::calcGregEaster($this->CalendarParams->Year)->sub(new \DateInterval('P8D'));
                    $this->Messages[]          = sprintf(
                        /**translators: 1: LiturgicalEvent name, 2: LiturgicalEvent date, 3: Requested calendar year, 4: Description of the reason for the transferral (ex. the Saturday preceding Palm Sunday), 5: actual date for the transferral, 6: Decree of the Congregation for Divine Worship  */
                        _('The Solemnity \'%1$s\' falls on %2$s in the year %3$d, the celebration has been transferred to %4$s (%5$s) as per the %6$s.'),
                        $tempLiturgicalEvent->name,
                        $this->Cal->solemnityFromDate($currentLitEventDate)->name,
                        $this->CalendarParams->Year,
                        _('the Saturday preceding Palm Sunday'),
                        $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                            ? ( $tempLiturgicalEvent->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[(int) $tempLiturgicalEvent->date->format('n')] )
                            : ( $locale === 'en'
                                ? $tempLiturgicalEvent->date->format('F jS')
                                : $this->dayAndMonth->format($tempLiturgicalEvent->date->format('U'))
                            ),
                        '<a href="https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/2000/notitiae-42-(2006)/Notitiae-475-476-2006.pdf" target="_blank">'
                            . _('Decree of the Congregation for Divine Worship')
                        . '</a>'
                    );
                } elseif ($propriumDeSanctisEvent->event_key === 'Annunciation' && $currentLitEventDate >= $this->Cal->getLiturgicalEvent('PalmSun')->date && $currentLitEventDate <= $this->Cal->getLiturgicalEvent('Easter2')->date) {
                    //if the Annunciation falls during Holy Week or within the Octave of Easter, it is transferred to the Monday after the Second Sunday of Easter.
                    $tempLiturgicalEvent->date = Utilities::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P8D'));
                    $this->Messages[]          = sprintf(
                        /**translators: 1: LiturgicalEvent name, 2: LiturgicalEvent date, 3: Requested calendar year, 4: Explicatory string for the transferral (ex. the Saturday preceding Palm Sunday), 5: actual date for the transferral, 6: Decree of the Congregation for Divine Worship */
                        _('The Solemnity \'%1$s\' falls on %2$s in the year %3$d, the celebration has been transferred to %4$s (%5$s) as per the %6$s.'),
                        $tempLiturgicalEvent->name,
                        $this->Cal->solemnityFromDate($currentLitEventDate)->name,
                        $this->CalendarParams->Year,
                        _('the Monday following the Second Sunday of Easter'),
                        $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                            ? ( $tempLiturgicalEvent->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[(int) $tempLiturgicalEvent->date->format('n')] )
                            : ( $locale === 'en'
                                ? $tempLiturgicalEvent->date->format('F jS')
                                : $this->dayAndMonth->format($tempLiturgicalEvent->date->format('U'))
                            ),
                        '<a href="https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/2000/notitiae-42-(2006)/Notitiae-475-476-2006.pdf" target="_blank">'
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
                    in_array($propriumDeSanctisEvent->event_key, [ 'Annunciation', 'StJoseph', 'ImmaculateConception' ])
                    && $this->Cal->isSundayAdventLentEaster($currentLitEventDate)
                ) {
                    // Take into account the exemption made for Italy since 2024, when the Immaculate Conception coincides with the Second Sunday of Advent
                    // TODO: Should this be handled in an automated fashion?
                    if ($this->CalendarParams->Year >= 2024 && $propriumDeSanctisEvent->event_key === 'ImmaculateConception' && $this->CalendarParams->NationalCalendar === 'IT') {
                        // We actually suppress the Second Sunday of Advent in this case
                        $this->Cal->removeLiturgicalEvent('Advent2');
                        $this->Messages[] = sprintf(
                            'La solennità dell\'Immacolata Concezione\' coincide con la Seconda Domenica dell\'Avvento nell\'anno %1$d, e per <a href="%2$s" target="_blank">decreto della Congregazione per il Culto Divino</a> del 6 ottobre 2023 viene fatta deroga alla regola del trasferimento al lunedì seguente per tutte le diocesi dell\'Italia, per le quali verrà celebrata comunque il giorno 8 dicembre.',
                            $this->CalendarParams->Year,
                            'https://liturgico.chiesacattolica.it/solennita-dellimmacolata-concezione-2024/'
                        );
                    } else {
                        $tempLiturgicalEvent->date = clone( $currentLitEventDate );
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
                            $this->Cal->solemnityFromDate($currentLitEventDate)->name,
                            $this->CalendarParams->Year,
                            _('the following Monday'),
                            $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                                ? ( $tempLiturgicalEvent->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[(int) $tempLiturgicalEvent->date->format('n')] )
                                : ( $locale === 'en'
                                        ? $tempLiturgicalEvent->date->format('F jS')
                                        : $this->dayAndMonth->format($tempLiturgicalEvent->date->format('U'))
                            ),
                            '<a href="https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/1990/notitiae-26-(1990)/Notitiae-284-285-1990.pdf" target="_blank">' . _('Decree of the Congregation for Divine Worship') . '</a>'
                        );
                    }
                } else {
                    //In all other cases, let's make a note of what's happening and ask the Congegation for Divine Worship
                    $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                        /**translators: 1: LiturgicalEvent name, 2: Coinciding LiturgicalEvent name, 3: Requested calendar year */
                        _('The Solemnity \'%1$s\' coincides with the Solemnity \'%2$s\' in the year %3$d. We should ask the Congregation for Divine Worship what to do about this!'),
                        $propriumDeSanctisEvent->name,
                        $this->Cal->solemnityFromDate($currentLitEventDate)->name,
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
                if ($propriumDeSanctisEvent->event_key === 'NativityJohnBaptist' && $this->Cal->solemnityKeyFromDate($currentLitEventDate) === 'SacredHeart') {
                    $NativityJohnBaptistNewDate = clone( $this->Cal->getLiturgicalEvent('SacredHeart')->date );
                    $SacredHeart                = $this->Cal->solemnityFromDate($currentLitEventDate);
                    if (!$this->Cal->inSolemnities($NativityJohnBaptistNewDate->sub(new \DateInterval('P1D')))) {
                        $tempLiturgicalEvent->date->sub(new \DateInterval('P1D'));
                        $decree = '<a href="'
                            . 'https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/2020/notitiae-56-(2020)/Notitiae-597-NS-005-2020.pdf'
                            . '" target="_blank">'
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
            $this->Cal->addLiturgicalEvent($propriumDeSanctisEvent->event_key, $tempLiturgicalEvent);
        }

        //let's add a displayGrade property for AllSouls so applications don't have to worry about fixing it
        $this->Cal->setProperty('AllSouls', 'grade_display', ''); //LitGrade::i18n(LitGrade::COMMEMORATION, $this->CalendarParams->Locale, false)
    }


    /**
     * Calculates the dates for the Baptism of the Lord, Holy Family, and the other feasts of the Lord
     * (Presentation, Transfiguration, Triumph of the Holy Cross and Dedication of the Lateran Basilica)
     * and creates the corresponding LiturgicalEvents in the calendar.
     *
     * Also creates the LiturgicalEvent for Christ the Eternal High Priest for those areas that have adopted this liturgical event
     *
     * **General Norms for the Liturgical Year and the Calendar**
     *
     * I.
     * 1. Easter Triduum of the Lord's Passion and Resurrection
     * 2. Christmas, Epiphany, Ascension, and Pentecost
     * 3. Solemnities of the Lord, of the Blessed Virgin Mary, and of saints listed in the General Calendar
     * II.
     * 5. ***Feasts of the Lord***
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
            $dateJan7 = DateTime::fromFormat('7-1-' . $this->CalendarParams->Year);
            $dateJan8 = DateTime::fromFormat('8-1-' . $this->CalendarParams->Year);
            if (self::dateIsSunday($dateJan7)) {
                $this->BaptismLordFmt = '7-1-' . $this->CalendarParams->Year;
                $this->BaptismLordMod = 'next Monday';
            } elseif (self::dateIsSunday($dateJan8)) {
                $this->BaptismLordFmt = '8-1-' . $this->CalendarParams->Year;
                $this->BaptismLordMod = 'next Monday';
            }
        }
        $this->PropriumDeTempore['BaptismLord']->setDate(DateTime::fromFormat($this->BaptismLordFmt)
            ->modify($this->BaptismLordMod));

        $this->createPropriumDeTemporeLiturgicalEventByKey('BaptismLord');

        // the other feasts of the Lord ( Presentation, Transfiguration and Triumph of the Holy Cross) are fixed date feasts
        // and are found in the Proprium de Sanctis
        // :DedicationLateran is a specific case, we consider it a Feast of the Lord even though it is displayed as FEAST
        //  source: in the Missale Romanum, in the section Index Alphabeticus Celebrationum,
        //    under Iesus Christus D. N., the Dedicatio Basilicae Lateranensis is also listed
        $propriumDeSanctisEvents = $this->missalsMap[RomanMissal::EDITIO_TYPICA_1970]->filterByGrade(LitGrade::FEAST_LORD);

        foreach ($propriumDeSanctisEvents as $propriumDeSanctisEvent) {
            $propriumDeSanctisEvent->setDate(DateTime::fromFormat($propriumDeSanctisEvent->day . '-' . $propriumDeSanctisEvent->month . '-' . $this->CalendarParams->Year));
            // $propriumDeSanctisEvent->type = LitEventType::FIXED;
            $litEvent = LiturgicalEvent::fromObject($propriumDeSanctisEvent);
            if ($propriumDeSanctisEvent->event_key === 'DedicationLateran') {
                $litEvent->grade_display = LitGrade::i18n(LitGrade::FEAST, $this->CalendarParams->Locale, false);
                $litEvent->setGradeAbbreviation(LitGrade::i18n(LitGrade::FEAST, $this->CalendarParams->Locale, false, true));
            }
            $this->Cal->addLiturgicalEvent($propriumDeSanctisEvent->event_key, $litEvent);
        }

        //Holy Family is celebrated the Sunday after Christmas, unless Christmas falls on a Sunday, in which case it is celebrated Dec. 30
        $locale = LitLocale::$PRIMARY_LANGUAGE;
        if (self::dateIsSunday($this->Cal->getLiturgicalEvent('Christmas')->date)) {
            $this->PropriumDeTempore['HolyFamily']->setDate(DateTime::fromFormat('30-12-' . $this->CalendarParams->Year));

            $HolyFamily       = $this->createPropriumDeTemporeLiturgicalEventByKey('HolyFamily');
            $this->Messages[] = sprintf(
                /**translators: 1: LiturgicalEvent name (Christmas), 2: Requested calendar year, 3: LiturgicalEvent name (Holy Family), 4: New date for Holy Family */
                _('\'%1$s\' falls on a Sunday in the year %2$d, therefore the Feast \'%3$s\' is celebrated on %4$s rather than on the Sunday after Christmas.'),
                $this->Cal->getLiturgicalEvent('Christmas')->name,
                $this->CalendarParams->Year,
                $HolyFamily->name,
                $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? ( $HolyFamily->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[(int) $HolyFamily->date->format('n')] )
                    : ( $locale === 'en'
                        ? $HolyFamily->date->format('F jS')
                        : $this->dayAndMonth->format($HolyFamily->date->format('U'))
                    )
            );
        } else {
            $this->PropriumDeTempore['HolyFamily']->setDate(DateTime::fromFormat('25-12-' . $this->CalendarParams->Year)->modify('next Sunday'));
            $this->createPropriumDeTemporeLiturgicalEventByKey('HolyFamily');
        }

        // In 2012, Pope Benedict XVI gave faculty to the Episcopal Conferences
        //  to insert the Feast of Our Lord Jesus Christ, the Eternal High Priest
        //  in their own liturgical calendars on the Thursday after Pentecost,
        //  see https://notitiae.ipsissima-verba.org/pdf/notitiae-2012-335-368.pdf
        if ($this->CalendarParams->Year >= 2012 && true === $this->CalendarParams->EternalHighPriest) {
            $EternalHighPriestDate = clone( $this->Cal->getLiturgicalEvent('Pentecost')->date );
            $EternalHighPriestDate->modify('next Thursday');
            $EternalHighPriestName = ( LitLocale::$PRIMARY_LANGUAGE === LitLocale::LATIN_PRIMARY_LANGUAGE )
                ? 'Domini Nostri Iesu Christi Summi et Aeterni Sacerdotis'
                /**translators: You can ignore this translation if the Feast has not been inserted by the Episcopal Conference */
                : _('Our Lord Jesus Christ, The Eternal High Priest');
            $litEvent = new LiturgicalEvent(
                $EternalHighPriestName,
                $EternalHighPriestDate,
                LitColor::WHITE,
                LitEventType::FIXED,
                LitGrade::FEAST_LORD,
                LitCommon::PROPRIO
            );
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
        // If a fixed date Solemnity occurs on a Sunday of Ordinary Time or on a Sunday of Christmas,
        //  the Solemnity is celebrated in place of the Sunday. ( e.g., Birth of John the Baptist, 1990 )
        // If a fixed date Feast of the Lord occurs on a Sunday in Ordinary Time, the feast is celebrated in place of the Sunday

        // Sundays of Ordinary Time in the first part of the year are numbered from after the Baptism of the Lord
        //  ( which begins the 1st week of Ordinary Time ) until Ash Wednesday
        $firstOrdinaryDate = DateTime::fromFormat($this->BaptismLordFmt)->modify($this->BaptismLordMod);
        // Basically we take Ash Wednesday as the limit...
        // Here is ( Ash Wednesday - 7 ) since one more cycle will complete...
        $firstOrdinaryLimit = Utilities::calcGregEaster($this->CalendarParams->Year)->sub(new \DateInterval('P53D'));
        $ordSun             = 1;
        while ($firstOrdinaryDate >= $this->Cal->getLiturgicalEvent('BaptismLord')->date && $firstOrdinaryDate < $firstOrdinaryLimit) {
            $firstOrdinaryDate = DateTime::fromFormat($this->BaptismLordFmt)->modify($this->BaptismLordMod)->modify('next Sunday')->add(new \DateInterval('P' . ( ( $ordSun - 1 ) * 7 ) . 'D'));
            $ordSun++;
            if (!$this->Cal->inSolemnities($firstOrdinaryDate)) {
                $this->Cal->addLiturgicalEvent('OrdSunday' . $ordSun, new LiturgicalEvent(
                    $this->PropriumDeTempore['OrdSunday' . $ordSun]->name,
                    $firstOrdinaryDate,
                    LitColor::GREEN,
                    LitEventType::MOBILE,
                    LitGrade::FEAST_LORD,
                    LitCommon::NONE,
                    ''
                ));
            } else {
                $this->Messages[] = sprintf(
                    /**translators: 1: LiturgicalEvent name, 2: Superseding LiturgicalEvent grade, 3: Superseding LiturgicalEvent name, 4: Requested calendar year */
                    _('\'%1$s\' is superseded by the %2$s \'%3$s\' in the year %4$d.'),
                    $this->PropriumDeTempore['OrdSunday' . $ordSun]->name,
                    $this->Cal->solemnityFromDate($firstOrdinaryDate)->grade->value > LitGrade::SOLEMNITY->value
                        ? '<i>' . LitGrade::i18n($this->Cal->solemnityFromDate($firstOrdinaryDate)->grade, $this->CalendarParams->Locale, false) . '</i>'
                        : LitGrade::i18n($this->Cal->solemnityFromDate($firstOrdinaryDate)->grade, $this->CalendarParams->Locale, false),
                    $this->Cal->solemnityFromDate($firstOrdinaryDate)->name,
                    $this->CalendarParams->Year
                );
            }
        }

        //Sundays of Ordinary Time in the Latter part of the year are numbered backwards from Christ the King ( 34th ) to Pentecost
        $lastOrdinary = DateTime::fromFormat('25-12-' . $this->CalendarParams->Year)->modify('last Sunday')->sub(new \DateInterval('P' . ( 4 * 7 ) . 'D'));
        //We take Trinity Sunday as the limit...
        //Here is ( Trinity Sunday + 7 ) since one more cycle will complete...
        $lastOrdinaryLowerLimit = Utilities::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P' . ( 7 * 9 ) . 'D'));
        $ordSun                 = 34;
        $ordSunCycle            = 4;

        while ($lastOrdinary <= $this->Cal->getLiturgicalEvent('ChristKing')->date && $lastOrdinary > $lastOrdinaryLowerLimit) {
            $lastOrdinary = DateTime::fromFormat('25-12-' . $this->CalendarParams->Year)->modify('last Sunday')->sub(new \DateInterval('P' . ( ++$ordSunCycle * 7 ) . 'D'));
            $ordSun--;
            if (!$this->Cal->inSolemnities($lastOrdinary)) {
                $this->Cal->addLiturgicalEvent('OrdSunday' . $ordSun, new LiturgicalEvent(
                    $this->PropriumDeTempore['OrdSunday' . $ordSun]->name,
                    $lastOrdinary,
                    LitColor::GREEN,
                    LitEventType::MOBILE,
                    LitGrade::FEAST_LORD,
                    LitCommon::NONE,
                    ''
                ));
            } else {
                $this->Messages[] = sprintf(
                    /**translators: 1: LiturgicalEvent name, 2: Superseding LiturgicalEvent grade, 3: Superseding LiturgicalEvent name, 4: Requested calendar year */
                    _('\'%1$s\' is superseded by the %2$s \'%3$s\' in the year %4$d.'),
                    $this->PropriumDeTempore['OrdSunday' . $ordSun]->name,
                    $this->Cal->solemnityFromDate($lastOrdinary)->grade->value > LitGrade::SOLEMNITY->value
                        ? '<i>' . LitGrade::i18n($this->Cal->solemnityFromDate($lastOrdinary)->grade, $this->CalendarParams->Locale, false) . '</i>'
                        : LitGrade::i18n($this->Cal->solemnityFromDate($lastOrdinary)->grade, $this->CalendarParams->Locale, false),
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
        $propriumDeSanctisEvents = $this->missalsMap[RomanMissal::EDITIO_TYPICA_1970]->filterByGrade(LitGrade::FEAST);

        foreach ($propriumDeSanctisEvents as $propriumDeSanctisEvent) {
            $propriumDeSanctisEvent->setDate(DateTime::fromFormat($propriumDeSanctisEvent->day . '-' . $propriumDeSanctisEvent->month . '-' . $this->CalendarParams->Year));
            // If a Feast ( not of the Lord ) occurs on a Sunday in Ordinary Time, the Sunday is celebrated.  ( e.g., St. Luke, 1992 )
            // obviously solemnities also have precedence
            // The Dedication of the Lateran Basilica is an exceptional case, where it is treated as a Feast of the Lord, even if it is displayed as a Feast
            //  source: in the Missale Romanum, in the section Index Alphabeticus Celebrationum,
            //    under Iesus Christus D. N., the Dedicatio Basilicae Lateranensis is also listed
            //  so we give it a grade of 5 === FEAST_LORD but a displayGrade of FEAST
            //  It should therefore have already been handled in $this->calculateFeastsOfTheLord(), see :DedicationLateran
            if (self::dateIsNotSunday($propriumDeSanctisEvent->date) && !$this->Cal->inSolemnitiesOrFeasts($propriumDeSanctisEvent->date)) {
                //$propriumDeSanctisEvent->type = LitEventType::FIXED;
                $litEvent = LiturgicalEvent::fromObject($propriumDeSanctisEvent);
                $this->Cal->addLiturgicalEvent($propriumDeSanctisEvent->event_key, $litEvent);
            } else {
                $this->handleCoincidence($propriumDeSanctisEvent, RomanMissal::EDITIO_TYPICA_1970);
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
        // We start calculating the weekdays of Advent from the First Sunday of Advent
        $DoMAdvent1       = $this->Cal->getLiturgicalEvent('Advent1')->date->format('j'); // j = Day of the Month (DoM) on which the first Sunday of Advent falls
        $MonthAdvent1     = $this->Cal->getLiturgicalEvent('Advent1')->date->format('n'); // n = Month in which the first Sunday of Advent falls
        $weekdayAdvent    = DateTime::fromFormat($DoMAdvent1 . '-' . $MonthAdvent1 . '-' . $this->CalendarParams->Year);
        $weekdayAdventCnt = 1;

        while ($weekdayAdvent >= $this->Cal->getLiturgicalEvent('Advent1')->date && $weekdayAdvent < $this->Cal->getLiturgicalEvent('Christmas')->date) {
            // We start calculating from the First Sunday of Advent, but incrementally adding a day
            $weekdayAdvent = DateTime::fromFormat($DoMAdvent1 . '-' . $MonthAdvent1 . '-' . $this->CalendarParams->Year)->add(new \DateInterval('P' . $weekdayAdventCnt . 'D'));

            // We just double check to make sure we are not going beyond the bounds of Advent
            // (we can check against Christmas inclusive on Dec. 25th because being a Solemnity a weekday for Dec. 25th will not be calculated)
            if ((int) $weekdayAdvent->format('n') === 12 && (int) $weekdayAdvent->format('j') > 25) {
                throw new \Exception('the weekdays of Advent must be calculated from 17 Dec. to 24 Dec., you are trying to calculate the ' . $weekdayAdventCnt . 'th weekday of Advent which is ' . $weekdayAdvent->format('Y-m-d'));
            }

            //if we're not dealing with a sunday or a solemnity or an obligatory memorial, then create the weekday
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($weekdayAdvent) && self::dateIsNotSunday($weekdayAdvent)) {
                $upper          = (int) $weekdayAdvent->format('z');
                $diff           = $upper - (int) $this->Cal->getLiturgicalEvent('Advent1')->date->format('z'); //day count between current day and First Sunday of Advent
                $currentAdvWeek = ( ( $diff - $diff % 7 ) / 7 ) + 1; //week count between current day and First Sunday of Advent
                $dayOfTheWeek   = LitLocale::$PRIMARY_LANGUAGE === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? LatinUtils::LATIN_DAYOFTHEWEEK[$weekdayAdvent->format('w')]
                    : Utilities::ucfirst($this->dayOfTheWeek->format($weekdayAdvent->format('U')));
                $dayOfTheWeekEn = $this->dayOfTheWeekEnglish->format($weekdayAdvent->format('U'));
                $ordinal        = ucfirst(
                    Utilities::getOrdinal($currentAdvWeek, $this->CalendarParams->Locale, $this->formatterFem, LatinUtils::LATIN_ORDINAL_FEM_GEN)
                );
                $nthStr         = LitLocale::$PRIMARY_LANGUAGE === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? sprintf('Hebdomadæ %s Adventus', $ordinal)
                    : sprintf(
                        /**translators: %s is an ordinal number (first, second...) */
                        _('of the %s Week of Advent'),
                        $ordinal
                    );
                $name                   = $dayOfTheWeek . ' ' . $nthStr;
                $dayOfTheMonth          = $weekdayAdvent->format('j');
                $event_key              = ( (int) $weekdayAdvent->format('n') === 12 && (int) $dayOfTheMonth >= 17 )
                                            ? 'AdventWeekdayDec' . $dayOfTheMonth
                                            : 'AdventWeekday' . $currentAdvWeek . $dayOfTheWeekEn;
                $litEvent               = new LiturgicalEvent(
                    $name,
                    $weekdayAdvent,
                    LitColor::PURPLE,
                    LitEventType::MOBILE,
                    LitGrade::WEEKDAY,
                    LitCommon::NONE
                );
                $litEvent->psalter_week = $this->Cal::psalterWeek($currentAdvWeek);
                if ($event_key === 'AdventWeekday3Saturday') {
                    throw new \Exception(
                        'You are trying to calculate the 3rd Saturday of Advent which is ' . $weekdayAdvent->format('Y-m-d')
                        . ', however this cannot exist, because it should on or beyond Dec. 17th which cannot happen.'
                        . ' Month = ' . $dayOfTheMonth . ', Day of the week = ' . $weekdayAdvent->format('j')
                    );
                }
                $this->Cal->addLiturgicalEvent($event_key, $litEvent);
            }

            ++$weekdayAdventCnt;
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
        $weekdayChristmas    = DateTime::fromFormat('25-12-' . $this->CalendarParams->Year);
        $weekdayChristmasCnt = 1;
        while (
            $weekdayChristmas >= $this->Cal->getLiturgicalEvent('Christmas')->date
            && $weekdayChristmas < DateTime::fromFormat('31-12-' . $this->CalendarParams->Year)
        ) {
            $weekdayChristmas = DateTime::fromFormat('25-12-' . $this->CalendarParams->Year)->add(new \DateInterval('P' . $weekdayChristmasCnt . 'D'));
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($weekdayChristmas) && self::dateIsNotSunday($weekdayChristmas)) {
                $ordinal = ucfirst(Utilities::getOrdinal(( $weekdayChristmasCnt + 1 ), $this->CalendarParams->Locale, $this->formatter, LatinUtils::LATIN_ORDINAL));
                $name    = LitLocale::$PRIMARY_LANGUAGE === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? sprintf('Dies %s Octavæ Nativitatis', $ordinal)
                    : sprintf(
                        /**translators: %s is an ordinal number (first, second...) */
                        _('%s Day of the Octave of Christmas'),
                        $ordinal
                    );
                $dayOfTheMonth = $weekdayChristmas->format('j');
                $event_key     = 'ChristmasWeekdayDec' . $dayOfTheMonth;
                $litEvent      = new LiturgicalEvent(
                    $name,
                    $weekdayChristmas,
                    LitColor::WHITE,
                    LitEventType::MOBILE,
                    LitGrade::WEEKDAY,
                    LitCommon::NONE,
                    null
                );
                $this->Cal->addLiturgicalEvent($event_key, $litEvent);
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
        $weekdayLent       = DateTime::fromFormat($DoMAshWednesday . '-' . $MonthAshWednesday . '-' . $this->CalendarParams->Year);
        $weekdayLentCnt    = 1;
        while ($weekdayLent >= $this->Cal->getLiturgicalEvent('AshWednesday')->date && $weekdayLent < $this->Cal->getLiturgicalEvent('PalmSun')->date) {
            $weekdayLent = DateTime::fromFormat($DoMAshWednesday . '-' . $MonthAshWednesday . '-' . $this->CalendarParams->Year)->add(new \DateInterval('P' . $weekdayLentCnt . 'D'));
            if (!$this->Cal->inSolemnities($weekdayLent) && self::dateIsNotSunday($weekdayLent)) {
                if ($weekdayLent > $this->Cal->getLiturgicalEvent('Lent1')->date) {
                    $upper           =  (int) $weekdayLent->format('z');
                    $diff            = $upper -  (int) $this->Cal->getLiturgicalEvent('Lent1')->date->format('z'); //day count between current day and First Sunday of Lent
                    $currentLentWeek = ( ( $diff - $diff % 7 ) / 7 ) + 1; //week count between current day and First Sunday of Lent
                    $ordinal         = ucfirst(Utilities::getOrdinal($currentLentWeek, $this->CalendarParams->Locale, $this->formatterFem, LatinUtils::LATIN_ORDINAL_FEM_GEN));
                    $locale          = LitLocale::$PRIMARY_LANGUAGE;
                    $dayOfTheWeek    = $locale == LitLocale::LATIN_PRIMARY_LANGUAGE
                        ? LatinUtils::LATIN_DAYOFTHEWEEK[$weekdayLent->format('w')]
                        : Utilities::ucfirst($this->dayOfTheWeek->format($weekdayLent->format('U')));
                    $nthStr          = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                        ? sprintf('Hebdomadæ %s Quadragesimæ', $ordinal)
                        : sprintf(
                            /**translators: %s is an ordinal number (first, second...) */
                            _('of the %s Week of Lent'),
                            $ordinal
                        );
                    $name                   = $dayOfTheWeek . ' ' .  $nthStr;
                    $dayOfTheWeekEn         = $this->dayOfTheWeekEnglish->format($weekdayLent->format('U'));
                    $event_key              = 'LentWeekday' . $currentLentWeek . $dayOfTheWeekEn;
                    $litEvent               = new LiturgicalEvent(
                        $name,
                        $weekdayLent,
                        LitColor::PURPLE,
                        LitEventType::MOBILE,
                        LitGrade::WEEKDAY,
                        LitCommon::NONE,
                        null
                    );
                    $litEvent->psalter_week = $this->Cal::psalterWeek($currentLentWeek);
                } else {
                    $locale         = LitLocale::$PRIMARY_LANGUAGE;
                    $dayOfTheWeek   = $locale == LitLocale::LATIN_PRIMARY_LANGUAGE
                        ? LatinUtils::LATIN_DAYOFTHEWEEK[$weekdayLent->format('w')]
                        : Utilities::ucfirst($this->dayOfTheWeek->format($weekdayLent->format('U')));
                    $postStr        = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE ? 'post Feria IV Cinerum' : _('after Ash Wednesday');
                    $name           = $dayOfTheWeek . ' ' . $postStr;
                    $dayOfTheWeekEn = $this->dayOfTheWeekEnglish->format($weekdayLent->format('U'));
                    $event_key      = $dayOfTheWeekEn . 'AfterAshWednesday';
                    $litEvent       = new LiturgicalEvent(
                        $name,
                        $weekdayLent,
                        LitColor::PURPLE,
                        LitEventType::MOBILE,
                        LitGrade::WEEKDAY,
                        LitCommon::NONE,
                        null
                    );
                }
                $this->Cal->addLiturgicalEvent($event_key, $litEvent);
            }
            $weekdayLentCnt++;
        }
    }

    /**
     * Adds a message to the API response indicating that a given memorial has been added to the calendar
     *
     * @param PropriumDeSanctisEvent $propriumDeSanctisEvent A JSON object representing data for the liturgical event in question
     */
    private function addMissalMemorialMessage(PropriumDeSanctisEvent $propriumDeSanctisEvent): void
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
            LitGrade::i18n($propriumDeSanctisEvent->grade, $this->CalendarParams->Locale, false),
            $propriumDeSanctisEvent->name,
            $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                ? ( $propriumDeSanctisEvent->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[(int) $propriumDeSanctisEvent->date->format('n')] )
                : ( $locale === 'en'
                    ? $propriumDeSanctisEvent->date->format('F jS')
                    : $this->dayAndMonth->format($propriumDeSanctisEvent->date->format('U'))
                ),
            $propriumDeSanctisEvent->since_year,
            $propriumDeSanctisEvent->decree,
            $this->CalendarParams->Year
        );
    }

    /**
     * Calculates memorials to add to the calendar, following the rules of the Roman Missal.
     *
     * @param LitGrade $grade the grade of the liturgical event (e.g. 'memorial', 'feast', etc.)
     * @param string $missal the edition of the Roman Missal
     */
    private function calculateMemorials(LitGrade $grade = LitGrade::MEMORIAL, string $missal = RomanMissal::EDITIO_TYPICA_1970): void
    {
        if ($missal === RomanMissal::EDITIO_TYPICA_1970 && $grade === LitGrade::MEMORIAL) {
            $this->createImmaculateHeart();
        }

        $propriumDeSanctisEvents = $this->missalsMap[$missal]->filterByGrade($grade);

        foreach ($propriumDeSanctisEvents as $propriumDeSanctisEvent) {
            //If it doesn't occur on a Sunday or a Solemnity or a Feast of the Lord or a Feast, then go ahead and create the memorial
            $propriumDeSanctisEvent->setDate(DateTime::fromFormat($propriumDeSanctisEvent->day . '-' . $propriumDeSanctisEvent->month . '-' . $this->CalendarParams->Year));
            if (self::dateIsNotSunday($propriumDeSanctisEvent->date) && $this->Cal->notInSolemnitiesFeastsOrMemorials($propriumDeSanctisEvent->date)) {
                //$propriumDeSanctisEvent->type          = LitEventType::FIXED;
                $newLiturgicalEvent = LiturgicalEvent::fromObject($propriumDeSanctisEvent);
                //Calendar::debugWrite( "adding new memorial '$propriumDeSanctisEvent->name', common vartype = " . gettype( $propriumDeSanctisEvent->common ) . ", common = " . implode(', ', $propriumDeSanctisEvent->common) );
                //Calendar::debugWrite( ">>> added new memorial '$newLiturgicalEvent->name', common vartype = " . gettype( $newLiturgicalEvent->common ) . ", common = " . implode(', ', $newLiturgicalEvent->common) );

                $this->Cal->addLiturgicalEvent($propriumDeSanctisEvent->event_key, $newLiturgicalEvent);

                $this->reduceMemorialsInAdventLentToCommemoration($propriumDeSanctisEvent);

                if ($missal === RomanMissal::EDITIO_TYPICA_TERTIA_2002) {
                    $propriumDeSanctisEvent->setSinceYear(2002);
                    $propriumDeSanctisEvent->setDecree(
                        '<a href="https://press.vatican.va/content/salastampa/it/bollettino/pubblico/2002/03/22/0150/00449.html" target="_blank">'
                        . _('Vatican Press conference: Presentation of the Editio Typica Tertia of the Roman Missal')
                        . '</a>'
                    );
                    $this->addMissalMemorialMessage($propriumDeSanctisEvent);
                } elseif ($missal === RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008) {
                    $propriumDeSanctisEvent->setSinceYear(2008);
                    switch ($propriumDeSanctisEvent->event_key) {
                        case 'StPioPietrelcina':
                            $propriumDeSanctisEvent->setDecree(RomanMissal::getName($missal));
                            break;
                        /**both of the following event keys refer to the same decree, no need for a break between them */
                        case 'LadyGuadalupe':
                        case 'JuanDiego':
                            $langs = ['la' => 'lt', 'es' => 'es'];
                            $lang  = in_array(LitLocale::$PRIMARY_LANGUAGE, array_keys($langs)) ? $langs[LitLocale::$PRIMARY_LANGUAGE] : 'lt';

                            $propriumDeSanctisEvent->setDecree(
                                "<a href=\"https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_$lang.html\" target=\"_blank\">"
                                . _('Decree of the Congregation for Divine Worship')
                                . '</a>'
                            );
                            break;
                    }
                    $this->addMissalMemorialMessage($propriumDeSanctisEvent);
                }
                if ($grade === LitGrade::MEMORIAL && $this->Cal->getLiturgicalEvent($propriumDeSanctisEvent->event_key)->grade->value > LitGrade::MEMORIAL_OPT->value) {
                    $this->removeWeekdaysEpiphanyOverridenByMemorials($propriumDeSanctisEvent->event_key);
                    $this->removeWeekdaysAdventOverridenByMemorials($propriumDeSanctisEvent->event_key);
                }
            } else {
                // checkImmaculateHeartCoincidence will take care of the case of the Immaculate Heart, reducing both memorials to optional memorials in case of coincidence
                if (false === $this->checkImmaculateHeartCoincidence($propriumDeSanctisEvent)) {
                    $this->handleCoincidence($propriumDeSanctisEvent, $missal);
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
     * @param PropriumDeSanctisEvent $propriumDeSanctisEvent the row of data comprising the event_key and grade of the memorial
     */
    private function reduceMemorialsInAdventLentToCommemoration(PropriumDeSanctisEvent $propriumDeSanctisEvent): void
    {
        //If a fixed date optional memorial falls between 17 Dec. to 24 Dec., the Octave of Christmas or weekdays of the Lenten season,
        //it is reduced in rank to a Commemoration ( only the collect can be used )
        if ($this->Cal->inWeekdaysAdventChristmasLent($propriumDeSanctisEvent->date)) {
            $this->Cal->setProperty($propriumDeSanctisEvent->event_key, 'grade', LitGrade::COMMEMORATION);
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
                LitGrade::i18n($propriumDeSanctisEvent->grade, $this->CalendarParams->Locale, false),
                $propriumDeSanctisEvent->name,
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
            if (null !== $key) {
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
                    LitGrade::i18n($this->Cal->getLiturgicalEvent($key)->grade, $this->CalendarParams->Locale),
                    $this->Cal->getLiturgicalEvent($key)->name,
                    LitGrade::i18n($litEvent->grade, $this->CalendarParams->Locale, false),
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
        $Dec17    = DateTime::fromFormat('17-12-' . $this->CalendarParams->Year);
        if (
            $litEvent->date > $this->Cal->getLiturgicalEvent('Advent1')->date
            && $litEvent->date < $Dec17
        ) {
            $key = $this->Cal->weekdayAdventBeforeDec17KeyFromDate($litEvent->date);
            if (null !== $key) {
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
                    LitGrade::i18n($this->Cal->getLiturgicalEvent($key)->grade, $this->CalendarParams->Locale),
                    $this->Cal->getLiturgicalEvent($key)->name,
                    LitGrade::i18n($litEvent->grade, $this->CalendarParams->Locale, false),
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
     * @param \stdClass|PropriumDeTemporeEvent|PropriumDeSanctisEvent $potentialEvent the liturgical event that may be coinciding with a Sunday Solemnity or Feast
     * @param string $missal the edition of the Roman Missal to check against
     */
    private function handleCoincidence(\stdClass|PropriumDeTemporeEvent|PropriumDeSanctisEvent $potentialEvent, string $missal = RomanMissal::EDITIO_TYPICA_1970): void
    {
        $coincidingLiturgicalEvent = $this->Cal->determineSundaySolemnityOrFeast($potentialEvent->date, $potentialEvent->event_key);
        switch ($missal) {
            case RomanMissal::EDITIO_TYPICA_1970:
                $year   = 1970;
                $lang   = in_array(LitLocale::$PRIMARY_LANGUAGE, ['de', 'en', 'it', 'la', 'pt']) ? LitLocale::$PRIMARY_LANGUAGE : 'en';
                $decree = "<a href=\"https://www.vatican.va/content/paul-vi/$lang/apost_constitutions/documents/hf_p-vi_apc_19690403_missale-romanum.html\" target=\"_blank\">"
                    . _('Apostolic Constitution Missale Romanum')
                    . '</a>';
                break;
            case RomanMissal::EDITIO_TYPICA_TERTIA_2002:
                $year   = 2002;
                $decree = '<a href="https://press.vatican.va/content/salastampa/it/bollettino/pubblico/2002/03/22/0150/00449.html" target="_blank">'
                    . _('Vatican Press conference: Presentation of the Editio Typica Tertia of the Roman Missal')
                    . '</a>';
                break;
            case RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008:
                $year   = 2008;
                $decree = '';
                break;
            default:
                $year   = 0; // Default value, should not be used
                $decree = '';
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
        $message          = _('The %1$s \'%2$s\', added in the %3$s of the Roman Missal since the year %4$d (%5$s) and usually celebrated on %6$s, is suppressed by the %7$s \'%8$s\' in the year %9$d.');
        $locale           = LitLocale::$PRIMARY_LANGUAGE;
        $grade_str        = $potentialEvent->grade instanceof LitGrade
                    ? LitGrade::i18n($potentialEvent->grade, $this->CalendarParams->Locale, false)
                    : LitGrade::i18n(LitGrade::from($potentialEvent->grade), $this->CalendarParams->Locale, false);
        $this->Messages[] = sprintf(
            $message,
            $grade_str,
            $potentialEvent->name,
            RomanMissal::getName($missal),
            $year,
            $decree,
            $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                ? ( $potentialEvent->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[(int) $potentialEvent->date->format('n')] )
                : ( $locale === 'en'
                    ? $potentialEvent->date->format('F jS')
                    : $this->dayAndMonth->format($potentialEvent->date->format('U'))
                ),
            $coincidingLiturgicalEvent->grade_lcl,
            $coincidingLiturgicalEvent->event->name,
            $this->CalendarParams->Year
        );

        // Add the celebration to LiturgicalEventCollection::suppressedEvents
        $suppressedEvent            = LiturgicalEvent::fromObject($potentialEvent);
        $suppressedEvent->event_key = $potentialEvent->event_key;
        $this->Cal->addSuppressedEvent($suppressedEvent);
    }

    /**
     * Adds a message to the list of messages for the calendar indicating that
     * a liturgical event that would have been added to the calendar via a Decree of the Congregation for Divine Worship
     * is however superseded by a Sunday Solemnity or Feast.
     *
     * @param DecreeItem $decreeItem A decree has established a liturgical event with grade of memoriale,
     *                                  which however coincides with a Sunday Solemnity or Feast
     * @return void
     */
    private function handleCoincidenceDecree(DecreeItem $decreeItem): void
    {
        /** @var DecreeItemCreateNewFixed $liturgicalEvent */
        $liturgicalEvent           = $decreeItem->liturgical_event;
        $coincidingLiturgicalEvent = $this->Cal->determineSundaySolemnityOrFeast($liturgicalEvent->date, $liturgicalEvent->event_key);
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
            LitGrade::i18n($liturgicalEvent->grade, $this->CalendarParams->Locale),
            $liturgicalEvent->name,
            $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                ? ( $liturgicalEvent->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[(int) $liturgicalEvent->date->format('n')] )
                : ( $locale === 'en'
                    ? $liturgicalEvent->date->format('F jS')
                    : $this->dayAndMonth->format($liturgicalEvent->date->format('U'))
                ),
            $decreeItem->metadata->since_year,
            $decreeItem->metadata->getUrl(),
            $coincidingLiturgicalEvent->grade_lcl,
            $coincidingLiturgicalEvent->event->name,
            $this->CalendarParams->Year
        );
    }

    /**
     * Checks if the liturgical event in $row coincides with the Immaculate Heart of Mary.
     * If it does, it reduces both in rank to optional memorials.
     *
     * @param PropriumDeSanctisEvent $propriumDeSanctisEvent The row of data of the liturgical event to check
     * @return bool True if the liturgical event coincides with the Immaculate Heart of Mary, false otherwise
     */
    private function checkImmaculateHeartCoincidence(PropriumDeSanctisEvent $propriumDeSanctisEvent): bool
    {
        $coincidence = false;
        //IMMACULATEHEART: in years when the memorial of the Immaculate Heart of Mary coincides with another obligatory memorial,
        //as happened in 2014 [ 28 June, Saint Irenaeus ] and 2015 [ 13 June, Saint Anthony of Padua ], both must be considered optional for that year
        //source: https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
        $ImmaculateHeart = $this->Cal->getLiturgicalEvent('ImmaculateHeart');
        if ($ImmaculateHeart !== null) {
            if ($propriumDeSanctisEvent->grade === LitGrade::MEMORIAL) {
                if ($propriumDeSanctisEvent->date->format('U') === $ImmaculateHeart->date->format('U')) {
                    $this->Cal->setProperty('ImmaculateHeart', 'grade', LitGrade::MEMORIAL_OPT);
                    $litEvent = $this->Cal->getLiturgicalEvent($propriumDeSanctisEvent->event_key);
                    if ($litEvent === null) {
                        $propriumDeSanctisEvent->setGrade(LitGrade::MEMORIAL_OPT);
                        //$propriumDeSanctisEvent->type  = LitEventType::FIXED;
                        $this->Cal->addLiturgicalEvent(
                            $propriumDeSanctisEvent->event_key,
                            LiturgicalEvent::fromObject($propriumDeSanctisEvent)
                        );
                    } else {
                        $this->Cal->setProperty($propriumDeSanctisEvent->event_key, 'grade', LitGrade::MEMORIAL_OPT);
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
                        $propriumDeSanctisEvent->name,
                        $this->CalendarParams->Year,
                        '<a href="https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html" target="_blank">'
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
     * @param DecreeItem $decreeItem
     * @return void
     */
    private function handleLiturgicalEventDecreeTypeMobile(DecreeItem $decreeItem): void
    {
        /** @var DecreeItemCreateNewMobile $decreeItemLiturgicalEvent */
        $decreeItemLiturgicalEvent = $decreeItem->liturgical_event;

        if (is_string($decreeItemLiturgicalEvent->strtotime)) {
            $decreeItemLiturgicalEvent->setDate(new DateTime($decreeItemLiturgicalEvent->strtotime . ' ' . $this->CalendarParams->Year, new \DateTimeZone('UTC')));
            if (true === $this->canCreateNewMobileDecreeEvent($decreeItem)) {
                $this->createMobileLiturgicalEvent($decreeItem);
            }
        } elseif ($decreeItemLiturgicalEvent->strtotime instanceof RelativeLiturgicalDate) {
            // For example, 'Pentecost' for 'Monday after Pentecost' (Mary Mother of the Church)
            $litEvent = $this->Cal->getLiturgicalEvent($decreeItemLiturgicalEvent->strtotime->event_key);
            if ($litEvent !== null) {
                // Set the date for the Decree to the date of the liturgical event (e.g. Pentecost)
                $decreeItemLiturgicalEvent->setDate(clone( $litEvent->date ));
                switch ($decreeItemLiturgicalEvent->strtotime->relative_time) {
                    case DateRelation::Before:
                        // Modify the date based on the given day of the week that falls before the date of the given liturgical event
                        $decreeItemLiturgicalEvent->date->modify("previous {$decreeItemLiturgicalEvent->strtotime->day_of_the_week}");
                        break;
                    case DateRelation::After:
                        // Modify the date based on the given day of the week that falls after the date of the given liturgical event
                        $decreeItemLiturgicalEvent->date->modify("next {$decreeItemLiturgicalEvent->strtotime->day_of_the_week}");
                        break;
                    default:
                        $this->Messages[] = sprintf(
                            /**translators: 1. Name of the mobile liturgical event being created, 2. Name of the liturgical event that it is relative to */
                            _('Cannot create mobile liturgical event \'%1$s\': can only be relative to liturgical event with key \'%2$s\' using keywords %3$s'),
                            $decreeItemLiturgicalEvent->name,
                            $decreeItemLiturgicalEvent->strtotime->event_key,
                            implode(', ', ['\'before\'', '\'after\''])
                        );
                        break;
                }

                if (true === $this->canCreateNewMobileDecreeEvent($decreeItem)) {
                    $this->createMobileLiturgicalEvent($decreeItem);
                }
            } else {
                $this->Messages[] = sprintf(
                    /**translators: 1. Name of the mobile liturgical event being created, 2. Name of the liturgical event that it is relative to */
                    _('Cannot create mobile liturgical event \'%1$s\' relative to liturgical event with key \'%2$s\''),
                    $decreeItemLiturgicalEvent->name,
                    $decreeItemLiturgicalEvent->strtotime->event_key
                );
            }
        }
    }

    /**
     * Handle a fixed liturgical event whose date is specified using the "day" and "month" properties
     *
     * @param DecreeItem $decreeItem
     * @return void
     */
    private function handleLiturgicalEventDecreeTypeFixed(DecreeItem $decreeItem): void
    {
        /** @var DecreeItemCreateNewFixed $liturgicalEvent */
        $liturgicalEvent = $decreeItem->liturgical_event;
        $date            = DateTime::fromFormat("{$liturgicalEvent->day}-{$liturgicalEvent->month}-{$this->CalendarParams->Year}");
        $liturgicalEvent->setDate($date);

        $locale = LitLocale::$PRIMARY_LANGUAGE;

        if ($liturgicalEvent->grade === LitGrade::MEMORIAL_OPT) {
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($date)) {
                $litEvent = LiturgicalEvent::fromObject($liturgicalEvent);
                $litEvent->setReadings($this->Cal::$lectionary->getReadingsForDecreedEvent($liturgicalEvent->event_key));
                $this->Cal->addLiturgicalEvent($liturgicalEvent->event_key, $litEvent);
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
                    LitGrade::i18n($liturgicalEvent->grade, $this->CalendarParams->Locale, false),
                    $liturgicalEvent->name,
                    $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                        ? ( $date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[(int) $date->format('n')] )
                        : ( $locale === 'en' ? $date->format('F jS') :
                            $this->dayAndMonth->format($date->format('U'))
                        ),
                    $decreeItem->metadata->since_year,
                    $decreeItem->metadata->getUrl(),
                    $this->CalendarParams->Year
                );
            } else {
                $this->handleCoincidenceDecree($decreeItem);
            }
        }
    }


    /**
     * Creates a LiturgicalEvent object based on information from a Decree of the Congregation for Divine Worship
     * and adds it to the calendar.
     * @param DecreeItem $decreeItem
     * @return void
     */
    private function createLiturgicalEventFromDecree(DecreeItem $decreeItem): void
    {
        if ($decreeItem->liturgical_event instanceof DecreeItemCreateNewFixed) {
            $this->handleLiturgicalEventDecreeTypeFixed($decreeItem);
        } else {
            $this->handleLiturgicalEventDecreeTypeMobile($decreeItem);
        }
    }

    /**
     * Sets a property of a liturgical event (name, grade) based on a Decree of the Congregation for Divine Worship
     * and adds a message to the list of messages for the calendar indicating that the property has been changed.
     * @param DecreeItem $decreeItem
     * @return void
     */
    private function setPropertyBasedOnDecree(DecreeItem $decreeItem): void
    {
        /** @var DecreeItemSetPropertyGrade|DecreeItemSetPropertyName $decreeLiturgicalEvent */
        $decreeLiturgicalEvent = $decreeItem->liturgical_event;
        /** @var DecreeItemSetPropertyGradeMetadata|DecreeItemSetPropertyNameMetadata $decreeMetadata */
        $decreeMetadata = $decreeItem->metadata;

        $existingLiturgicalEvent = $this->Cal->getLiturgicalEvent($decreeLiturgicalEvent->event_key);
        if ($existingLiturgicalEvent !== null) {
            if ($decreeMetadata instanceof DecreeItemSetPropertyNameMetadata) {
                //example: StMartha becomes Martha, Mary and Lazarus in 2021
                $this->Cal->setProperty($decreeLiturgicalEvent->event_key, 'name', $decreeLiturgicalEvent->name);
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
                    LitGrade::i18n($existingLiturgicalEvent->grade, $this->CalendarParams->Locale, false),
                    '<i>' . $existingLiturgicalEvent->name . '</i>',
                    '<i>' . $decreeLiturgicalEvent->name . '</i>',
                    $decreeMetadata->since_year,
                    $this->CalendarParams->Year,
                    $decreeMetadata->getUrl()
                );
            } elseif ($decreeMetadata instanceof DecreeItemSetPropertyGradeMetadata) {
                /** @var DecreeItemSetPropertyGrade $decreeLiturgicalEvent */
                if ($decreeLiturgicalEvent->grade->value > $existingLiturgicalEvent->grade->value) {
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
                    LitGrade::i18n($existingLiturgicalEvent->grade, $this->CalendarParams->Locale, false),
                    $existingLiturgicalEvent->name,
                    LitGrade::i18n($decreeLiturgicalEvent->grade, $this->CalendarParams->Locale, false),
                    $decreeMetadata->since_year,
                    $this->CalendarParams->Year,
                    $decreeMetadata->getUrl()
                );
                $this->Cal->setProperty($decreeLiturgicalEvent->event_key, 'grade', $decreeLiturgicalEvent->grade);
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
        /** @var array<DecreeItem> */
        $doctorDecrees = $this->decreeItems->getDoctorDecrees();
        if (count($doctorDecrees) === 0) {
            throw new \InvalidArgumentException('There are no Decrees of the Congregation for Divine Worship for Doctors of the Church.');
        }

        foreach ($doctorDecrees as $doctorDecree) {
            /** @var DecreeItemMakeDoctor $doctorDecreeLiturgicalEvent */
            $doctorDecreeLiturgicalEvent = $doctorDecree->liturgical_event;

            /** @var DecreeItemMakeDoctorMetadata */
            $doctorDecreeMetadata = $doctorDecree->metadata;

            if ($this->CalendarParams->Year >= $doctorDecreeMetadata->since_year) {
                $existingLitEvent = $this->Cal->getLiturgicalEvent($doctorDecreeLiturgicalEvent->event_key);
                if ($existingLitEvent !== null) {
                    /**translators:
                     * 1. Name of the liturgical event
                     * 2. Year in which was declared Doctor
                     * 3. Requested calendar year
                     * 4. Source of the information
                     */
                    $message          = _('\'%1$s\' has been declared a Doctor of the Church since the year %2$d, applicable to the year %3$d (%4$s).');
                    $this->Messages[] = sprintf(
                        $message,
                        '<i>' . $existingLitEvent->name . '</i>',
                        $doctorDecreeMetadata->since_year,
                        $this->CalendarParams->Year,
                        $doctorDecreeMetadata->getUrl()
                    );

                    $etDoctor    = ( LitLocale::$PRIMARY_LANGUAGE === LitLocale::LATIN_PRIMARY_LANGUAGE )
                                    ? $existingLitEvent->name . ' et Ecclesiæ doctoris'
                                    : $doctorDecreeLiturgicalEvent->name; //' ' . _('and Doctor of the Church')
                    $propertySet = $this->Cal->setProperty($doctorDecreeLiturgicalEvent->event_key, 'name', $etDoctor);
                    if (false === $propertySet) {
                        throw new \Exception('Could not set name for liturgical event ' . $doctorDecreeLiturgicalEvent->event_key . ' to ' . $etDoctor);
                    }
                }
            }
        }
    }


    /**
     * Applies memorials based on Decrees of the Congregation for Divine Worship to the calendar.
     *
     * @param LitGrade $grade The grade of the liturgical event (e.g. 'memorial', 'feast', etc.)
     *                               Defaults to LitGrade::MEMORIAL if not provided.
     * @return void
     */
    private function applyDecrees(LitGrade $grade = LitGrade::MEMORIAL): void
    {
        if (count($this->decreeItems) === 0) {
            $message = 'We seem to be missing data for Memorials based on Decrees: array data was not found!';
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
        }

        // filterByGrade excludes Doctors of the Church through the `makeDoctor` action
        $decreeItems = $this->decreeItems->filterByGrade($grade);
        if (count($decreeItems) === 0) {
            throw new \Exception('We seem to be missing data for Decrees that handle liturgical events of grade ' . $grade->value . ': no data found!');
        }
        foreach ($decreeItems as $decreeItem) {
            if ($this->CalendarParams->Year >= $decreeItem->metadata->since_year) {
                switch ($decreeItem->metadata->action) {
                    case CalEventAction::CreateNew:
                        //example: MaryMotherChurch in 2018
                        $this->createLiturgicalEventFromDecree($decreeItem);
                        break;
                    case CalEventAction::SetProperty:
                        $this->setPropertyBasedOnDecree($decreeItem);
                        break;
                }
            }
        }

        if ($this->CalendarParams->Year === 2009) {
            //Conversion of St. Paul falls on a Sunday in the year 2009
            //Faculty to celebrate as optional memorial
            $this->applyOptionalMemorialDecree2009();
        }
    }

    /**
     * Creates a mobile LiturgicalEvent object based on information from a Decree of the Congregation for Divine Worship
     * and adds it to the calendar.
     * @param DecreeItem $decreeItem The row from the database containing the information about the liturgical event
     * @return void
     */
    private function createMobileLiturgicalEvent(DecreeItem $decreeItem): void
    {
        /** @var DecreeItemCreateNewMobile $liturgicalEvent */
        $liturgicalEvent = $decreeItem->liturgical_event;
        $litEvent        = LiturgicalEvent::fromObject($liturgicalEvent);
        $litEvent->setReadings($this->Cal::$lectionary->getReadingsForDecreedEvent($liturgicalEvent->event_key));
        $this->Cal->addLiturgicalEvent($liturgicalEvent->event_key, $litEvent);

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
            LitGrade::i18n($liturgicalEvent->grade, $this->CalendarParams->Locale, false),
            $liturgicalEvent->name,
            $liturgicalEvent->strtotime,
            $decreeItem->metadata->since_year,
            $decreeItem->metadata->getUrl(),
            $this->CalendarParams->Year
        );
    }

    /**
     * Whether a mobile liturgical event can be added to the calendar.
     *
     * A mobile liturgical event can be added to the calendar if it does not conflict with a Solemnity or Feast.
     *
     * If it does, we return false.
     *
     * If it only coincides with a liturgical event that is lesser or equal to memorial,
     * then we remove the coinciding liturgical event from the calendar
     * and add a message indicating what has happened, and we return true.
     *
     * @param DecreeItem $decreeItem
     * @return bool True if the mobile liturgical event can be added to the calendar, false if it has been
     *              superseded by a Solemnity or Feast.
     */
    private function canCreateNewMobileDecreeEvent(DecreeItem $decreeItem): bool
    {
        /** @var DecreeItemCreateNewMobile $decreeItemLiturgicalEvent */
        $decreeItemLiturgicalEvent = $decreeItem->liturgical_event;
        if ($decreeItemLiturgicalEvent->grade === LitGrade::MEMORIAL) {
            // A Memorial is superseded by Solemnities and Feasts, but not by Memorials of Saints
            if ($this->Cal->inSolemnitiesOrFeasts($decreeItemLiturgicalEvent->date)) {
                if ($this->Cal->inSolemnities($decreeItemLiturgicalEvent->date)) {
                    $coincidingLiturgicalEvent = $this->Cal->solemnityFromDate($decreeItemLiturgicalEvent->date);
                } elseif ($this->Cal->inFeastsLord($decreeItemLiturgicalEvent->date)) {
                    $coincidingLiturgicalEvent = $this->Cal->feastLordFromDate($decreeItemLiturgicalEvent->date);
                } else {
                    $coincidingLiturgicalEvent = $this->Cal->feastFromDate($decreeItemLiturgicalEvent->date);
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
                    LitGrade::i18n($decreeItemLiturgicalEvent->grade, $this->CalendarParams->Locale, false),
                    '<i>' . $decreeItemLiturgicalEvent->name . '</i>',
                    $decreeItemLiturgicalEvent->strtotime,
                    $decreeItem->metadata->since_year,
                    $decreeItem->metadata->getUrl(),
                    LitGrade::i18n($coincidingLiturgicalEvent->grade, $this->CalendarParams->Locale, false),
                    '<i>' . $coincidingLiturgicalEvent->name . '</i>',
                    $this->CalendarParams->Year
                );
                return false;
            } else {
                if ($this->Cal->inCalendar($decreeItemLiturgicalEvent->date)) {
                    $coincidingLiturgicalEvents = $this->Cal->getCalEventsFromDate($decreeItemLiturgicalEvent->date);
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
                                LitGrade::i18n($coincidingLiturgicalEvent->grade, $this->CalendarParams->Locale, false),
                                '<i>' . $coincidingLiturgicalEvent->name . '</i>',
                                LitGrade::i18n($decreeItemLiturgicalEvent->grade, $this->CalendarParams->Locale, false),
                                '<i>' . $decreeItemLiturgicalEvent->name . '</i>',
                                $decreeItemLiturgicalEvent->strtotime,
                                $decreeItem->metadata->since_year,
                                $decreeItem->metadata->getUrl()
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
        $this->PropriumDeTempore['ImmaculateHeart']->setDate(Utilities::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P' . ( 7 * 9 + 6 ) . 'D')));
        if ($this->Cal->notInSolemnitiesFeastsOrMemorials($this->PropriumDeTempore['ImmaculateHeart']->date)) {
            //Immaculate Heart of Mary fixed on the Saturday following the second Sunday after Pentecost
            //( see Calendarium Romanum Generale in Missale Romanum Editio Typica 1970 )
            //Pentecost = Utilities::calcGregEaster( $this->CalendarParams->Year )->add( new \DateInterval( 'P'.( 7*7 ).'D' ) )
            //Second Sunday after Pentecost = Utilities::calcGregEaster( $this->CalendarParams->Year )->add( new \DateInterval( 'P'.( 7*9 ).'D' ) )
            //Following Saturday = Utilities::calcGregEaster( $this->CalendarParams->Year )->add( new \DateInterval( 'P'.( 7*9+6 ).'D' ) )
            $this->Cal->addLiturgicalEvent(
                'ImmaculateHeart',
                LiturgicalEvent::fromObject($this->PropriumDeTempore['ImmaculateHeart'])
            );

            //In years when this memorial coincides with another obligatory memorial, as happened in 2014 [ 28 June, Saint Irenaeus ] and 2015 [ 13 June, Saint Anthony of Padua ],
            // both must be considered optional for that year
            //source: https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
            //This is taken care of in the next code cycle, see tag IMMACULATEHEART: in the code comments ahead
        } else {
            $this->handleCoincidence($this->PropriumDeTempore['ImmaculateHeart'], RomanMissal::EDITIO_TYPICA_1970);
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
        $StJaneFrancesNewDate = DateTime::fromFormat('12-8-' . $this->CalendarParams->Year);

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
                    "<a href=\"https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_$lang.html\" target=\"_blank\">"
                        . _('Decree of the Congregation for Divine Worship')
                        . '</a>',
                    $this->CalendarParams->Year
                );
            } else {
                //perhaps it wasn't created on December 12th because it was superseded by a Sunday, Solemnity or Feast
                //but seeing that there is no problem for August 12th, let's go ahead and try creating it again
                $propriumDeSanctisEvent = $this->missalsMap[RomanMissal::EDITIO_TYPICA_1970]['StJaneFrancesDeChantal'];
                $propriumDeSanctisEvent->setDate($StJaneFrancesNewDate);
                $litEvent = LiturgicalEvent::fromObject($propriumDeSanctisEvent);
                $this->Cal->addLiturgicalEvent('StJaneFrancesDeChantal', $litEvent);
                $this->Messages[] = sprintf(
                    /**translators: 1: LiturgicalEvent name, 2: Source of the information, 3: Requested calendar year  */
                    _('The optional memorial \'%1$s\', which would have been superseded this year by a Sunday or Solemnity were it on Dec. 12, has however been transferred to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d.'),
                    $litEvent->name,
                    "<a href=\"https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_$lang.html\" target=\"_blank\">"
                        . _('Decree of the Congregation for Divine Worship')
                        . '</a>',
                    $this->CalendarParams->Year
                );
            }
        } else {
            $coincidingLiturgicalEvent = $this->Cal->determineSundaySolemnityOrFeast($StJaneFrancesNewDate, 'StJaneFrancesDeChantal');
            $litEvent                  = $this->Cal->getLiturgicalEvent('StJaneFrancesDeChantal');
            // we can't move it, but we still need to remove it from Dec 12th if it's there!!!
            if ($litEvent !== null) {
                $this->Cal->removeLiturgicalEvent('StJaneFrancesDeChantal');
            }
            $row              = $this->missalsMap[RomanMissal::EDITIO_TYPICA_1970]['StJaneFrancesDeChantal'];
            $this->Messages[] = sprintf(
                /**translators: 1: LiturgicalEvent name, 2: Source of the information, 3: Requested calendar year, 4: Coinciding LiturgicalEvent grade, 5: Coinciding LiturgicalEvent name */
                _('The optional memorial \'%1$s\' has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d. However, it is superseded by the %4$s \'%5$s\' in the year %3$d.'),
                $row->name,
                "<a href=\"https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_$lang.html\" target=\"_blank\">"
                    . _('Decree of the Congregation for Divine Worship')
                    . '</a>',
                $this->CalendarParams->Year,
                $coincidingLiturgicalEvent->grade_lcl,
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
            $propriumDeSanctisEvent = $this->missalsMap[RomanMissal::EDITIO_TYPICA_1970]['ConversionStPaul'];
            $litEvent               = new LiturgicalEvent(
                $propriumDeSanctisEvent->name,
                DateTime::fromFormat('25-1-2009'),
                LitColor::WHITE,
                LitEventType::FIXED,
                LitGrade::MEMORIAL_OPT,
                LitCommon::PROPRIO,
                null
            );
            $this->Cal->addLiturgicalEvent('ConversionStPaul', $litEvent);
            $langs            = ['fr' => 'fr', 'en' => 'en', 'it' => 'it', 'la' => 'lt', 'pt' => 'pt', 'es' => 'sp', 'de' => 'ge'];
            $lang             = in_array(LitLocale::$PRIMARY_LANGUAGE, array_keys($langs)) ? $langs[LitLocale::$PRIMARY_LANGUAGE] : 'en';
            $this->Messages[] = sprintf(
                /**translators: 1: LiturgicalEvent name, 2: Source of the information  */
                _('The Feast \'%1$s\' would have been suppressed this year ( 2009 ) since it falls on a Sunday, however being the Year of the Apostle Paul, as per the %2$s it has been reinstated so that local churches can optionally celebrate the memorial.'),
                '<i>' . $propriumDeSanctisEvent->name . '</i>',
                "<a href=\"https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20080125_san-paolo_$lang.html\" target=\"_blank\">"
                    . _('Decree of the Congregation for Divine Worship')
                    . '</a>'
            );
        }
    }

    /**
     * Calculates the liturgical events for Weekdays of Easter, from the Monday of the Octave of Easter to the Saturday before Pentecost.
     *
     * The events are keyed as "EasterWeekdayXMonday" where X is the week number (1, 2, 3, etc.) and Monday is the day of the week (Monday, Tuesday, Wednesday, etc.).
     *
     * The dates for the Weekdays of Easter are calculated starting from Easter Sunday itself, and then counting up one day at a time until the Saturday before Pentecost.
     * For each weekday, the event name is generated as follows:
     * - For the Latin locale, the name is in the format "feria secunda Hebdomadæ X Temporis Paschali", where X is the week number in ordinal form (Primæ, Secundæ, etc.).
     * - For all other locales, the name is in the format "Monday of the X Week of Easter", where X is the week number in ordinal form, according to the current locale.
     * - The event color is always white.
     * - The event type is always MOBILE.
     * - The event grade is always WEEKDAY.
     * - The event psalter_week is set to the week number.
     *
     * @return void
     */
    private function calculateWeekdaysEaster(): void
    {
        $DoMEaster   = $this->Cal->getLiturgicalEvent('Easter')->date->format('j');      //day of the month of Easter
        $MonthEaster = $this->Cal->getLiturgicalEvent('Easter')->date->format('n');    //month of Easter
        //let's start cycling dates one at a time starting from Easter itself
        $weekdayEasterDate = DateTime::fromFormat($DoMEaster . '-' . $MonthEaster . '-' . $this->CalendarParams->Year);
        $weekdayEasterCnt  = 1;
        while ($weekdayEasterDate >= $this->Cal->getLiturgicalEvent('Easter')->date && $weekdayEasterDate < $this->Cal->getLiturgicalEvent('Pentecost')->date) {
            $weekdayEasterDate = DateTime::fromFormat($DoMEaster . '-' . $MonthEaster . '-' . $this->CalendarParams->Year)->add(new \DateInterval('P' . $weekdayEasterCnt . 'D'));
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($weekdayEasterDate) && self::dateIsNotSunday($weekdayEasterDate)) {
                $upper                  = (int) $weekdayEasterDate->format('z');
                $diff                   = $upper - (int) $this->Cal->getLiturgicalEvent('Easter')->date->format('z'); //day count between current day and Easter Sunday
                $currentEasterWeek      = ( ( $diff - $diff % 7 ) / 7 ) + 1; //week count between current day and Easter Sunday
                $ordinal                = ucfirst(Utilities::getOrdinal($currentEasterWeek, $this->CalendarParams->Locale, $this->formatterFem, LatinUtils::LATIN_ORDINAL_FEM_GEN));
                $locale                 = LitLocale::$PRIMARY_LANGUAGE;
                $dayOfTheWeek           = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? LatinUtils::LATIN_DAYOFTHEWEEK[$weekdayEasterDate->format('w')]
                    : Utilities::ucfirst($this->dayOfTheWeek->format($weekdayEasterDate->format('U')));
                $t                      = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? sprintf('Hebdomadæ %s Temporis Paschali', $ordinal)
                    : sprintf(_('of the %s Week of Easter'), $ordinal);
                $name                   = $dayOfTheWeek . ' ' . $t;
                $dayOfTheWeekEnglish    = $this->dayOfTheWeekEnglish->format($weekdayEasterDate->format('U'));
                $event_key              = 'EasterWeekday' . $currentEasterWeek . $dayOfTheWeekEnglish;
                $litEvent               = new LiturgicalEvent(
                    $name,
                    $weekdayEasterDate,
                    LitColor::WHITE,
                    LitEventType::MOBILE,
                    LitGrade::WEEKDAY,
                    LitCommon::NONE,
                    null
                );
                $litEvent->psalter_week = $this->Cal::psalterWeek($currentEasterWeek);
                $this->Cal->addLiturgicalEvent($event_key, $litEvent);
            }
            $weekdayEasterCnt++;
        }
    }

    /**
     * Calculates the dates for weekdays of Ordinary Time throughout the liturgical year
     * and creates the corresponding LiturgicalEvents in the calendar.
     *
     * Weekdays of Ordinary Time are divided into two parts:
     * - The first part begins the day after the Baptism of the Lord and ends with Ash Wednesday.
     * - The second part begins the day after Pentecost and ends with the Feast of Christ the King.
     *
     * For each weekday, the event name is generated in the format:
     * - For the Latin locale, "feria Hebdomadæ X Temporis Ordinarii", where X is the week number in ordinal form (Primæ, Secundæ, etc).
     * - For all other locales, "Monday of the X Week of Ordinary Time", where X is the week number in ordinal form according to the current locale.
     *
     * The event color is green, and the event type is MOBILE, with a grade of WEEKDAY.
     *
     * @return void
     */
    private function calculateWeekdaysOrdinaryTime(): void
    {
        // In the first part of the year, weekdays of ordinary time begin the day after the Baptism of the Lord
        $FirstWeekdaysLowerLimit = $this->Cal->getLiturgicalEvent('BaptismLord')->date;
        // and end with Ash Wednesday
        $FirstWeekdaysUpperLimit = $this->Cal->getLiturgicalEvent('AshWednesday')->date;

        $ordWeekday        = 1;
        $currentOrdWeek    = 1;
        $firstOrdinaryDate = DateTime::fromFormat($this->BaptismLordFmt)->modify($this->BaptismLordMod);
        $firstSundayDate   = DateTime::fromFormat($this->BaptismLordFmt)->modify($this->BaptismLordMod)->modify('next Sunday');
        $dayFirstSunday    = (int) $firstSundayDate->format('z');

        while ($firstOrdinaryDate >= $FirstWeekdaysLowerLimit && $firstOrdinaryDate < $FirstWeekdaysUpperLimit) {
            $firstOrdinaryDate = DateTime::fromFormat($this->BaptismLordFmt)->modify($this->BaptismLordMod)->add(new \DateInterval('P' . $ordWeekday . 'D'));
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($firstOrdinaryDate)) {
                // The Baptism of the Lord is the First Sunday, so the weekdays following are of the First Week of Ordinary Time
                // After the Second Sunday, let's calculate which week of Ordinary Time we're in
                if ($firstOrdinaryDate > $firstSundayDate) {
                    $upper          = (int) $firstOrdinaryDate->format('z');
                    $diff           = $upper - $dayFirstSunday;
                    $currentOrdWeek = ( ( $diff - $diff % 7 ) / 7 ) + 2;
                }
                $ordinal                = ucfirst(Utilities::getOrdinal($currentOrdWeek, $this->CalendarParams->Locale, $this->formatterFem, LatinUtils::LATIN_ORDINAL_FEM_GEN));
                $locale                 = LitLocale::$PRIMARY_LANGUAGE;
                $dayOfTheWeek           = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? LatinUtils::LATIN_DAYOFTHEWEEK[$firstOrdinaryDate->format('w')]
                    : Utilities::ucfirst($this->dayOfTheWeek->format($firstOrdinaryDate->format('U')));
                $nthStr                 = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? sprintf('Hebdomadæ %s Temporis Ordinarii', $ordinal)
                    : sprintf(_('of the %s Week of Ordinary Time'), $ordinal);
                $name                   = $dayOfTheWeek . ' ' . $nthStr;
                $dayOfTheWeekEnglish    = $this->dayOfTheWeekEnglish->format($firstOrdinaryDate->format('U'));
                $event_key              = 'OrdWeekday' . $currentOrdWeek . $dayOfTheWeekEnglish;
                $litEvent               = new LiturgicalEvent(
                    $name,
                    $firstOrdinaryDate,
                    LitColor::GREEN,
                    LitEventType::MOBILE,
                    LitGrade::WEEKDAY,
                    LitCommon::NONE,
                    null
                );
                $litEvent->psalter_week = $this->Cal::psalterWeek($currentOrdWeek);
                $this->Cal->addLiturgicalEvent($event_key, $litEvent);
            }
            $ordWeekday++;
        }

        // In the second part of the year, weekdays of ordinary time begin the day after Pentecost
        $SecondWeekdaysLowerLimit = $this->Cal->getLiturgicalEvent('Pentecost')->date;
        // and end with the Feast of Christ the King (four Sundays before Christmas)
        $SecondWeekdaysUpperLimit = DateTime::fromFormat('25-12-' . $this->CalendarParams->Year)->modify('last Sunday')->sub(new \DateInterval('P' . ( 3 * 7 ) . 'D'));

        //$currentOrdWeek = 1;
        $ordWeekday       = 1;
        $lastOrdinaryDate = Utilities::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P' . ( 7 * 7 ) . 'D'));
        $dayLastSunday    = (int) DateTime::fromFormat('25-12-' . $this->CalendarParams->Year)->modify('last Sunday')->sub(new \DateInterval('P' . ( 3 * 7 ) . 'D'))->format('z');

        while ($lastOrdinaryDate >= $SecondWeekdaysLowerLimit && $lastOrdinaryDate < $SecondWeekdaysUpperLimit) {
            $lastOrdinaryDate = Utilities::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P' . ( 7 * 7 + $ordWeekday ) . 'D'));
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($lastOrdinaryDate)) {
                $lower               = (int) $lastOrdinaryDate->format('z');
                $diff                = $dayLastSunday - $lower; //day count between current day and Christ the King Sunday
                $weekDiff            = ( ( $diff - $diff % 7 ) / 7 ); //week count between current day and Christ the King Sunday;
                $currentOrdWeek      = 34 - $weekDiff;
                $ordinal             = ucfirst(Utilities::getOrdinal($currentOrdWeek, $this->CalendarParams->Locale, $this->formatterFem, LatinUtils::LATIN_ORDINAL_FEM_GEN));
                $locale              = LitLocale::$PRIMARY_LANGUAGE;
                $dayOfTheWeek        = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? LatinUtils::LATIN_DAYOFTHEWEEK[$lastOrdinaryDate->format('w')]
                    : Utilities::ucfirst($this->dayOfTheWeek->format($lastOrdinaryDate->format('U')));
                $nthStr              = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? sprintf('Hebdomadæ %s Temporis Ordinarii', $ordinal)
                    : sprintf(_('of the %s Week of Ordinary Time'), $ordinal);
                $name                = $dayOfTheWeek . ' ' . $nthStr;
                $dayOfTheWeekEnglish = $this->dayOfTheWeekEnglish->format($lastOrdinaryDate->format('U'));
                $event_key           = 'OrdWeekday' . $currentOrdWeek . $dayOfTheWeekEnglish;
                $litEvent            = new LiturgicalEvent(
                    $name,
                    $lastOrdinaryDate,
                    LitColor::GREEN,
                    LitEventType::MOBILE,
                    LitGrade::WEEKDAY,
                    LitCommon::NONE,
                    null
                );

                $litEvent->psalter_week = $this->Cal::psalterWeek($currentOrdWeek);
                $this->Cal->addLiturgicalEvent($event_key, $litEvent);
            }
            $ordWeekday++;
        }
    }

    /**
     * On Saturdays in Ordinary Time when there is no obligatory memorial, an optional memorial of the Blessed Virgin Mary is allowed.
     *
     * We cycle through all Saturdays of the year checking that it falls in Ordinary Time and that there isn't an obligatory memorial.
     * First we find the first Saturday of the civil year ( to do this we actually have to find the last Saturday of the previous year,
     * so that our cycle using "next Saturday" logic will actually start from the first Saturday of the year ),
     * and then continue for every next Saturday until we reach the last Saturday of the year.
     */
    private function calculateSaturdayMemorialBVM(): void
    {
        $currentSaturdayDate = new DateTime("previous Saturday January {$this->CalendarParams->Year}", new \DateTimeZone('UTC'));
        $lastSaturdayDate    = new DateTime("last Saturday December {$this->CalendarParams->Year}", new \DateTimeZone('UTC'));
        $SatMemBVM_cnt       = 0;
        while ($currentSaturdayDate <= $lastSaturdayDate) {
            $currentSaturdayDate = DateTime::fromFormat($currentSaturdayDate->format('j-n-Y'))->modify('next Saturday');
            if ($this->Cal->inOrdinaryTime($currentSaturdayDate) && $this->Cal->notInSolemnitiesFeastsOrMemorials($currentSaturdayDate)) {
                $memID    = 'SatMemBVM' . ++$SatMemBVM_cnt;
                $locale   = LitLocale::$PRIMARY_LANGUAGE;
                $name     = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? 'Memoria Sanctæ Mariæ in Sabbato'
                    : _('Saturday Memorial of the Blessed Virgin Mary');
                $litEvent = new LiturgicalEvent(
                    $name,
                    $currentSaturdayDate,
                    LitColor::WHITE,
                    LitEventType::MOBILE,
                    LitGrade::MEMORIAL_OPT,
                    LitCommon::BEATAE_MARIAE_VIRGINIS
                );
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
            JsonData::WIDER_REGION_FILE,
            ['{wider_region}' => $this->NationalData->metadata->wider_region]
        );

        $widerRegionDataJson = Utilities::jsonFileToObject($widerRegionDataFile);
        if (is_array($widerRegionDataJson)) {
            throw new \Exception('Expected wider region data to be an object, got an array');
        }
        $this->WiderRegionData = WiderRegionData::fromObject($widerRegionDataJson);
    }

    /**
     * Loads the JSON data for the specified National calendar.
     *
     * @return void
     */
    private function loadNationalCalendarData(): void
    {
        if (null === $this->CalendarParams->NationalCalendar) {
            return;
        }

        if ('VA' === $this->CalendarParams->NationalCalendar) {
            return;
        }

        $NationalDataFile = strtr(
            JsonData::NATIONAL_CALENDAR_FILE,
            [ '{nation}' => $this->CalendarParams->NationalCalendar ]
        );

        $nationalDataJson = Utilities::jsonFileToObject($NationalDataFile);
        if (is_array($nationalDataJson)) {
            throw new \Exception('Expected national data to be an object, got an array');
        }
        $this->NationalData = NationalData::fromObject($nationalDataJson);

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

        if ($this->NationalData->hasWiderRegion()) {
            $this->loadWiderRegionData();
        } else {
            $this->Messages[] = sprintf(
                /**translators: 1: wider_region, 2: metadata, 3: calendar_id */
                _('Could not find a %1$s property in the %2$s for the National Calendar %3$s.'),
                '`wider_region`',
                '`metadata`',
                $this->CalendarParams->NationalCalendar
            );
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
     * @param LitCalItem $litCalItem the data from the JSON file containing the information about the liturgical event
     * @return void
     */
    private function handleMissingPatronEvent(LitCalItem $litCalItem): void
    {
        if ($this->Cal->isSuppressed($litCalItem->liturgical_event->event_key)) {
            $suppressedEvent = $this->Cal->getSuppressedEventByKey($litCalItem->liturgical_event->event_key);
            // Let's check if it was suppressed by a Solemnity, Feast, Memorial or Sunday,
            // so we can give some feedback and maybe even recreate the liturgical event if applicable
            if ($this->Cal->inSolemnitiesFeastsOrMemorials($suppressedEvent->date) || self::dateIsSunday($suppressedEvent->date)) {
                /** @var LitCalItemMakePatron $liturgicalEvent  */
                $liturgicalEvent           = $litCalItem->liturgical_event;
                $coincidingLiturgicalEvent = $this->Cal->determineSundaySolemnityOrFeast($suppressedEvent->date, $liturgicalEvent->event_key);
                // If it was suppressed by a Feast or Memorial, we should be able to create it
                // so we'll get the required properties back from the suppressed event
                if ($this->Cal->inFeastsOrMemorials($suppressedEvent->date)) {
                    $this->Cal->addLiturgicalEvent(
                        $liturgicalEvent->event_key,
                        new LiturgicalEvent(
                            $liturgicalEvent->name,
                            $suppressedEvent->date,
                            $suppressedEvent->color,
                            LitEventType::FIXED,
                            $liturgicalEvent->grade,
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
                        LitGrade::i18n($liturgicalEvent->grade, $this->CalendarParams->Locale, false),
                        $liturgicalEvent->name,
                        $this->dayAndMonth->format($suppressedEvent->date->format('U')),
                        $coincidingLiturgicalEvent->grade_lcl,
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
                        LitGrade::i18n($liturgicalEvent->grade, $this->CalendarParams->Locale, false),
                        $liturgicalEvent->name,
                        $this->dayAndMonth->format($suppressedEvent->date->format('U')),
                        $coincidingLiturgicalEvent->grade_lcl,
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
     * @param LitCalItemCreateNewFixed|LitCalItemCreateNewMobile $liturgicalEvent the row of data from the JSON file containing the information about the liturgical event
     * @return bool true if the liturgical event can be created, false if it is superseded by a Solemnity or a Feast
     */
    private function liturgicalEventCanBeCreated(LitCalItemCreateNewFixed|LitCalItemCreateNewMobile $liturgicalEvent): bool
    {
        switch ($liturgicalEvent->grade) {
            case LitGrade::MEMORIAL_OPT:
                return $this->Cal->notInSolemnitiesFeastsOrMemorials($liturgicalEvent->date);
            case LitGrade::MEMORIAL:
                return $this->Cal->notInSolemnitiesOrFeasts($liturgicalEvent->date);
                //however we still have to handle possible coincidences with another memorial
            case LitGrade::FEAST:
                return $this->Cal->notInSolemnities($liturgicalEvent->date);
                //however we still have to handle possible coincidences with another feast
            case LitGrade::SOLEMNITY:
                return true;
                //however we still have to handle possible coincidences with another solemnity
        }
        return false;
    }

    /**
     * Checks if a LiturgicalEvent does not coincide with another LiturgicalEvent of equal or higher rank
     * @param LitCalItemCreateNewFixed|LitCalItemCreateNewMobile $liturgicalEvent the row of data from the JSON file containing the information about the liturgical event
     * @return bool true if the liturgical event does not coincide with another liturgical event of equal or higher rank, false if it does
     */
    private function liturgicalEventDoesNotCoincide(LitCalItemCreateNewFixed|LitCalItemCreateNewMobile $liturgicalEvent): bool
    {
        switch ($liturgicalEvent->grade) {
            case LitGrade::MEMORIAL_OPT:
                return true;
                //optional memorials never have problems as regards coincidence with another optional memorial
            case LitGrade::MEMORIAL:
                return $this->Cal->notInMemorials($liturgicalEvent->date);
            case LitGrade::FEAST:
                return $this->Cal->notInFeasts($liturgicalEvent->date);
            case LitGrade::SOLEMNITY:
                return $this->Cal->notInSolemnities($liturgicalEvent->date);
        }
        return true;
    }

    /**
     * Handles a liturgical event that coincides with another liturgical event of equal or higher rank in the calendar.
     * If the coinciding liturgical event is a Memorial, both are reduced in rank to optional memorials.
     * If the coinciding liturgical event is a Feast or a Solemnity, a message is added to the Messages array.
     * @param LitCalItemCreateNewMobile|LitCalItemCreateNewFixed $liturgicalEvent the row of data from the JSON file containing the information about the liturgical event
     * @return void
     */
    private function handleLiturgicalEventCreationWithCoincidence(LitCalItemCreateNewMobile|LitCalItemCreateNewFixed $liturgicalEvent): void
    {
        switch ($liturgicalEvent->grade) {
            case LitGrade::MEMORIAL:
                //both memorials become optional memorials
                $coincidingLiturgicalEvents = $this->Cal->getCalEventsFromDate($liturgicalEvent->date);
                $coincidingMemorials        = array_filter($coincidingLiturgicalEvents, function ($el) {
                    return $el->grade === LitGrade::MEMORIAL;
                });
                foreach ($coincidingMemorials as $key => $value) {
                    $this->Cal->setProperty($key, 'grade', LitGrade::MEMORIAL_OPT);
                    $this->Messages[] = sprintf(
                        /**translators:
                         * 1. Name of the first coinciding Memorial
                         * 2. Name of the second coinciding Memorial
                         * 3. Requested calendar year
                         * 4. Source of the information
                         */
                        _('The Memorial \'%1$s\' coincides with another Memorial \'%2$s\' in the year %3$d. They are both reduced in rank to optional memorials.'),
                        $liturgicalEvent->name,
                        $value->name,
                        $this->CalendarParams->Year
                    );
                }
                //$liturgicalEvent->type  = LitEventType::FIXED;
                $liturgicalEvent->setGrade(LitGrade::MEMORIAL_OPT);
                $newLitEvent = LiturgicalEvent::fromObject($liturgicalEvent);
                $this->Cal->addLiturgicalEvent($liturgicalEvent->event_key, $newLitEvent);
                break;
            case LitGrade::FEAST:
                //there seems to be a coincidence with a different Feast on the same day!
                //what should we do about this? perhaps move one of them?
                $coincidingLiturgicalEvents = $this->Cal->getCalEventsFromDate($liturgicalEvent->date);
                $coincidingFeasts           = array_filter($coincidingLiturgicalEvents, function ($el) {
                    return $el->grade === LitGrade::FEAST;
                });
                foreach ($coincidingFeasts as $key => $value) {
                    $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> '
                        . $this->CalendarParams->NationalCalendar . ': '
                        . sprintf(
                            /**translators: 1. LiturgicalEvent name, 2. LiturgicalEvent date, 3. Coinciding liturgical event name, 4. Requested calendar year */
                            _('The Feast \'%1$s\', usually celebrated on %2$s, coincides with another Feast \'%3$s\' in the year %4$d! Does something need to be done about this?'),
                            '<b>' . $liturgicalEvent->name . '</b>',
                            '<b>' . $this->dayAndMonth->format($liturgicalEvent->date->format('U')) . '</b>',
                            '<b>' . $value->name . '</b>',
                            $this->CalendarParams->Year
                        );
                }
                break;
            case LitGrade::SOLEMNITY:
                //there seems to be a coincidence with a different Solemnity on the same day!
                //should we attempt to move to the next open slot?
                $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> '
                    . $this->CalendarParams->NationalCalendar . ': '
                    . sprintf(
                        /**translators: 1. LiturgicalEvent name, 2. LiturgicalEvent date, 3. Coinciding liturgical event name, 4. Requested calendar year */
                        _('The Solemnity \'%1$s\', usually celebrated on %2$s, coincides with the Sunday or Solemnity \'%3$s\' in the year %4$d! Does something need to be done about this?'),
                        '<i>' . $liturgicalEvent->name . '</i>',
                        '<b>' . $this->dayAndMonth->format($liturgicalEvent->date->format('U')) . '</b>',
                        '<i>' . $this->Cal->solemnityFromDate($liturgicalEvent->date)->name . '</i>',
                        $this->CalendarParams->Year
                    );
                break;
        }
    }

    /**
     * Creates a new regional or national liturgical event and adds it to the calendar.
     *
     * @param LitCalItem $litEvent The row from the database containing the information about the liturgical event
     *
     * @return void
     */
    private function createNewRegionalOrNationalLiturgicalEvent(LitCalItem $litEvent): void
    {
        /** @var LitCalItemCreateNewFixed|LitCalItemCreateNewMobile|LitCalItemMakePatron $liturgicalEvent */
        $liturgicalEvent = $litEvent->liturgical_event;

        if (
            property_exists($liturgicalEvent, 'strtotime')
            && $liturgicalEvent->strtotime !== ''
        ) {
            /** @var LitCalItemCreateNewMobile $liturgicalEvent */
            $strtotime = $liturgicalEvent->strtotime . ' ' . $this->CalendarParams->Year;
            $liturgicalEvent->setDate(new DateTime($strtotime, new \DateTimeZone('UTC')));
        } elseif (
            property_exists($liturgicalEvent, 'month')
            && property_exists($liturgicalEvent, 'day')
        ) {
            /** @var LitCalItemCreateNewFixed $liturgicalEvent */
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $liturgicalEvent->month, $this->CalendarParams->Year);
            if ($liturgicalEvent->day > $daysInMonth) {
                $this->Messages[] = sprintf(
                    _('Cannot create liturgical event with key "%1$s": the day (%2$d) of the liturgical event is greater than the number of days (%3$d) in the month (%4$d) of the liturgical event year (%5$d).'),
                    $liturgicalEvent->event_key,
                    $liturgicalEvent->day,
                    $daysInMonth,
                    $liturgicalEvent->month,
                    $this->CalendarParams->Year
                );
            }
            $liturgicalEvent->setDate(DateTime::fromFormat("{$liturgicalEvent->day}-{$liturgicalEvent->month}-{$this->CalendarParams->Year}"));
        } else {
            ob_start();
            var_dump($litEvent);
            $a = ob_get_contents();
            ob_end_clean();
            $this->Messages[] = _('We should be creating a new liturgical event, however we do not seem to have the correct date information in order to proceed') . ' :: ' . $a;
            return;
        }

        if ($this->liturgicalEventCanBeCreated($liturgicalEvent)) {
            if ($this->liturgicalEventDoesNotCoincide($liturgicalEvent)) {
                $newLitEvent = LiturgicalEvent::fromObject($liturgicalEvent);
                $this->Cal->addLiturgicalEvent($liturgicalEvent->event_key, $newLitEvent);
            } else {
                $this->handleLiturgicalEventCreationWithCoincidence($liturgicalEvent);
            }

            // TODO: if we are only creating new liturgical events here,
            // will $litEvent->metadata ever be an instance of LitCalItemMakePatronMetadata
            // or of LitCalItemMoveEventMetadata?
            // Perhaps we can test this by adding a custom string to mark that the $infoSource was actually elaborated here?
            // If the custom string is never present, then we can remove this logic entirely.
            $infoSource = 'unknown #createNewRegionalOrNationalLiturgicalEvent';
            if ($litEvent->metadata instanceof LitCalItemMakePatronMetadata) {
                $infoSource = $litEvent->metadata->getUrl($this->CalendarParams->Locale) . ' #createNewRegionalOrNationalLiturgicalEvent';
            } elseif ($litEvent->metadata instanceof LitCalItemMoveEventMetadata) {
                $infoSource = RomanMissal::getName($litEvent->metadata->missal) . ' #createNewRegionalOrNationalLiturgicalEvent';
            }

            $locale           = LitLocale::$PRIMARY_LANGUAGE;
            $formattedDateStr = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                ? ( $liturgicalEvent->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[(int) $liturgicalEvent->date->format('n')] )
                : ( $locale === 'en'
                    ? $liturgicalEvent->date->format('F jS')
                    : $this->dayAndMonth->format($liturgicalEvent->date->format('U'))
                );
            $dateStr          = property_exists($liturgicalEvent, 'strtotime') && $liturgicalEvent->strtotime !== ''
                ? '<i>' . $liturgicalEvent->strtotime . '</i>'
                : $formattedDateStr;
            $this->Messages[] = sprintf(
                /**translators:
                 * 1. Grade or rank of the liturgical event
                 * 2. Name of the liturgical event
                 * 3. Day and month of the liturgical event
                 * 4. Year from which the liturgical event has been added
                 * 5. Source of the information
                 * 6. Requested calendar year
                 */
                _('The %1$s \'%2$s\' has been added on %3$s since the year %4$d (%5$s), applicable to the year %6$d.'),
                LitGrade::i18n($liturgicalEvent->grade, $this->CalendarParams->Locale, false),
                $liturgicalEvent->name,
                $dateStr,
                $litEvent->metadata->since_year,
                $infoSource,
                $this->CalendarParams->Year
            );
        } else {
            $locale           = LitLocale::$PRIMARY_LANGUAGE;
            $formattedDateStr = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                ? ( $liturgicalEvent->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[(int) $liturgicalEvent->date->format('n')] )
                : ( $locale === 'en'
                    ? $liturgicalEvent->date->format('F jS')
                    : $this->dayAndMonth->format($liturgicalEvent->date->format('U'))
                );
            $dateStr          = property_exists($liturgicalEvent, 'strtotime') && $liturgicalEvent->strtotime !== ''
                ? '<i>' . $liturgicalEvent->strtotime . '</i>'
                : $formattedDateStr;

            $this->Messages[] = sprintf(
                /**translators:
                 * 1. Grade or rank of the liturgical event
                 * 2. Name of the liturgical event
                 * 3. Day and month of the liturgical event
                 * 4. Requested calendar year
                 */
                _('The %1$s \'%2$s\' was not added to the calendar on %3$s because it conflicts with an existing liturgical event in the year %4$d.'),
                LitGrade::i18n($liturgicalEvent->grade, $this->CalendarParams->Locale, false),
                $liturgicalEvent->name,
                $dateStr,
                $this->CalendarParams->Year
            );
        }
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
     * @param LitCalItemCollection $litItemCollection The array of rows from the national calendar JSON file
     */
    private function handleNationalCalendarEvents(LitCalItemCollection $litItemCollection): void
    {
        foreach ($litItemCollection as $litEventItem) {
            if ($this->CalendarParams->Year >= $litEventItem->metadata->since_year) {
                // Until year is exclusive with this logic
                if ($litEventItem->metadata->until_year !== null && $this->CalendarParams->Year >= $litEventItem->metadata->until_year) {
                    continue;
                }
                /** @var LitCalItemCreateNewMetadata|LitCalItemMakePatronMetadata|LitCalItemSetPropertyGradeMetadata|LitCalItemSetPropertyNameMetadata|LitCalItemMoveEventMetadata $litEventItemMetadata */
                $litEventItemMetadata = $litEventItem->metadata;
                switch ($litEventItemMetadata->action) {
                    case CalEventAction::MakePatron:
                        /** @var LitCalItemMakePatron $liturgicalEvent  */
                        $liturgicalEvent  = $litEventItem->liturgical_event;
                        $existingLitEvent = $this->Cal->getLiturgicalEvent($liturgicalEvent->event_key);
                        if ($existingLitEvent !== null) {
                            if ($existingLitEvent->grade !== $liturgicalEvent->grade) {
                                $this->Cal->setProperty($liturgicalEvent->event_key, 'grade', $liturgicalEvent->grade);
                            }
                            $this->Cal->setProperty($liturgicalEvent->event_key, 'name', $liturgicalEvent->name);
                        } else {
                            $this->handleMissingPatronEvent($litEventItem);
                        }
                        break;
                    case CalEventAction::CreateNew:
                        $this->createNewRegionalOrNationalLiturgicalEvent($litEventItem);
                        break;
                    case CalEventAction::SetProperty:
                        /** @var LitCalItemSetPropertyGradeMetadata|LitCalItemSetPropertyNameMetadata $litEventItemMetadata  */
                        switch ($litEventItemMetadata->property) {
                            case 'name':
                                /** @var LitCalItemSetPropertyName $liturgicalEvent */
                                $liturgicalEvent         = $litEventItem->liturgical_event;
                                $existingLiturgicalEvent = $this->Cal->getLiturgicalEvent($liturgicalEvent->event_key);
                                if (null !== $existingLiturgicalEvent) {
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
                                        LitGrade::i18n($existingLiturgicalEvent->grade, $this->CalendarParams->Locale, false),
                                        $existingLiturgicalEvent->name,
                                        $liturgicalEvent->name,
                                        $this->CalendarParams->NationalCalendar,
                                        $litEventItemMetadata->since_year,
                                        $this->CalendarParams->Year
                                    );
                                    $this->Cal->setProperty($liturgicalEvent->event_key, 'name', $liturgicalEvent->name);
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
                                        $liturgicalEvent->event_key,
                                        $liturgicalEvent->name,
                                        $this->CalendarParams->NationalCalendar,
                                        $litEventItemMetadata->since_year,
                                        $this->CalendarParams->Year
                                    );
                                }
                                break;
                            case 'grade':
                                /** @var LitCalItemSetPropertyGrade $liturgicalEvent */
                                $liturgicalEvent = $litEventItem->liturgical_event;
                                /** @var LitCalItemSetPropertyGradeMetadata $metadata */
                                $metadata                = $litEventItem->metadata;
                                $existingLiturgicalEvent = $this->Cal->getLiturgicalEvent($liturgicalEvent->event_key);
                                $url                     = $metadata->url !== null ? '<a href="' . $metadata->url . '" target="_blank">' . $metadata->url . '</a>' : 'source unknown #handleNationalCalendarEvents';
                                if (null !== $existingLiturgicalEvent) {
                                    $this->Messages[] = sprintf(
                                        /**translators:
                                         * 1. Original liturgical grade
                                         * 2. Name of the liturgical event
                                         * 3. New liturgical grade
                                         * 4. ID of the national calendar
                                         * 5. Year from which the grade has been changed
                                         * 6. Source of the information
                                         * 7. Requested calendar year
                                         */
                                        _('The grade of the %1$s \'%2$s\' has been changed to \'%3$s\' in the national calendar \'%4$s\' since the year %5$d (%6$s), applicable to the year %7$d.'),
                                        LitGrade::i18n($existingLiturgicalEvent->grade, $this->CalendarParams->Locale, false),
                                        $existingLiturgicalEvent->name,
                                        LitGrade::i18n($liturgicalEvent->grade, $this->CalendarParams->Locale, false),
                                        $this->CalendarParams->NationalCalendar,
                                        $litEventItem->metadata->since_year,
                                        $url,
                                        $this->CalendarParams->Year
                                    );
                                    $this->Cal->setProperty($liturgicalEvent->event_key, 'grade', $liturgicalEvent->grade);
                                } else {
                                    $this->Messages[] = sprintf(
                                        /**translators:
                                         * 1. Event key of the liturgical event
                                         * 2. New name of the liturgical event
                                         * 3. ID of the national calendar
                                         * 4. Year from which the name has been changed
                                         * 5. Requested calendar year
                                         */
                                        _('The grade of the celebration \'%1$s\' has been changed to \'%2$s\' in the national calendar \'%3$s\' since the year %4$d (%5$s), but could not be applied to the year %6$d because the celebration was not found.'),
                                        $liturgicalEvent->event_key,
                                        LitGrade::i18n($liturgicalEvent->grade, $this->CalendarParams->Locale, false),
                                        $this->CalendarParams->NationalCalendar,
                                        $litEventItem->metadata->since_year,
                                        $url,
                                        $this->CalendarParams->Year
                                    );
                                }
                                break;
                        }
                        break;
                    case CalEventAction::MoveEvent:
                        /** @var LitCalItemMoveEvent $liturgicalEvent */
                        $liturgicalEvent = $litEventItem->liturgical_event;
                        /** @var LitCalItemMoveEventMetadata $litEventItemMetadata */
                        $existingLitEvent = $this->Cal->getLiturgicalEvent($liturgicalEvent->event_key);
                        $litEventNewDate  = DateTime::fromFormat($liturgicalEvent->day . '-' . $liturgicalEvent->month . '-' . $this->CalendarParams->Year);
                        if (self::dateIsNotSunday($litEventNewDate) && $this->Cal->notInSolemnitiesFeastsOrMemorials($litEventNewDate)) {
                            if (null === $existingLitEvent) {
                                if ($this->Cal->isSuppressed($liturgicalEvent->event_key)) {
                                    $existingLitEvent = $this->Cal->getSuppressedEventByKey($liturgicalEvent->event_key);
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
                                        LitGrade::i18n($existingLitEvent->grade, $this->CalendarParams->Locale, false),
                                        $existingLitEvent->name,
                                        $this->dayAndMonth->format($existingLitEvent->date->format('U')),
                                        $this->dayAndMonth->format($litEventNewDate->format('U')),
                                        $litEventItemMetadata->since_year,
                                        $this->CalendarParams->NationalCalendar,
                                        $this->CalendarParams->Year
                                    );
                                    $this->moveLiturgicalEventDate($liturgicalEvent->event_key, $litEventNewDate, $litEventItemMetadata->reason, $litEventItemMetadata->missal);
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
                                        $liturgicalEvent->name,
                                        $this->dayAndMonth->format($litEventNewDate->format('U')),
                                        $litEventItemMetadata->since_year,
                                        $this->CalendarParams->NationalCalendar,
                                        $this->CalendarParams->Year
                                    );
                                }
                            }
                        } else {
                            if (null !== $existingLitEvent) {
                                $this->Messages[] = sprintf(
                                    /**translators:
                                     * 1. event_key of the liturgical event
                                     * 2. New date of the liturgical event
                                     * 3. Year from which the date has been changed
                                     * 4. ID of the national calendar
                                     * 5. Requested calendar year
                                     */
                                    _('The liturgical event \'%1$s\' has been moved to %2$s since the year %3$d in the national calendar \'%4$s\', but this could not take place in the year %5$d since the new date %2$s seems to be a Sunday or a liturgical event of greater rank.'),
                                    $litEventItem->liturgical_event->event_key,
                                    $this->dayAndMonth->format($litEventNewDate->format('U')),
                                    $litEventItem->metadata->since_year,
                                    $this->CalendarParams->NationalCalendar,
                                    $this->CalendarParams->Year
                                );
                                $this->Cal->removeLiturgicalEvent($litEventItem->liturgical_event->event_key);
                            }
                        }
                        break;
                }
            }
        }
    }

    /**
     * Applies any national calendar celebrations from the national calendar JSON file,
     * including any data from a wider region that the national calendar might belong to,
     * and any language edition Roman Missals proper to the national calendar
     *
     * @return void
     */
    private function applyNationalCalendar(): void
    {
        // Apply any new celebrations that the National Calendar introduces via it's Missals
        $requiredProps = ['calendar', 'day', 'month', 'name', 'color', 'grade', 'common', 'grade_display', 'event_key'];
        if ($this->NationalData !== null) {
            if (count($this->NationalData->metadata->missals) === 0) {
                $this->Messages[] = 'Did not find any Missals for region ' . $this->NationalData->metadata->nation;
            } else {
                // Since we have no way of localizing the country name in Latin, we'll just use English for now
                $countryName      = LitLocale::LATIN_PRIMARY_LANGUAGE === LitLocale::$PRIMARY_LANGUAGE
                                    ? \Locale::getDisplayRegion('-' . $this->NationalData->metadata->nation, 'en')
                                    : \Locale::getDisplayRegion('-' . $this->NationalData->metadata->nation, $this->CalendarParams->Locale);
                $this->Messages[] = sprintf(
                    /**translators: 1. Country name, 2. List of missals */
                    _('Found Missals for region %1$s: %2$s.'),
                    $countryName,
                    implode(', ', $this->NationalData->metadata->missals)
                );

                // If the national calendar has any proper language edition Roman Missals,
                // we get the sanctorale data for those Missals (if defined)
                foreach ($this->NationalData->metadata->missals as $missal) {
                    $yearLimits = RomanMissal::getYearLimits($missal);

                    // Skip missals that only apply to years after the current requested year
                    if ($this->CalendarParams->Year < $yearLimits['since_year']) {
                        continue;
                    }

                    // Skip language edition Roman Missals that no longer apply to the current requested year
                    // (until_year is exclusive, e.g. 'until 1983' means that if 1983 is requested, the Missal will no longer apply)
                    if (array_key_exists('until_year', $yearLimits) && $this->CalendarParams->Year >= $yearLimits['until_year']) {
                        continue;
                    }

                    if (RomanMissal::getSanctoraleFileName($missal) !== false) {
                        $this->Messages[] = sprintf(
                            /**translators: Name of the Roman Missal */
                            _('Found a sanctorale data file for %s'),
                            RomanMissal::getName($missal)
                        );

                        $this->loadPropriumDeSanctisData($missal);

                        foreach ($this->missalsMap[$missal] as $propriumDeSanctisEvent) {
                            $keys        = array_keys(get_object_vars($propriumDeSanctisEvent));
                            $missingKeys = array_diff($requiredProps, $keys);
                            if (count($missingKeys) > 0) {
                                self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, sprintf(
                                    'The sanctorale data file for the %1$s does not contain the required fields to create a liturgical event: missing keys %2$s in event %3$s.',
                                    RomanMissal::getName($missal),
                                    implode(', ', $missingKeys),
                                    json_encode($propriumDeSanctisEvent)
                                ));
                            }
                            $currentLitEventDate = DateTime::fromFormat($propriumDeSanctisEvent->day . '-' . $propriumDeSanctisEvent->month . '-' . $this->CalendarParams->Year);
                            if (!$this->Cal->inSolemnitiesOrFeasts($currentLitEventDate)) {
                                $propriumDeSanctisEvent->setName("[ {$this->NationalData->metadata->nation} ] " . $propriumDeSanctisEvent->name);
                                $propriumDeSanctisEvent->setDate($currentLitEventDate);
                                //$propriumDeSanctisEvent->type = LitEventType::FIXED;
                                $litEvent = LiturgicalEvent::fromObject($propriumDeSanctisEvent);
                                $this->Cal->addLiturgicalEvent($propriumDeSanctisEvent->event_key, $litEvent);
                            } else {
                                if (self::dateIsSunday($currentLitEventDate) && $propriumDeSanctisEvent->event_key === 'PrayerUnborn') {
                                    $propriumDeSanctisEvent->setName('[ USA ] ' . $propriumDeSanctisEvent->name);
                                    $propriumDeSanctisEvent->setDate($currentLitEventDate->add(new \DateInterval('P1D')));
                                    //$propriumDeSanctisEvent->type = LitEventType::FIXED;
                                    $litEvent = LiturgicalEvent::fromObject($propriumDeSanctisEvent);
                                    $this->Cal->addLiturgicalEvent($propriumDeSanctisEvent->event_key, $litEvent);
                                    $this->Messages[] = sprintf(
                                        'USA: The National Day of Prayer for the Unborn is set to Jan 22 as per the 2011 Roman Missal issued by the USCCB, however since it coincides with a Sunday or a Solemnity in the year %d, it has been moved to Jan 23',
                                        $this->CalendarParams->Year
                                    );
                                } else {
                                    $coincidingLiturgicalEvent = $this->Cal->determineSundaySolemnityOrFeast($currentLitEventDate, $propriumDeSanctisEvent->event_key);
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
                                        LitGrade::i18n($propriumDeSanctisEvent->grade, $this->CalendarParams->Locale, false),
                                        '<i>' . $propriumDeSanctisEvent->name . '</i>',
                                        $this->dayAndMonth->format($currentLitEventDate->format('U')),
                                        RomanMissal::getName($missal),
                                        $coincidingLiturgicalEvent->grade_lcl,
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

        // Apply any actions that modify celebrations from the General Roman Calendar for the Wider Region (such as Europe, or Americas)
        if ($this->WiderRegionData !== null && property_exists($this->WiderRegionData, 'litcal')) {
            $this->handleNationalCalendarEvents($this->WiderRegionData->litcal);
        }

        // Apply any actions that modify celebrations from the General Roman Calendar for the National Calendar
        if ($this->NationalData !== null && property_exists($this->NationalData, 'litcal')) {
            $this->handleNationalCalendarEvents($this->NationalData->litcal);
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
        $newDateStr = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
            ? ( $newDate->format('j') . ' ' . LatinUtils::LATIN_MONTHS[(int) $newDate->format('n')] )
            : ( $locale === 'en'
                ? $newDate->format('F jS')
                : $this->dayAndMonth->format($newDate->format('U'))
            );

        if (!$this->Cal->inSolemnitiesFeastsOrMemorials($newDate)) {
            $oldDateStr = '';
            // If the liturgical event exists, we can simply move it to the new date
            // If it does not exist, we should recreate it on the new date
            if ($litEvent !== null) {
                $oldDateStr = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? ( $litEvent->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[(int) $litEvent->date->format('n')] )
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
                        $suppressedEvent       = $this->Cal->getSuppressedEventByKey($event_key);
                        $suppressedEvent->date = $newDate;
                        $suppressedEvent->type = LitEventType::FIXED;
                        $this->Cal->addLiturgicalEvent($event_key, $suppressedEvent);
                        // if it was suppressed previously (which it should have been), we should remove from the suppressed events collection
                        $this->Cal->reinstateEvent($event_key);
                        $oldDate    = $suppressedEvent->date;
                        $oldDateStr = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                            ? ( $oldDate->format('j') . ' ' . LatinUtils::LATIN_MONTHS[(int) $oldDate->format('n')] )
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
                    LitGrade::i18n($litEvent->grade, $this->CalendarParams->Locale),
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
                $oldDateStr = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
                    ? ( $litEvent->date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[(int) $litEvent->date->format('n')] )
                    : ( $locale === 'en'
                        ? $litEvent->date->format('F jS')
                        : $this->dayAndMonth->format($litEvent->date->format('U'))
                    );

                $coincidingLiturgicalEvent = $this->Cal->determineSundaySolemnityOrFeast($newDate, $event_key);
                //If the new date is already covered by a Solemnity, Feast or Memorial, then we can't move the celebration, so we simply suppress it
                $this->Messages[] = sprintf(
                    /**translators: 1. LiturgicalEvent grade, 2. LiturgicalEvent name, 3. Old date, 4. New date, 5. Source of the information, 6. New liturgical event name, 7. Superseding liturgical event grade, 8. Superseding liturgical event name, 9: Requested calendar year */
                    _('The %1$s \'%2$s\' would have been transferred from %3$s to %4$s as per the %5$s, to make room for \'%6$s\', however it is suppressed by the %7$s \'%8$s\' in the year %9$d.'),
                    LitGrade::i18n($litEvent->grade, $this->CalendarParams->Locale),
                    '<i>' . $litEvent->name . '</i>',
                    $oldDateStr,
                    $newDateStr,
                    RomanMissal::getName($missal),
                    '<i>' . $inFavorOf . '</i>',
                    $coincidingLiturgicalEvent->grade_lcl,
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
        return (int) $dt->format('N') === 7;
    }

    /**
     * Returns true if the given date is not a Sunday.
     *
     * @param DateTime $dt The date to check.
     * @return bool True if the given date is not a Sunday, false otherwise.
     */
    private static function dateIsNotSunday(DateTime $dt): bool
    {
        return (int) $dt->format('N') !== 7;
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
        //these will be dealt with later when loading Local Calendar Data, {@see \LiturgicalCalendar\Api\PAths\CalendarPath::applyNationalCalendarData()}

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
        $this->createDoctorsFromDecrees();

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
     * Handles a mobile liturgical event whose date is relative to another liturgical event
     *
     * The strtotime object must have the following properties:
     *  - day_of_the_week (e.g. 'monday', 'tuesday', etc.)
     *  - relative_time (either 'before' or 'after')
     *  - event_key (the key of the liturgical event that this mobile liturgical event is relative to)
     *
     * If the strtotime object is invalid, or if the liturgical event that it is relative to does not exist,
     * an error message will be added to the Messages array and false will be returned.
     *
     * @param DiocesanLitCalItemCreateNewMobile $mobileLiturgicalEvent the unit of data for the mobile liturgical event from the JSON file
     * @return DateTime|false the date of the mobile liturgical event, or false if there was an error
     */
    private function handleMobileEventRelativeToLiturgicalEvent(DiocesanLitCalItemCreateNewMobile $mobileLiturgicalEvent): DateTime|false
    {
        /** @var RelativeLiturgicalDate $strtotime */
        $strtotime = $mobileLiturgicalEvent->strtotime;

        $relativeToLiturgicalEvent = $this->Cal->getLiturgicalEvent($strtotime->event_key);
        if (null === $relativeToLiturgicalEvent) {
            $this->Messages[] = sprintf(
                /**translators: 1. Name of the mobile liturgical event being created, 2. Name of the liturgical event that it is relative to */
                _('Cannot create mobile liturgical event \'%1$s\' relative to liturgical event with key \'%2$s\', liturgical event does not exist.'),
                $mobileLiturgicalEvent->name,
                $strtotime->event_key
            );
            return false;
        }

        $relativeToDate = clone($relativeToLiturgicalEvent->date);
        switch ($strtotime->relative_time) {
            case DateRelation::Before:
                return $relativeToDate->modify("previous {$strtotime->day_of_the_week}");
            case DateRelation::After:
                return $relativeToDate->modify("next {$strtotime->day_of_the_week}");
            default:
                $this->Messages[] = sprintf(
                    /**translators: 1. Name of the mobile liturgical event being created, 2. event_key of the liturgical event that it is relative to, 3. List of valid keywords */
                    _('Cannot create mobile liturgical event \'%1$s\': the `strtotime.relative_time` property can only express relativity to the liturgical event with key \'%2$s\' using keywords %3$s.'),
                    $mobileLiturgicalEvent->name,
                    $strtotime->event_key,
                    implode(', ', DateRelation::values())
                );
                return false;
        }
    }


    /**
     * Handle a mobile liturgical event whose date is specified using the "strtotime" property
     *
     * The "strtotime" property should generally be a string that can be interpreted by PHP's strtotime function.
     * Since however PHP's strtotime function does not handle strings such as "wednesday after March 12",
     * we create our own logic to parse this kind of string and handle it accordingly.
     *
     * @param DiocesanLitCalItemCreateNewMobile $mobileLiturgicalEvent the row containing data for the mobile liturgical event from the JSON file
     * @return DateTime the date of the mobile liturgical event, or false if there was an error
     */
    private function handleMobileEventRelativeToCalendarDate(DiocesanLitCalItemCreateNewMobile $mobileLiturgicalEvent): DateTime|false
    {
        /** @var string $strtotime */
        $strtotime = $mobileLiturgicalEvent->strtotime;

        if (preg_match('/(before|after)/', $strtotime)) {
            // Example: "wednesday after March 12" for the Miracle of the Holy Eucharist in Amsterdam
            $match = preg_split('/(before|after)/', $strtotime, -1, PREG_SPLIT_DELIM_CAPTURE);
            if (false === $match || count($match) !== 3) {
                $this->Messages[] = sprintf(
                    /**translators: Do not translate 'strtotime'. 1. The value of the 'strtotime' property, 2. The result of preg_split */
                    'Could not interpret the \'strtotime\' property with value %1$s into a timestamp. Splitting failed: %2$s',
                    $strtotime,
                    json_encode($match)
                );
                return false;
            }

            $mobileEventDate = new DateTime($match[2] . ' ' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
            if ($match[1] === DateRelation::Before->value) {
                $mobileEventDate->modify("previous {$match[0]}");
            } elseif ($match[1] === DateRelation::After->value) {
                $mobileEventDate->modify("next {$match[0]}");
            }
            return $mobileEventDate;
        } else {
            // Example: "fourth thursday of November" (for Thanksgiving in the United States)
            return new DateTime($strtotime . ' ' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
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
     * @param DiocesanLitCalItemCreateNewMobile $liturgicalEvent An item from a collection of liturgical events, containing data for the mobile liturgical event from the JSON file
     * @return DateTime|false The date of the mobile liturgical event, or false if there was an error
     */
    private function interpretStrtotime(DiocesanLitCalItemCreateNewMobile $liturgicalEvent): DateTime|false
    {
        if ($liturgicalEvent->strtotime instanceof RelativeLiturgicalDate) {
            return $this->handleMobileEventRelativeToLiturgicalEvent($liturgicalEvent);
        } elseif (gettype($liturgicalEvent->strtotime) === 'string') {
            return $this->handleMobileEventRelativeToCalendarDate($liturgicalEvent);
        } else {
            $this->Messages[] = sprintf(
                /**translators: Do not translate 'strtotime'! 1. Name of the mobile liturgical event being created */
                _('Cannot create mobile liturgical event \'%1$s\': \'strtotime\' property must be either an object or a string, instead it is of type \'%2$s\'.'),
                $liturgicalEvent->name,
                gettype($liturgicalEvent->strtotime)
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
        foreach ($this->DiocesanData->litcal as $litCalItem) {
            // If sinceYear is undefined or null or empty, let's go ahead and create the event in any case.
            // Creation will be restricted only if explicitly defined by the sinceYear property.
            if (
                (
                    $litCalItem->metadata->since_year === null
                    || $litCalItem->metadata->since_year === 0
                    || $this->CalendarParams->Year >= $litCalItem->metadata->since_year
                )
                &&
                (
                    $litCalItem->metadata->until_year === null
                    || $litCalItem->metadata->until_year === 0
                    || $this->CalendarParams->Year <= $litCalItem->metadata->until_year
                )
            ) {
                if ($litCalItem->liturgical_event->isMobile()) {
                    /** @var DiocesanLitCalItemCreateNewMobile $liturgicalEvent */
                    $liturgicalEvent     = $litCalItem->liturgical_event;
                    $currentLitEventDate = $this->interpretStrtotime($liturgicalEvent);
                } else {
                    /** @var DiocesanLitCalItemCreateNewFixed $liturgicalEvent */
                    $liturgicalEvent     = $litCalItem->liturgical_event;
                    $currentLitEventDate = DateTime::fromFormat($liturgicalEvent->day . '-' . $liturgicalEvent->month . '-' . $this->CalendarParams->Year);
                }
                if ($currentLitEventDate !== false) {
                    $liturgicalEvent->setDate($currentLitEventDate);
                    if ($liturgicalEvent->grade->value > LitGrade::FEAST->value) {
                        if ($this->Cal->inSolemnities($currentLitEventDate) && $liturgicalEvent->event_key !== $this->Cal->solemnityKeyFromDate($currentLitEventDate)) {
                            // There seems to be a coincidence with a different Solemnity on the same day!
                            // Should we attempt to move to the next open slot?
                            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> '
                                . $this->CalendarParams->DiocesanCalendar . ': '
                                .  sprintf(
                                    /**translators: 1: LiturgicalEvent name, 2: Name of the diocese, 3: LiturgicalEvent date, 4: Coinciding liturgical event name, 5: Requested calendar year */
                                    _('The Solemnity \'%1$s\', proper to the calendar of the %2$s and usually celebrated on %3$s, coincides with the Sunday or Solemnity \'%4$s\' in the year %5$d! Does something need to be done about this?'),
                                    '<i>' . $liturgicalEvent->name . '</i>',
                                    $this->DioceseName,
                                    '<b>' . $this->dayAndMonth->format($currentLitEventDate->format('U')) . '</b>',
                                    '<i>' . $this->Cal->solemnityFromDate($currentLitEventDate)->name . '</i>',
                                    $this->CalendarParams->Year
                                );
                        }
                        $liturgicalEvent->name = '[ ' . $this->DioceseName . ' ] ' . $liturgicalEvent->name;
                        //$liturgicalEvent->type = LitEventType::FIXED;
                        $this->Cal->addLiturgicalEvent(
                            $this->CalendarParams->DiocesanCalendar . '_' . $liturgicalEvent->event_key,
                            LiturgicalEvent::fromObject($liturgicalEvent)
                        );
                    } elseif ($liturgicalEvent->grade->value <= LitGrade::FEAST->value && $this->Cal->notInSolemnities($currentLitEventDate) && $this->Cal->dateIsNotSunday($currentLitEventDate)) {
                        $liturgicalEvent->name = '[ ' . $this->DioceseName . ' ] ' . $liturgicalEvent->name;
                        //$liturgicalEvent->type = LitEventType::FIXED;
                        $this->Cal->addLiturgicalEvent(
                            $this->CalendarParams->DiocesanCalendar . '_' . $liturgicalEvent->event_key,
                            LiturgicalEvent::fromObject($liturgicalEvent)
                        );
                    } else {
                        $coincidingEvent  = $this->Cal->determineSundaySolemnityOrFeast($currentLitEventDate, $liturgicalEvent->event_key);
                        $this->Messages[] = $this->CalendarParams->DiocesanCalendar . ': ' . sprintf(
                            /**translators: 1: LiturgicalEvent grade, 2: LiturgicalEvent name, 3: Name of the diocese, 4: LiturgicalEvent date, 5: Coinciding liturgical event name, 6: Requested calendar year */
                            _('The %1$s \'%2$s\', proper to the calendar of the %3$s and usually celebrated on %4$s, is suppressed by the %5$s %6$s in the year %7$d.'),
                            LitGrade::i18n($liturgicalEvent->grade, $this->CalendarParams->Locale, false),
                            '<i>' . $liturgicalEvent->name . '</i>',
                            $this->DioceseName,
                            '<b>' . $this->dayAndMonth->format($currentLitEventDate->format('U')) . '</b>',
                            $coincidingEvent->grade_lcl,
                            '<i>' . $coincidingEvent->event->name . '</i>',
                            $this->CalendarParams->Year
                        );
                    }
                } else {
                    throw new \Exception('Unable to create DateTime object for LiturgicalEvent: ' . $liturgicalEvent->name);
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
            $GitHubReleasesObj = Utilities::jsonFileToObject($ghReleaseCacheFile);
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
            } else {
                /** @var string $ghCurrentReleaseInfo */
                $GitHubReleasesObj = json_decode($ghCurrentReleaseInfo);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $returnObj->status  = 'error';
                    $returnObj->message = json_last_error_msg();
                } else {
                    /** @var \stdClass $GitHubReleasesObj */
                    $returnObj->status    = 'success';
                    $returnObj->obj       = $GitHubReleasesObj;
                    $GitHubReleaseEncoded = json_encode($GitHubReleasesObj, JSON_PRETTY_PRINT);
                    if (false === $GitHubReleaseEncoded) {
                        throw new \Exception('Could not re-encode JSON object');
                    }
                    file_put_contents($ghReleaseCacheFile, $GitHubReleaseEncoded);
                }
            }

            curl_close($ch);
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

        /** @var LiturgicalEvent $liturgicalEvent */
        foreach ($SerializeableLitCal->litcal as $liturgicalEvent) {
            $displayGrade     = '';
            $displayGradeHTML = '';

            if (property_exists($liturgicalEvent, 'grade_display') && $liturgicalEvent->grade_display !== null) {
                $displayGrade = $liturgicalEvent->grade_display;
            }

            if (property_exists($liturgicalEvent, 'event_key') && ( $liturgicalEvent->event_key === 'DedicationLateran' || $liturgicalEvent->event_key === 'DedicationLateran_vigil' )) {
                $displayGradeHTML = LitGrade::i18n(LitGrade::FEAST, $this->CalendarParams->Locale, true);
            } elseif ($liturgicalEvent->grade_display === null) {
                $displayGradeHTML = LitGrade::i18n($liturgicalEvent->grade, $this->CalendarParams->Locale, true);
            } else {
                if ($liturgicalEvent->grade_display === '') {
                    $displayGradeHTML = '';
                } elseif ($liturgicalEvent->grade->value >= LitGrade::FEAST->value) {
                    $displayGradeHTML = '<B>' . $liturgicalEvent->grade_display . '</B>';
                } else {
                    $displayGradeHTML = $liturgicalEvent->grade_display;
                }
            }

            $description  = $liturgicalEvent->getCommonLcl();
            $description .=  '\n' . $displayGrade;
            $description .= ( count($liturgicalEvent->color) > 0 )
                ? '\n' . Utilities::parseColorToString($liturgicalEvent->color, $this->CalendarParams->Locale, false)
                : '';
            $description .= (
                    isset($liturgicalEvent->liturgical_year) // no need to check for null value, isset will fail for a null value
                    && $liturgicalEvent->liturgical_year !== ''
                )
                ? '\n' . $liturgicalEvent->liturgical_year
                : '';

            $htmlDescription  = '<P DIR=LTR>' . $liturgicalEvent->getCommonLcl();
            $htmlDescription .=  '<BR>' . $displayGradeHTML;
            $htmlDescription .= ( count($liturgicalEvent->color) > 0 )
                ? '<BR>' . Utilities::parseColorToString($liturgicalEvent->color, $this->CalendarParams->Locale, true)
                : '';
            $htmlDescription .= (
                    isset($liturgicalEvent->liturgical_year) // no need to check for null value, isset will fail for a null value
                    && $liturgicalEvent->liturgical_year != ''
                )
                ? '<BR>' . $liturgicalEvent->liturgical_year . '</P>'
                : '</P>';

            $ical .= "BEGIN:VEVENT\r\n";
            $ical .= "CLASS:PUBLIC\r\n";

            /** @var string $publishDate */
            $publishDate = $GitHubReleasesObj->published_at;

            $ical .= 'DTSTART;VALUE=DATE:' . $liturgicalEvent->date->format('Ymd') . "\r\n";// . "T" . $liturgicalEvent->date->format( 'His' ) . "Z\r\n";
            //$liturgicalEvent->date->add( new \DateInterval( 'P1D' ) );
            //$ical .= "DTEND:" . $liturgicalEvent->date->format( 'Ymd' ) . "T" . $liturgicalEvent->date->format( 'His' ) . "Z\r\n";
            $ical .= 'DTSTAMP:' . date('Ymd') . 'T' . date('His') . "Z\r\n";
            /** The event created in the calendar is specific to this year, next year it may be different.
             *  So UID must take into account the year
             *  Next year's event should not cancel this year's event, they are different events.
             *  We will never have the same event_key twice in the same year.
             **/
            $ical .= 'UID:' . md5('LITCAL-' . $liturgicalEvent->event_key . '-' . $liturgicalEvent->date->format('Y')) . "\r\n";
            $ical .= 'CREATED:' . str_replace(':', '', str_replace('-', '', $publishDate)) . "\r\n";

            $desc  = 'DESCRIPTION:' . str_replace(',', '\,', $description);
            $ical .= strlen($desc) > 75 ? rtrim(chunk_split($desc, 71, "\r\n\t")) . "\r\n" : "$desc\r\n";
            $ical .= 'LAST-MODIFIED:' . str_replace(':', '', str_replace('-', '', $publishDate)) . "\r\n";

            $summaryLang = ';LANGUAGE=' . strtolower(preg_replace('/_/', '-', $this->CalendarParams->Locale));
            $summary     = 'SUMMARY' . $summaryLang . ':' . str_replace(',', '\,', str_replace("\r\n", ' ', $liturgicalEvent->name));

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

        $SerializeableLitCal->metadata                            = new \stdClass();
        $SerializeableLitCal->metadata->version                   = self::API_VERSION;
        $SerializeableLitCal->metadata->timestamp                 = time();
        $SerializeableLitCal->metadata->date_time                 = date(DATE_ATOM);
        $SerializeableLitCal->metadata->request_headers           = self::$Core->getRequestHeaders();
        $SerializeableLitCal->metadata->solemnities_lord_bvm      = $this->Cal->getSolemnitiesLordBVM();
        $SerializeableLitCal->metadata->solemnities_lord_bvm_keys = $this->Cal->getSolemnitiesLordBVMKeys();
        $SerializeableLitCal->metadata->solemnities               = $this->Cal->getSolemnities();
        $SerializeableLitCal->metadata->solemnities_keys          = $this->Cal->getSolemnitiesKeys();
        $SerializeableLitCal->metadata->feasts_lord               = $this->Cal->getFeastsLord();
        $SerializeableLitCal->metadata->feasts_lord_keys          = $this->Cal->getFeastsLordKeys();
        $SerializeableLitCal->metadata->feasts                    = $this->Cal->getFeasts();
        $SerializeableLitCal->metadata->feasts_keys               = $this->Cal->getFeastsKeys();
        $SerializeableLitCal->metadata->memorials                 = $this->Cal->getMemorials();
        $SerializeableLitCal->metadata->memorials_keys            = $this->Cal->getMemorialsKeys();
        $SerializeableLitCal->metadata->suppressed_events         = $this->Cal->getSuppressedEvents();
        $SerializeableLitCal->metadata->suppressed_events_keys    = $this->Cal->getSuppressedKeys();
        $SerializeableLitCal->metadata->reinstated_events         = $this->Cal->getReinstatedEvents();
        $SerializeableLitCal->metadata->reinstated_events_keys    = $this->Cal->getReinstatedKeys();
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
                $ns             = 'http://www.bibleget.io/catholicliturgy';
                $schemaLocation = API_BASE_PATH . '/' . JsonData::SCHEMAS_FOLDER . '/LiturgicalCalendar.xsd';
                $xml            = new \SimpleXMLElement(
                    '<?xml version="1.0" encoding="UTF-8"?' . '><LiturgicalCalendar xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
                    . " xsi:schemaLocation=\"$ns $schemaLocation\""
                    . " xmlns=\"$ns\"/>"
                );

                $jsonArr = Utilities::objectToArray($SerializeableLitCal);
                Utilities::convertArray2XML($jsonArr, $xml);
                $rawXML = $xml->asXML(); //this gives us non pretty XML, basically a single long string
                if (false === $rawXML) {
                    $message = _('Error converting Liturgical Calendar to XML.');
                    self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
                }

                // finally let's pretty print the XML to make the cached file more readable
                /** @var string $rawXML */
                $dom                     = new \DOMDocument();
                $dom->preserveWhiteSpace = false;
                $dom->formatOutput       = true;
                $dom->loadXML($rawXML);

                // in the response we return the pretty printed version
                $response = $dom->saveXML();
                break;
            case ReturnType::YAML:
                $jsonArr  = Utilities::objectToArray($SerializeableLitCal);
                $response = yaml_emit($jsonArr, YAML_UTF8_ENCODING);
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

        if (false === $response) {
            $message = _('Error serializing Liturgical Calendar.');
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
        }

        /**
         * at this point we are sure that the response is not null, and is not false
         * @var string $response
         */

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
            header($_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified');
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

        $this->Cal = new LiturgicalEventCollection($this->CalendarParams);
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
     * @param array<ReturnType> $returnTypes The return types to allow.
     */
    public function setAllowedReturnTypes(array $returnTypes = [ReturnType::JSON]): void
    {
        if (false === empty($this->AllowedReturnTypes)) {
            return; //if we already have the allowed return types, do not change them
        } else {
            $this->AllowedReturnTypes = array_values(array_filter(
                ReturnType::cases(),
                fn (ReturnType $returnType) => in_array($returnType, $returnTypes)
            ));
        }
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
                JsonData::DIOCESAN_CALENDAR_I18N_FILE,
                [
                    '{nation}'  => $this->CalendarParams->NationalCalendar,
                    '{diocese}' => $this->CalendarParams->DiocesanCalendar,
                    '{locale}'  => $this->CalendarParams->Locale
                ]
            );

            $DiocesanDataI18nJson = Utilities::jsonFileToArray($DiocesanDataI18nFile);
            if (array_filter(array_keys($DiocesanDataI18nJson), 'is_string') !== array_keys($DiocesanDataI18nJson)) {
                throw new \Exception('We expected all the keys of the array to be strings.');
            }
            if (array_filter($DiocesanDataI18nJson, 'is_string') !== $DiocesanDataI18nJson) {
                throw new \Exception('We expected all the values of the array to be strings.');
            }
            /** @var array<string,string> $DiocesanDataI18nJson */
            $this->DiocesanData->setNames($DiocesanDataI18nJson);

            $diocesanLectionaryFile = strtr(
                JsonData::DIOCESAN_CALENDAR_LECTIONARY_FILE,
                [
                    '{nation}'  => $this->CalendarParams->NationalCalendar,
                    '{diocese}' => $this->CalendarParams->DiocesanCalendar,
                    '{locale}'  => $this->CalendarParams->Locale
                ]
            );

            if (file_exists($diocesanLectionaryFile) && is_readable($diocesanLectionaryFile)) {
                $this->Cal::$lectionary->addSanctoraleReadingsFromFile($diocesanLectionaryFile);
            }
        }

        if ($this->CalendarParams->NationalCalendar !== null && $this->NationalData !== null) {
            $NationalDataI18nFile = strtr(
                JsonData::NATIONAL_CALENDAR_I18N_FILE,
                [
                    '{nation}' => $this->CalendarParams->NationalCalendar,
                    '{locale}' => $this->CalendarParams->Locale
                ]
            );

            $NationalDataI18nJson = Utilities::jsonFileToArray($NationalDataI18nFile);
            if (array_filter(array_keys($NationalDataI18nJson), 'is_string') !== array_keys($NationalDataI18nJson)) {
                throw new \Exception('We expected all the keys of the array to be strings.');
            }
            if (array_filter($NationalDataI18nJson, 'is_string') !== $NationalDataI18nJson) {
                throw new \Exception('We expected all the values of the array to be strings.');
            }
            /** @var array<string,string> $NationalDataI18nJson */
            $this->NationalData->setNames($NationalDataI18nJson);

            $nationalLectionaryFile = strtr(
                JsonData::NATIONAL_CALENDAR_LECTIONARY_FILE,
                [
                    '{nation}' => $this->CalendarParams->NationalCalendar,
                    '{locale}' => $this->CalendarParams->Locale
                ]
            );

            if (file_exists($nationalLectionaryFile) && is_readable($nationalLectionaryFile)) {
                $this->Cal::$lectionary->addSanctoraleReadingsFromFile($nationalLectionaryFile);
            }
        }

        if ($this->WiderRegionData !== null && property_exists($this->WiderRegionData, 'litcal')) {
            $WiderRegionDataI18nFile = strtr(
                JsonData::WIDER_REGION_I18N_FILE,
                [
                    '{wider_region}' => $this->NationalData->metadata->wider_region,
                    '{locale}'       => $this->CalendarParams->Locale
                ]
            );

            $WiderRegionDataI18nJson = Utilities::jsonFileToArray($WiderRegionDataI18nFile);
            if (array_filter(array_keys($WiderRegionDataI18nJson), 'is_string') !== array_keys($WiderRegionDataI18nJson)) {
                throw new \Exception('We expected all the keys of the array to be strings.');
            }
            if (array_filter($WiderRegionDataI18nJson, 'is_string') !== $WiderRegionDataI18nJson) {
                throw new \Exception('We expected all the values of the array to be strings.');
            }
            /** @var array<string,string> $WiderRegionDataI18nJson */
            $this->WiderRegionData->setNames($WiderRegionDataI18nJson);

            $widerRegionLectionaryFile = strtr(
                JsonData::NATIONAL_CALENDAR_LECTIONARY_FILE,
                [
                    '{nation}' => $this->CalendarParams->NationalCalendar,
                    '{locale}' => $this->CalendarParams->Locale
                ]
            );

            if (file_exists($widerRegionLectionaryFile) && is_readable($widerRegionLectionaryFile)) {
                $this->Cal::$lectionary->addSanctoraleReadingsFromFile($widerRegionLectionaryFile);
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
        self::$Core->setResponseContentTypeHeader();
        $this->CachePath = 'engineCache/v' . str_replace('.', '_', self::API_VERSION) . '/';

        if (false === Router::isLocalhost() && $this->cacheFileIsAvailable()) {
            //If we already have done the calculation
            //and stored the results in a cache file
            //then we're done, just output this and die
            //or better, make the client use it's own cache copy!
            $response = file_get_contents($this->CacheFile);
            if ($response === false) {
                throw new \Exception('Could not read file: ' . $this->CacheFile);
            }
            $responseHash  = md5($response);
            $this->endTime = hrtime(true);
            $executionTime = $this->endTime - $this->startTime;
            header('X-LitCal-Starttime: ' . $this->startTime);
            header('X-LitCal-Endtime: ' . $this->endTime);
            header('X-LitCal-Executiontime: ' . $executionTime);

            header("Etag: \"{$responseHash}\"");
            if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $responseHash) {
                header($_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified');
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
            LiturgicalEvent::setLocale($this->CalendarParams->Locale === LitLocale::LATIN ? LitLocale::LATIN_PRIMARY_LANGUAGE : $this->CalendarParams->Locale);

            $this->calculateUniversalCalendar();

            // Prepare the localization data for national and diocesan calendars, if applicable
            $this->applyCalendarI18nData();

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
                $this->Cal->merge($CalBackup);

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
