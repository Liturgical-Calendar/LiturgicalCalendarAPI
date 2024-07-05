<?php

namespace Johnrdorazio\LitCal\Paths;

use Johnrdorazio\LitCal\APICore;
use Johnrdorazio\LitCal\DateTime;
use Johnrdorazio\LitCal\Festivity;
use Johnrdorazio\LitCal\FestivityCollection;
use Johnrdorazio\LitCal\LitFunc;
use Johnrdorazio\LitCal\LitMessages;
use Johnrdorazio\LitCal\Enum\Ascension;
use Johnrdorazio\LitCal\Enum\CorpusChristi;
use Johnrdorazio\LitCal\Enum\Epiphany;
use Johnrdorazio\LitCal\Enum\AcceptHeader;
use Johnrdorazio\LitCal\Enum\CacheDuration;
use Johnrdorazio\LitCal\Enum\CalendarType;
use Johnrdorazio\LitCal\Enum\LitColor;
use Johnrdorazio\LitCal\Enum\LitCommon;
use Johnrdorazio\LitCal\Enum\LitFeastType;
use Johnrdorazio\LitCal\Enum\LitGrade;
use Johnrdorazio\LitCal\Enum\LitLocale;
use Johnrdorazio\LitCal\Enum\RequestContentType;
use Johnrdorazio\LitCal\Enum\RequestMethod;
use Johnrdorazio\LitCal\Enum\ReturnType;
use Johnrdorazio\LitCal\Enum\RomanMissal;
use Johnrdorazio\LitCal\Enum\StatusCode;
use Johnrdorazio\LitCal\Params\CalendarParams;

class Calendar
{
    public const API_VERSION                        = '3.9';
    public static APICore $APICore;

    private string $CacheDuration                   = "";
    private string $CACHEFILE                       = "";
    private array $AllowedReturnTypes;
    private CalendarParams $CalendarParams;
    private LitCommon $LitCommon;
    private LitGrade $LitGrade;

    private ?object $DiocesanData                   = null;
    private ?object $NationalData                   = null;
    private ?object $WiderRegionData                = null;
    private ?object $GeneralIndex                   = null;
    private \NumberFormatter $formatter;
    private \NumberFormatter $formatterFem;
    private \IntlDateFormatter $dayAndMonth;
    private \IntlDateFormatter $dayOfTheWeek;

    private array $PropriumDeTempore                = [];
    private array $Messages                         = [];
    private FestivityCollection $Cal;
    private array $tempCal                          = [];
    private string $BaptismLordFmt;
    private string $BaptismLordMod;

    private int $startTime;
    private int $endTime;

    private const EASTER_TRIDUUM_EVENT_KEYS = [
        "HolyThurs",
        "GoodFri",
        "EasterVigil",
        "Easter"
    ];

    /**
     * The following schemas for ordinal spellouts have been taken from
     * https://www.saxonica.com/html/documentation11/extensibility/localizing/ICU-numbering-dates/ICU-numbering.html
     *
     * verbose forms supported by certain languages
     *   (see https://www.saxonica.com/html/documentation9.6/extensibility/config-extend/localizing/ICU-numbering-dates/)
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

    private static $genericSpelloutOrdinal          = [
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

    private static $mascFemSpelloutOrdinal          = [
        'ar', //Arabic
        'ca', //Catalan
        'es', //Spanish : also supports plural forms, as well as a masculine adjective form (? spellout-ordinal-masculine-adjective)
        'fr', //French
        'he', //Hebrew
        'hi', //Hindi
        'it', //Italian
        'pt'  //Portuguese
    ];

    private static $mascFemNeutSpelloutOrdinal      = [
        'be', //Belarusian
        'el', //Greek
        'hr', //Croatian
        'nb', //Norwegian Bokmål
        'ru', //Russian : also supports a myriad of other cases, too complicated to handle for now
        'sv'  //Swedish : also supports spellout-ordinal-reale ?
    ];

    //even though these do not yet support spellout-ordinal, however they do support digits-ordinal
    /*private static $noSpelloutOrdinal               = [
        'bg', //Bulgarian
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
        'kl', //Greenlandic
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

    //whatever does spellout-ordinal-common mean? perhaps it's common to both masculine and feminine? with neuter being different?
    private static $commonNeutSpelloutOrdinal       = [
        'da'  //Danish
    ];

    public function __construct()
    {
        $this->startTime        = hrtime(true);
        $this->CacheDuration    = "_" . CacheDuration::MONTH . date("m");
        self::$APICore          = new APICore();
    }

    private static function debugWrite(string $string)
    {
        file_put_contents("debug.log", $string . PHP_EOL, FILE_APPEND);
    }

    public static function produceErrorResponse(int $statusCode, string $description): void
    {
        if (self::$APICore->getResponseContentType() === null) {
            // set a default response content type that can be overriden by a parameter or an accept header
            self::$APICore->setResponseContentType(self::$APICore->getAllowedAcceptHeaders()[ 0 ]);
            self::$APICore->setResponseContentTypeHeader();
        }
        header($_SERVER[ "SERVER_PROTOCOL" ] . StatusCode::toString($statusCode), true, $statusCode);
        $message = new \stdClass();
        $message->status = "ERROR";
        $message->description = $description;
        $response = json_encode($message);
        switch (self::$APICore->getResponseContentType()) {
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

    private function initParameterData(array $requestPathParts = [])
    {
        if (self::$APICore->getRequestContentType() === RequestContentType::JSON) {
            $data = self::$APICore->retrieveRequestParamsFromJsonBody(true);
            $this->CalendarParams = new CalendarParams($data);
        } elseif (self::$APICore->getRequestContentType() === RequestContentType::YAML) {
            $data = self::$APICore->retrieveRequestParamsFromYamlBody(true);
            $this->CalendarParams = new CalendarParams($data);
        } else {
            switch (self::$APICore->getRequestMethod()) {
                case RequestMethod::POST:
                    $this->CalendarParams = new CalendarParams($_POST);
                    break;
                case RequestMethod::GET:
                    $this->CalendarParams = new CalendarParams($_GET);
                    break;
                case RequestMethod::OPTIONS:
                    //continue
                    break;
                default:
                    $description = "Allowed Request Methods are "
                    . implode(' and ', self::$APICore->getAllowedRequestMethods())
                    . ', but your Request Method was '
                    . self::$APICore->getRequestMethod();
                    self::produceErrorResponse(StatusCode::METHOD_NOT_ALLOWED, $description);
            }
        }
        // path will have precedence over params
        $numPathParts = count($requestPathParts);
        if ($numPathParts > 0) {
            $DATA = [];
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
                    self::produceErrorResponse(StatusCode::BAD_REQUEST, "path parameter expected to represent Year value but did not have type Integer or numeric String");
                } else {
                    $DATA["YEAR"] = $requestPathParts[0];
                }
            } elseif ($numPathParts > 3) {
                $description = "Expected at least one and at most three path parameters, instead found " . $numPathParts;
                self::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
            } else {
                if (false === in_array($requestPathParts[0], ['nation','diocese'])) {
                    $description = "Invalid value `{$requestPathParts[0]}` for path parameter in position 1,"
                        . " the first parameter should have a value of either `nation` or `diocese`";
                    self::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
                } else {
                    if ($requestPathParts[0] === 'nation') {
                        $DATA['NATIONALCALENDAR'] = $requestPathParts[1];
                    } elseif ($requestPathParts[0] === 'diocese') {
                        $DATA['DIOCESANCALENDAR'] = $requestPathParts[1];
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
                        self::produceErrorResponse(StatusCode::BAD_REQUEST, "path parameter expected to represent Year value but did not have type Integer or numeric String");
                    } else {
                        $DATA["YEAR"] = $requestPathParts[2];
                    }
                }
            }
            if (count($DATA)) {
                $this->CalendarParams->setData($DATA);
            }
        }
        if ($this->CalendarParams->ReturnType !== null) {
            if (in_array($this->CalendarParams->ReturnType, $this->AllowedReturnTypes)) {
                self::$APICore->setResponseContentType(
                    self::$APICore->getAllowedAcceptHeaders()[ array_search($this->CalendarParams->ReturnType, $this->AllowedReturnTypes) ]
                );
            } else {
                $description = "You are requesting a content type which this API cannot produce. Allowed content types are "
                    . implode(' and ', $this->AllowedReturnTypes)
                    . ', but you have issued a parameter requesting a Content Type of '
                    . strtoupper($this->CalendarParams->ReturnType);
                self::produceErrorResponse(StatusCode::NOT_ACCEPTABLE, $description);
            }
        } else {
            // if the return type was not explicitly set through a parameter,
            //   check if we have an accept header
            //   and if not, use default value
            if (self::$APICore->hasAcceptHeader()) {
                if (self::$APICore->isAllowedAcceptHeader()) {
                    $this->CalendarParams->ReturnType = $this->AllowedReturnTypes[ self::$APICore->getIdxAcceptHeaderInAllowed() ];
                    self::$APICore->setResponseContentType(self::$APICore->getAcceptHeader());
                } else {
                    //Requests from browser windows using the address bar will probably have an Accept header of text/html
                    //In order to not be too drastic, let's treat text/html as though it were application/json
                    $acceptHeaders = explode(",", self::$APICore->getAcceptHeader());
                    if (in_array('text/html', $acceptHeaders) || in_array('text/plain', $acceptHeaders) || in_array('*/*', $acceptHeaders)) {
                        $this->CalendarParams->ReturnType = ReturnType::JSON;
                        self::$APICore->setResponseContentType(AcceptHeader::JSON);
                    } else {
                        $description = "You are requesting a content type which this API cannot produce. Allowed Accept headers are "
                            . implode(' and ', self::$APICore->getAllowedAcceptHeaders())
                            . ', but you have issued an request with an Accept header of '
                            . self::$APICore->getAcceptHeader();
                        self::produceErrorResponse(StatusCode::NOT_ACCEPTABLE, $description);
                    }
                }
            } else {
                $this->CalendarParams->ReturnType = $this->AllowedReturnTypes[ 0 ];
                self::$APICore->setResponseContentType(self::$APICore->getAllowedAcceptHeaders()[ 0 ]);
            }
        }
    }

    private function updateSettingsBasedOnNationalCalendar(): void
    {
        if ($this->CalendarParams->NationalCalendar !== null) {
            if ($this->CalendarParams->NationalCalendar === 'VATICAN') {
                $this->CalendarParams->Epiphany        = Epiphany::JAN6;
                $this->CalendarParams->Ascension       = Ascension::THURSDAY;
                $this->CalendarParams->CorpusChristi   = CorpusChristi::THURSDAY;
                $this->CalendarParams->Locale          = LitLocale::LATIN;
            } else {
                if (property_exists($this->NationalData, 'settings')) {
                    if (
                        property_exists($this->NationalData->settings, 'epiphany')
                        && Epiphany::isValid($this->NationalData->settings->epiphany)
                    ) {
                        $this->CalendarParams->Epiphany        = $this->NationalData->settings->epiphany;
                    }
                    if (
                        property_exists($this->NationalData->settings, 'ascension')
                        && Ascension::isValid($this->NationalData->settings->ascension)
                    ) {
                        $this->CalendarParams->Ascension       = $this->NationalData->settings->ascension;
                    }
                    if (
                        property_exists($this->NationalData->settings, 'corpuschristi')
                        && CorpusChristi::isValid($this->NationalData->settings->corpuschristi)
                    ) {
                        $this->CalendarParams->CorpusChristi   = $this->NationalData->settings->corpuschristi;
                    }
                    if (
                        property_exists($this->NationalData->settings, 'eternalhighpriest')
                        && is_bool($this->NationalData->settings->eternalhighpriest)
                    ) {
                        $this->CalendarParams->EternalHighPriest = $this->NationalData->settings->eternalhighpriest;
                    }
                    if (
                        property_exists($this->NationalData->settings, 'locale')
                        && LitLocale::isValid($this->NationalData->settings->locale)
                    ) {
                        $this->CalendarParams->Locale          = $this->NationalData->settings->locale;
                    }
                }
            }
        }
    }

    private function updateSettingsBasedOnDiocesanCalendar(): void
    {
        if ($this->CalendarParams->DiocesanCalendar !== null && $this->DiocesanData !== null) {
            if (property_exists($this->DiocesanData, "overrides")) {
                foreach ($this->DiocesanData->overrides as $key => $value) {
                    switch ($key) {
                        case "epiphany":
                            if (Epiphany::isValid($value)) {
                                $this->CalendarParams->Epiphany        = $value;
                            }
                            break;
                        case "ascension":
                            if (Ascension::isValid($value)) {
                                $this->CalendarParams->Ascension       = $value;
                            }
                            break;
                        case "corpuschristi":
                            if (CorpusChristi::isValid($value)) {
                                $this->CalendarParams->CorpusChristi   = $value;
                            }
                            break;
                    }
                }
            }
        }
    }


    private function loadDiocesanCalendarData(): void
    {
        if ($this->CalendarParams->DiocesanCalendar !== null) {
            //since a Diocesan calendar is being requested, we need to retrieve the JSON data
            //first we need to discover the path, so let's retrieve our index file
            if (file_exists("nations/index.json")) {
                $this->GeneralIndex = json_decode(file_get_contents("nations/index.json"));
                if (property_exists($this->GeneralIndex, $this->CalendarParams->DiocesanCalendar)) {
                    $diocesanDataFile = $this->GeneralIndex->{$this->CalendarParams->DiocesanCalendar}->path;
                    $this->CalendarParams->NationalCalendar = $this->GeneralIndex->{$this->CalendarParams->DiocesanCalendar}->nation;
                    if (file_exists($diocesanDataFile)) {
                        $this->DiocesanData = json_decode(file_get_contents($diocesanDataFile));
                    }
                }
            }
        }
    }

    private function cacheFileIsAvailable(): bool
    {
        $cacheFilePath = "engineCache/v" . str_replace(".", "_", self::API_VERSION) . "/";
        $paramsHash = md5(serialize($this->CalendarParams));
        LitCommon::$HASH_REQUEST = $paramsHash;
        LitFunc::$HASH_REQUEST = $paramsHash;
        $cacheFileName = $paramsHash . $this->CacheDuration . "." . strtolower($this->CalendarParams->ReturnType);
        $this->CACHEFILE = $cacheFilePath . $cacheFileName;
        return file_exists($this->CACHEFILE);
    }

    private function createFormatters(): void
    {
        $baseLocale = LitLocale::$PRIMARY_LANGUAGE;
        $this->dayAndMonth = \IntlDateFormatter::create(
            $baseLocale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            "d MMMM"
        );
        $this->dayOfTheWeek  = \IntlDateFormatter::create(
            $baseLocale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            "EEEE"
        );
        $this->formatter = new \NumberFormatter(
            $baseLocale,
            \NumberFormatter::SPELLOUT
        );
        //follow rules as indicated here:
        // https://www.saxonica.com/html/documentation11/extensibility/localizing/ICU-numbering-dates/ICU-numbering.html
        if (in_array($baseLocale, self::$genericSpelloutOrdinal)) {
            $this->formatter->setTextAttribute(\NumberFormatter::DEFAULT_RULESET, "%spellout-ordinal");
            //feminine version will be the same as masculine
            $this->formatterFem = $this->formatter;
        } elseif (in_array($baseLocale, self::$mascFemSpelloutOrdinal) || in_array($baseLocale, self::$mascFemNeutSpelloutOrdinal)) {
            $this->formatter->setTextAttribute(\NumberFormatter::DEFAULT_RULESET, "%spellout-ordinal-masculine");
            $this->formatterFem = new \NumberFormatter($baseLocale, \NumberFormatter::SPELLOUT);
            $this->formatterFem->setTextAttribute(\NumberFormatter::DEFAULT_RULESET, "%spellout-ordinal-feminine");
        } elseif (in_array($baseLocale, self::$commonNeutSpelloutOrdinal)) {
            $this->formatter->setTextAttribute(\NumberFormatter::DEFAULT_RULESET, "%spellout-ordinal-common");
            //feminine version will be the same as masculine
            $this->formatterFem = $this->formatter;
        } else {
            $this->formatter = new \NumberFormatter($baseLocale, \NumberFormatter::ORDINAL);
            //feminine version will be the same as masculine
            $this->formatterFem = $this->formatter;
        }
    }

    private function dieIfBeforeMinYear(): void
    {
        //for the time being, we cannot accept a year any earlier than 1970,
        // since this engine is based on the liturgical reform from Vatican II
        // with the Prima Editio Typica of the Roman Missal and the General Norms
        // promulgated with the Motu Proprio "Mysterii Paschali" in 1969
        if ($this->CalendarParams->Year < 1970) {
            $message = sprintf(
                _("Only years from 1970 and after are supported. You tried requesting the year %d."),
                $this->CalendarParams->Year
            );
            self::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
        }
    }

    private function loadPropriumDeTemporeI18nData(): ?array
    {
        $locale = LitLocale::$PRIMARY_LANGUAGE;
        $propriumDeTemporeI18nFile = "data/propriumdetempore/{$locale}.json";
        if (file_exists($propriumDeTemporeI18nFile)) {
            $rawData = file_get_contents($propriumDeTemporeI18nFile);
            $PropriumDeTemporeI18n = json_decode($rawData, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $PropriumDeTemporeI18n;
            } else {
                $message = sprintf(
                    /**translators: Temporale refers to the Proprium de Tempore */
                    _("There was an error trying to decode localized JSON data for the Temporale: %s"),
                    json_last_error_msg()
                );
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
            }
        } else {
            /**translators: Temporale refers to the Proprium de Tempore */
            $message = _("There was an error trying to retrieve localized data for the Temporale.");
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
        }
        return null;
    }

    /**
     * Retrieve Higher Ranking Solemnities from Proprium de Tempore
     */
    private function loadPropriumDeTemporeData(): void
    {
        $propriumDeTemporeFile = "data/propriumdetempore.json";
        if (file_exists($propriumDeTemporeFile)) {
            $rawData = file_get_contents($propriumDeTemporeFile);
            $PropriumDeTempore = json_decode($rawData, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $PropriumDeTemporeI18n = $this->loadPropriumDeTemporeI18nData();
                if (null !== $PropriumDeTemporeI18n) {
                    foreach ($PropriumDeTempore as $event) {
                        $key = $event["event_key"];
                        unset($event["event_key"]);
                        $this->PropriumDeTempore[ $key ] = [
                            "name" => $PropriumDeTemporeI18n[ $key ],
                            ...$event
                        ];
                    }
                }
            } else {
                $message = sprintf(
                    /**translators: Temporale refers to the Proprium de Tempore */
                    _("There was an error trying to decode JSON data for the Temporale: %s"),
                    json_last_error_msg()
                );
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
            }
        } else {
            /**translators: Temporale refers to the Proprium de Tempore */
            $message = _("There was an error trying to retrieve data for the Temporale.");
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
        }
    }

    private function loadPropriumDeSanctisData(string $missal): void
    {
        $propriumdesanctisFile = RomanMissal::getSanctoraleFileName($missal);
        $propriumdesanctisI18nPath = RomanMissal::getSanctoraleI18nFilePath($missal);
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
            $locale = LitLocale::$PRIMARY_LANGUAGE;
            $propriumdesanctisI18nFile = $propriumdesanctisI18nPath . $locale . ".json";
            if (file_exists($propriumdesanctisI18nFile)) {
                $rawData = file_get_contents($propriumdesanctisI18nFile);
                $NAME = json_decode($rawData, true);
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
            $rawData = file_get_contents($propriumdesanctisFile);
            $PropriumDeSanctis = json_decode($rawData);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->tempCal[ $missal ] = [];
                foreach ($PropriumDeSanctis as $row) {
                    if ($propriumdesanctisI18nPath !== false && $NAME !== null) {
                        $row->name = $NAME[ $row->event_key ];
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

    private function loadMemorialsFromDecreesData(): void
    {
        $memorialsFromDecreesFile       = "data/memorialsFromDecrees/memorialsFromDecrees.json";
        $memorialsFromDecreesI18nPath   = "data/memorialsFromDecrees/i18n/";
        $locale = LitLocale::$PRIMARY_LANGUAGE;
        $memorialsFromDecreesI18nFile = $memorialsFromDecreesI18nPath . $locale . ".json";
        $NAME = null;

        if (file_exists($memorialsFromDecreesI18nFile)) {
            $rawData = file_get_contents($memorialsFromDecreesI18nFile);
            $NAME = json_decode($rawData, true);
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

        if (file_exists($memorialsFromDecreesFile)) {
            $rawData = file_get_contents($memorialsFromDecreesFile);
            $memorialsFromDecrees = json_decode($rawData);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $message = sprintf(
                    _('There was an error trying to decode JSON data for Memorials based on Decrees of the Congregation for Divine Worship: %s'),
                    json_last_error_msg()
                );
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
            } else {
                $this->tempCal[ "MEMORIALS_FROM_DECREES" ] = [];
                foreach ($memorialsFromDecrees as $row) {
                    if (
                        (
                            $row->metadata->action === "createNew"
                            || ($row->metadata->action === "setProperty" && $row->metadata->property === "name" )
                        )
                        && $NAME !== null
                    ) {
                        $row->festivity->name = $NAME[ $row->festivity->event_key ];
                    }
                    $this->tempCal[ "MEMORIALS_FROM_DECREES" ][ $row->festivity->event_key ] = $row;
                }
            }
        }
    }

    private function createPropriumDeTemporeFestivityByKey(?string $key = null): Festivity
    {
        if ($key) {
            $event = new Festivity(
                $this->PropriumDeTempore[ $key ][ "name" ],
                $this->PropriumDeTempore[ $key ][ "date" ],
                $this->PropriumDeTempore[ $key ][ "color" ],
                $this->PropriumDeTempore[ $key ][ "type" ],
                $this->PropriumDeTempore[ $key ][ "grade" ]
            );
            $this->Cal->addFestivity($key, $event);
            return $event;
        } else {
            //return error
        }
    }

    private function calculateEasterTriduum(): void
    {
        $this->PropriumDeTempore[ "HolyThurs" ][ "date" ]   = LitFunc::calcGregEaster($this->CalendarParams->Year)->sub(new \DateInterval('P3D'));
        $this->PropriumDeTempore[ "GoodFri" ][ "date" ]     = LitFunc::calcGregEaster($this->CalendarParams->Year)->sub(new \DateInterval('P2D'));
        $this->PropriumDeTempore[ "EasterVigil" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)->sub(new \DateInterval('P1D'));
        $this->PropriumDeTempore[ "Easter" ][ "date" ]      = LitFunc::calcGregEaster($this->CalendarParams->Year);
        $this->createPropriumDeTemporeFestivityByKey("HolyThurs");
        $this->createPropriumDeTemporeFestivityByKey("GoodFri");
        $this->createPropriumDeTemporeFestivityByKey("EasterVigil");
        $this->createPropriumDeTemporeFestivityByKey("Easter");
    }

    /**
     * Function calculateEpiphanyJan6
     *
     * @var DateTime|false $dateTime
     *
     * @return void
     */
    private function calculateEpiphanyJan6(): void
    {
        $this->PropriumDeTempore[ "Epiphany" ][ "date" ] = DateTime::createFromFormat('!j-n-Y', '6-1-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
        $this->createPropriumDeTemporeFestivityByKey("Epiphany");
        //If a Sunday occurs on a day from Jan. 2 through Jan. 5, it is called the "Second Sunday of Christmas"
        //Weekdays from Jan. 2 through Jan. 5 are called "*day before Epiphany" (in which calendar? England?)
        //Actually in Latin they are "Feria II temporis Nativitatis",
        // in English "Monday - Christmas Weekday",
        // in Italian "Feria propria del 3 gennaio" etc.
        $nth = 0;
        for ($i = 2; $i <= 5; $i++) {
            $dateTime = DateTime::createFromFormat(
                '!j-n-Y',
                $i . '-1-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            );
            if (self::dateIsSunday($dateTime)) {
                $this->PropriumDeTempore[ "Christmas2" ][ "date" ] = $dateTime;
                $this->createPropriumDeTemporeFestivityByKey("Christmas2");
            } else {
                $nth++;
                //$nthStr = $this->CalendarParams->Locale === LitLocale::LATIN
                // ? LitMessages::LATIN_ORDINAL[ $nth ]
                // : $this->formatter->format( $nth );
                $locale = strtoupper(LitLocale::$PRIMARY_LANGUAGE);
                $dayOfTheWeek = $locale === LitLocale::LATIN
                    ? LitMessages::LATIN_DAYOFTHEWEEK[ $dateTime->format('w') ]
                    : ( $locale === 'IT'
                        ? $this->dayAndMonth->format($dateTime->format('U'))
                        : ucfirst($this->dayOfTheWeek->format($dateTime->format('U')))
                    );
                $name = $locale === LitLocale::LATIN
                    ? sprintf("%s temporis Nativitatis", $dayOfTheWeek)
                    : ( $locale === 'IT'
                        ? sprintf("Feria propria del %s", $dayOfTheWeek)
                        : sprintf(
                            /**translators: days before Epiphany when Epiphany falls on Jan 6 (not useful in Italian!) */
                            _("%s - Christmas Weekday"),
                            ucfirst($dayOfTheWeek)
                        )
                    );
                $festivity = new Festivity(
                    $name,
                    $dateTime,
                    LitColor::WHITE,
                    LitFeastType::MOBILE
                );
                $this->Cal->addFestivity("DayBeforeEpiphany" . $nth, $festivity);
            }
        }

        //Weekdays from Jan. 7 until the following Sunday are called "*day after Epiphany" (in which calendar? England?)
        //Actually in Latin they are still "Feria II temporis Nativitatis",
        // in English "Monday - Christmas Weekday",
        // in Italian "Feria propria del 3 gennaio" etc.
        $SundayAfterEpiphany = (int)DateTime::createFromFormat(
            '!j-n-Y',
            '6-1-' . $this->CalendarParams->Year,
            new \DateTimeZone('UTC')
        )->modify('next Sunday')->format('j');
        //this means January 7th, it does not refer to the day of the week which is obviously Sunday in this case
        if ($SundayAfterEpiphany !== 7) {
            $nth = 0;
            for ($i = 7; $i < $SundayAfterEpiphany; $i++) {
                $nth++;
                //$nthStr = $this->CalendarParams->Locale === LitLocale::LATIN
                // ? LitMessages::LATIN_ORDINAL[ $nth ]
                // : $this->formatter->format( $nth );
                $dateTime = DateTime::createFromFormat(
                    '!j-n-Y',
                    $i . '-1-' . $this->CalendarParams->Year,
                    new \DateTimeZone('UTC')
                );
                $locale = strtoupper(LitLocale::$PRIMARY_LANGUAGE);
                $dayOfTheWeek = $locale === LitLocale::LATIN
                    ? LitMessages::LATIN_DAYOFTHEWEEK[ $dateTime->format('w') ]
                    : ( $locale === 'IT'
                        ? $this->dayAndMonth->format($dateTime->format('U'))
                        : ucfirst($this->dayOfTheWeek->format($dateTime->format('U')))
                    );
                //$name = $locale === LitLocale::LATIN
                // ? sprintf( "Dies %s post Epiphaniam", $nthStr )
                // : sprintf( _( "%s day after Epiphany" ), ucfirst( $nthStr ) );
                $name = $locale === LitLocale::LATIN
                    ? sprintf("%s temporis Nativitatis", $dayOfTheWeek)
                    : ( $locale === 'IT'
                        ? sprintf("Feria propria del %s", $dayOfTheWeek)
                        : sprintf(
                            /**translators: days after Epiphany when Epiphany falls on Jan 6 (not useful in Italian!) */
                            _("%s - Christmas Weekday"),
                            ucfirst($dayOfTheWeek)
                        )
                    );
                $festivity = new Festivity(
                    $name,
                    $dateTime,
                    LitColor::WHITE,
                    LitFeastType::MOBILE
                );
                $this->Cal->addFestivity("DayAfterEpiphany" . $nth, $festivity);
            }
        }
    }

    private function calculateEpiphanySunday(): void
    {
        //If January 2nd is a Sunday, then go with Jan 2nd
        $dateTime = DateTime::createFromFormat(
            '!j-n-Y',
            '2-1-' . $this->CalendarParams->Year,
            new \DateTimeZone('UTC')
        );
        if (self::dateIsSunday($dateTime)) {
            $this->PropriumDeTempore[ "Epiphany" ][ "date" ] = $dateTime;
            $Epiphany = $this->createPropriumDeTemporeFestivityByKey("Epiphany");

            $DayOfEpiphany = (int)$Epiphany->date->format('j');
            $SundayAfterEpiphany =  (int)DateTime::createFromFormat(
                '!j-n-Y',
                '2-1-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            )->modify('next Sunday')->format('j');
        } else {
            //otherwise find the Sunday following Jan 2nd
            $SundayOfEpiphany = $dateTime->modify('next Sunday');
            $this->PropriumDeTempore[ "Epiphany" ][ "date" ] = $SundayOfEpiphany;
            $Epiphany = $this->createPropriumDeTemporeFestivityByKey("Epiphany");

            //Weekdays from Jan. 2 until the following Sunday are called "*day before Epiphany"
            // (in which calendar? England?)
            //Actually in Latin they are "Feria II temporis Nativitatis",
            // in English "Monday - Christmas Weekday",
            // in Italian "Feria propria del 3 gennaio" etc.
            $DayOfEpiphany = (int)$SundayOfEpiphany->format('j');
            $SundayAfterEpiphany = (int)DateTime::createFromFormat(
                '!j-n-Y',
                '2-1-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            )->modify('next Sunday')->modify('next Sunday')->format('j');
            $nth = 0;
            for ($i = 2; $i < $DayOfEpiphany - 1; $i++) {
                $nth++;
                //$nthStr = $this->CalendarParams->Locale === LitLocale::LATIN
                // ? LitMessages::LATIN_ORDINAL[ $nth ]
                // : $this->formatter->format( $nth );
                $dateTime = DateTime::createFromFormat(
                    '!j-n-Y',
                    $i . '-1-' . $this->CalendarParams->Year,
                    new \DateTimeZone('UTC')
                );
                $locale = strtoupper(LitLocale::$PRIMARY_LANGUAGE);
                $dayOfTheWeek = $locale === LitLocale::LATIN
                    ? LitMessages::LATIN_DAYOFTHEWEEK[ $dateTime->format('w') ]
                    : ( $locale === 'IT'
                        ? $this->dayAndMonth->format($dateTime->format('U'))
                        : ucfirst($this->dayOfTheWeek->format($dateTime->format('U')))
                    );
                //$name = $locale === LitLocale::LATIN
                // ? sprintf( "Dies %s ante Epiphaniam", $nthStr )
                // : sprintf( _( "%s day before Epiphany" ), ucfirst( $nthStr ) );
                $name = $locale === LitLocale::LATIN
                    ? sprintf("%s temporis Nativitatis", $dayOfTheWeek)
                    : ( $locale === 'IT'
                        ? sprintf("Feria propria del %s", $dayOfTheWeek)
                        : sprintf(
                            /**translators: days before Epiphany when Epiphany is on a Sunday (not useful in Italian!) */
                            _("%s - Christmas Weekday"),
                            ucfirst($dayOfTheWeek)
                        )
                    );
                $festivity = new Festivity($name, $dateTime, LitColor::WHITE, LitFeastType::MOBILE);
                $this->Cal->addFestivity("DayBeforeEpiphany" . $nth, $festivity);
            }
        }
        //Weekday from the Sunday of Epiphany until Baptism of the Lord are called "*day after Epiphany"
        // (in which calendar? England?)
        //Actually in Latin they are still "Feria II temporis Nativitatis",
        // in English "Monday - Christmas Weekday",
        // in Italian "Feria propria del 3 gennaio" ecc.
        $nth = 0;
        for ($i = $DayOfEpiphany + 1; $i < $SundayAfterEpiphany - 1; $i++) {
            $nth++;
            //$nthStr = $this->CalendarParams->Locale === LitLocale::LATIN
            // ? LitMessages::LATIN_ORDINAL[ $nth ]
            // : $this->formatter->format( $nth );
            $dateTime = DateTime::createFromFormat(
                '!j-n-Y',
                $i . '-1-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            );
            $locale = strtoupper(LitLocale::$PRIMARY_LANGUAGE);
            $dayOfTheWeek = $locale === LitLocale::LATIN
                ? LitMessages::LATIN_DAYOFTHEWEEK[ $dateTime->format('w') ]
                : ( $locale === 'IT'
                    ? $this->dayAndMonth->format($dateTime->format('U'))
                    : ucfirst($this->dayOfTheWeek->format($dateTime->format('U')))
                );
            //$name = $locale === LitLocale::LATIN
            // ? sprintf( "Dies %s post Epiphaniam", $nthStr )
            // : sprintf( _( "%s day after Epiphany" ), ucfirst( $nthStr ) );
            $name = $locale === LitLocale::LATIN
                ? sprintf("%s temporis Nativitatis", $dayOfTheWeek)
                : ( $locale === 'IT'
                    ? sprintf("Feria propria del %s", $dayOfTheWeek)
                    : sprintf(
                        /**translators: days after Epiphany when Epiphany is on a Sunday (not useful in Italian!) */
                        _("%s - Christmas Weekday"),
                        ucfirst($dayOfTheWeek)
                    )
                );
            $festivity = new Festivity(
                $name,
                $dateTime,
                LitColor::WHITE,
                LitFeastType::MOBILE
            );
            $this->Cal->addFestivity("DayAfterEpiphany" . $nth, $festivity);
        }
    }

    private function calculateChristmasEpiphany(): void
    {
        $this->PropriumDeTempore[ "Christmas" ][ "date" ]   = DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
        $this->createPropriumDeTemporeFestivityByKey("Christmas");
        if ($this->CalendarParams->Epiphany === Epiphany::JAN6) {
            $this->calculateEpiphanyJan6();
        } elseif ($this->CalendarParams->Epiphany === Epiphany::SUNDAY_JAN2_JAN8) {
            $this->calculateEpiphanySunday();
        }
    }

    private function calculateAscensionPentecost(): void
    {

        if ($this->CalendarParams->Ascension === Ascension::THURSDAY) {
            $this->PropriumDeTempore[ "Ascension" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P39D'));
            $this->createPropriumDeTemporeFestivityByKey("Ascension");
            $this->PropriumDeTempore[ "Easter7" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
                ->add(new \DateInterval('P' . ( 7 * 6 ) . 'D'));
            $this->createPropriumDeTemporeFestivityByKey("Easter7");
        } elseif ($this->CalendarParams->Ascension === Ascension::SUNDAY) {
            $this->PropriumDeTempore[ "Ascension" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
                ->add(new \DateInterval('P' . ( 7 * 6 ) . 'D'));
            $this->createPropriumDeTemporeFestivityByKey("Ascension");
        }

        $this->PropriumDeTempore[ "Pentecost" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 7 ) . 'D'));
        $this->createPropriumDeTemporeFestivityByKey("Pentecost");
    }

    private function calculateSundaysMajorSeasons(): void
    {
        //We calculate Sundays of Advent based on Christmas
        $jny = '!j-n-Y';
        $christmasDateStr = '25-12-' . $this->CalendarParams->Year;
        $this->PropriumDeTempore[ "Advent1" ][ "date" ] = DateTime::createFromFormat($jny, $christmasDateStr, new \DateTimeZone('UTC'))
            ->modify('last Sunday')->sub(new \DateInterval('P' . ( 3 * 7 ) . 'D'));
        $this->PropriumDeTempore[ "Advent2" ][ "date" ] = DateTime::createFromFormat($jny, $christmasDateStr, new \DateTimeZone('UTC'))
            ->modify('last Sunday')->sub(new \DateInterval('P' . ( 2 * 7 ) . 'D'));
        $this->PropriumDeTempore[ "Advent3" ][ "date" ] = DateTime::createFromFormat($jny, $christmasDateStr, new \DateTimeZone('UTC'))
            ->modify('last Sunday')->sub(new \DateInterval('P7D'));
        $this->PropriumDeTempore[ "Advent4" ][ "date" ] = DateTime::createFromFormat($jny, $christmasDateStr, new \DateTimeZone('UTC'))
            ->modify('last Sunday');
        $this->createPropriumDeTemporeFestivityByKey("Advent1");
        $this->createPropriumDeTemporeFestivityByKey("Advent2");
        $this->createPropriumDeTemporeFestivityByKey("Advent3");
        $this->createPropriumDeTemporeFestivityByKey("Advent4");

        //We calculate Sundays of Lent, Palm Sunday, Sundays of Easter, Trinity Sunday and Corpus Christi based on Easter
        $this->PropriumDeTempore[ "Lent1" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P' . ( 6 * 7 ) . 'D'));
        $this->PropriumDeTempore[ "Lent2" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P' . ( 5 * 7 ) . 'D'));
        $this->PropriumDeTempore[ "Lent3" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P' . ( 4 * 7 ) . 'D'));
        $this->PropriumDeTempore[ "Lent4" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P' . ( 3 * 7 ) . 'D'));
        $this->PropriumDeTempore[ "Lent5" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P' . ( 2 * 7 ) . 'D'));
        $this->createPropriumDeTemporeFestivityByKey("Lent1");
        $this->createPropriumDeTemporeFestivityByKey("Lent2");
        $this->createPropriumDeTemporeFestivityByKey("Lent3");
        $this->createPropriumDeTemporeFestivityByKey("Lent4");
        $this->createPropriumDeTemporeFestivityByKey("Lent5");
        $this->PropriumDeTempore[ "PalmSun" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P7D'));
        $this->PropriumDeTempore[ "Easter2" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P7D'));
        $this->PropriumDeTempore[ "Easter3" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 2 ) . 'D'));
        $this->PropriumDeTempore[ "Easter4" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 3 ) . 'D'));
        $this->PropriumDeTempore[ "Easter5" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 4 ) . 'D'));
        $this->PropriumDeTempore[ "Easter6" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 5 ) . 'D'));
        $this->PropriumDeTempore[ "Trinity" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 8 ) . 'D'));
        $this->createPropriumDeTemporeFestivityByKey("PalmSun");
        $this->createPropriumDeTemporeFestivityByKey("Easter2");
        $this->createPropriumDeTemporeFestivityByKey("Easter3");
        $this->createPropriumDeTemporeFestivityByKey("Easter4");
        $this->createPropriumDeTemporeFestivityByKey("Easter5");
        $this->createPropriumDeTemporeFestivityByKey("Easter6");
        $this->createPropriumDeTemporeFestivityByKey("Trinity");
        if ($this->CalendarParams->CorpusChristi === CorpusChristi::THURSDAY) {
            $this->PropriumDeTempore[ "CorpusChristi" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
                ->add(new \DateInterval('P' . ( 7 * 8 + 4 ) . 'D'));
            $this->createPropriumDeTemporeFestivityByKey("CorpusChristi");
            //Seeing the Sunday is not taken by Corpus Christi, it should be later taken by a Sunday of Ordinary Time
            // (they are calculated back to Pentecost)
        } elseif ($this->CalendarParams->CorpusChristi === CorpusChristi::SUNDAY) {
            $this->PropriumDeTempore[ "CorpusChristi" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
                ->add(new \DateInterval('P' . ( 7 * 9 ) . 'D'));
            $this->createPropriumDeTemporeFestivityByKey("CorpusChristi");
        }

        if ($this->CalendarParams->Year >= 2000) {
            if ($this->CalendarParams->Locale === LitLocale::LATIN) {
                $divineMercySunday = $this->PropriumDeTempore[ "Easter2" ][ "name" ]
                    . " vel Dominica Divinæ Misericordiæ";
            } else {
                /**translators: context alternate name for a liturgical event, e.g. Second Sunday of Easter `or` Divine Mercy Sunday*/
                $or = _("or");
                $divineMercySunday = $this->PropriumDeTempore[ "Easter2" ][ "name" ]
                    . " $or "
                    /**translators: as instituted on the day of the canonization of St Faustina Kowalska by Pope John Paul II in the year 2000 */
                    . _("Divine Mercy Sunday");
            }
            $this->Cal->setProperty("Easter2", "name", $divineMercySunday);
        }
    }

    private function calculateAshWednesday(): void
    {
        $this->PropriumDeTempore[ "AshWednesday" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P46D'));
        $this->createPropriumDeTemporeFestivityByKey("AshWednesday");
    }

    private function calculateWeekdaysHolyWeek(): void
    {
        //Weekdays of Holy Week from Monday to Thursday inclusive
        // ( that is, thursday morning chrism Mass... the In Coena Domini Mass begins the Easter Triduum )
        $this->PropriumDeTempore[ "MonHolyWeek" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P6D'));
        $this->PropriumDeTempore[ "TueHolyWeek" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P5D'));
        $this->PropriumDeTempore[ "WedHolyWeek" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->sub(new \DateInterval('P4D'));
        $this->createPropriumDeTemporeFestivityByKey("MonHolyWeek");
        $this->createPropriumDeTemporeFestivityByKey("TueHolyWeek");
        $this->createPropriumDeTemporeFestivityByKey("WedHolyWeek");
    }

    private function calculateEasterOctave(): void
    {
        $this->PropriumDeTempore[ "MonOctaveEaster" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P1D'));
        $this->PropriumDeTempore[ "TueOctaveEaster" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P2D'));
        $this->PropriumDeTempore[ "WedOctaveEaster" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P3D'));
        $this->PropriumDeTempore[ "ThuOctaveEaster" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P4D'));
        $this->PropriumDeTempore[ "FriOctaveEaster" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P5D'));
        $this->PropriumDeTempore[ "SatOctaveEaster" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P6D'));
        $this->createPropriumDeTemporeFestivityByKey("MonOctaveEaster");
        $this->createPropriumDeTemporeFestivityByKey("TueOctaveEaster");
        $this->createPropriumDeTemporeFestivityByKey("WedOctaveEaster");
        $this->createPropriumDeTemporeFestivityByKey("ThuOctaveEaster");
        $this->createPropriumDeTemporeFestivityByKey("FriOctaveEaster");
        $this->createPropriumDeTemporeFestivityByKey("SatOctaveEaster");
    }

    private function calculateMobileSolemnitiesOfTheLord(): void
    {
        $this->PropriumDeTempore[ "SacredHeart" ][ "date" ] = LitFunc::calcGregEaster($this->CalendarParams->Year)
            ->add(new \DateInterval('P' . ( 7 * 9 + 5 ) . 'D'));
        $this->createPropriumDeTemporeFestivityByKey("SacredHeart");

        //Christ the King is calculated backwards from the first sunday of advent
        $this->PropriumDeTempore[ "ChristKing" ][ "date" ] = DateTime::createFromFormat(
            '!j-n-Y',
            '25-12-' . $this->CalendarParams->Year,
            new \DateTimeZone('UTC')
        )->modify('last Sunday')->sub(new \DateInterval('P' . ( 4 * 7 ) . 'D'));
        $this->createPropriumDeTemporeFestivityByKey("ChristKing");
    }

    private function calculateFixedSolemnities(): void
    {
        //even though Mary Mother of God is a fixed date solemnity,
        // it is however found in the Proprium de Tempore and not in the Proprium de Sanctis
        $this->PropriumDeTempore[ "MotherGod" ][ "date" ] = DateTime::createFromFormat(
            '!j-n-Y',
            '1-1-' . $this->CalendarParams->Year,
            new \DateTimeZone('UTC')
        );
        $this->createPropriumDeTemporeFestivityByKey("MotherGod");

        $tempCalSolemnities = array_filter($this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ], function ($el) {
            return $el->grade === LitGrade::SOLEMNITY;
        });
        foreach ($tempCalSolemnities as $row) {
            $currentFeastDate = DateTime::createFromFormat(
                '!j-n-Y',
                $row->day . '-' . $row->month . '-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            );
            $tempFestivity = new Festivity($row->name, $currentFeastDate, $row->color, LitFeastType::FIXED, $row->grade, $row->common);
            //A Solemnity impeded in any given year is transferred to the nearest day following designated in nn. 1-8 of the Tables given above ( LY 60 )
            //However if a solemnity is impeded by a Sunday of Advent, Lent or Easter Time, the solemnity is transferred to the Monday following,
            // or to the nearest free day, as laid down by the General Norms.
            //This affects Joseph, Husband of Mary ( Mar 19 ), Annunciation ( Mar 25 ), and Immaculate Conception ( Dec 8 ).
            //It is not possible for a fixed date Solemnity to fall on a Sunday of Easter.

            //However, if a solemnity is impeded by Palm Sunday or by Easter Sunday, it is transferred to the first free day ( Monday? )
            // after the Second Sunday of Easter ( decision of the Congregation of Divine Worship, dated 22 April 1990,
            // in Notitiæ vol. 26 [ 1990 ] num. 3/4, p. 160, Prot. CD 500/89 ).
            //Any other celebrations that are impeded are omitted for that year.

            /**
             * <<
             * Quando vero sollemnitates in his dominicis ( i.e. Adventus, Quadragesimae et Paschae ),
             * iuxta n.5 "Normarum universalium de anno liturgico et de calendario" sabbato anticipari debent.
             * Experientia autem pastoralis ostendit quod solutio huiusmodi nonnullas praebet difficultates praesertim quoad occurrentiam
             * celebrationis Missae vespertinae et II Vesperarum Liturgiae Horarum cuiusdam sollemnitatis
             * cum celebratione Missae vespertinae et I Vesperarum diei dominicae.
             * [ ... Perciò facciamo la seguente modifica al n. 5 delle norme universali: ]
             * Sollemnitates autem in his dominicis occurrentes ad feriam secundam sequentem transferuntur,
             * nisi agatur de occurrentia in Dominica in Palmis aut in Dominica Resurrectionis Domini.
             * >>
             *
             * http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/1990/284-285.html
             * https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/1990/notitiae-26-(1990)/Notitiae-284-285-1990.pdf
             */

            if ($this->Cal->inSolemnities($currentFeastDate)) {
                    //if Joseph, Husband of Mary ( Mar 19 ) falls on Palm Sunday or during Holy Week, it is moved to the Saturday preceding Palm Sunday
                    //this is correct and the reason for this is that, in this case, Annunciation will also fall during Holy Week,
                    //and the Annunciation will be transferred to the Monday following the Second Sunday of Easter
                    //Notitiæ vol. 42 [ 2006 ] num. 3/4, 475-476, p. 96
                    //http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/2006/475-476.html
                    //https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/2000/notitiae-42-(2006)/Notitiae-475-476-2006.pdf
                    $locale = strtoupper(LitLocale::$PRIMARY_LANGUAGE);
                if (
                    $row->event_key === "StJoseph"
                    && $currentFeastDate >= $this->Cal->getFestivity("PalmSun")->date
                    && $currentFeastDate <= $this->Cal->getFestivity("Easter")->date
                ) {
                    $tempFestivity->date = LitFunc::calcGregEaster($this->CalendarParams->Year)->sub(new \DateInterval('P8D'));
                    $this->Messages[] = sprintf(
                        /**translators: 1: Festivity name, 2: Festivity date, 3: Requested calendar year, 4: Explicatory string for the transferral (ex. the Saturday preceding Palm Sunday), 5: actual date for the transferral, 6: Decree of the Congregation for Divine Worship  */
                        _('The Solemnity \'%1$s\' falls on %2$s in the year %3$d, the celebration has been transferred to %4$s (%5$s) as per the %6$s.'),
                        $tempFestivity->name,
                        $this->Cal->solemnityFromDate($currentFeastDate)->name,
                        $this->CalendarParams->Year,
                        _("the Saturday preceding Palm Sunday"),
                        $locale === LitLocale::LATIN ? ( $tempFestivity->date->format('j') . ' ' . LitMessages::LATIN_MONTHS[ (int)$tempFestivity->date->format('n') ] ) :
                            ( $locale === 'EN' ? $tempFestivity->date->format('F jS') :
                                $this->dayAndMonth->format($tempFestivity->date->format('U'))
                            ),
                        '<a href="https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/2000/notitiae-42-(2006)/Notitiae-475-476-2006.pdf">'
                            . _('Decree of the Congregation for Divine Worship')
                        . '</a>'
                    );
                } elseif ($row->event_key === "Annunciation" && $currentFeastDate >= $this->Cal->getFestivity("PalmSun")->date && $currentFeastDate <= $this->Cal->getFestivity("Easter2")->date) {
                    //if the Annunciation falls during Holy Week or within the Octave of Easter, it is transferred to the Monday after the Second Sunday of Easter.
                    $tempFestivity->date = LitFunc::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P8D'));
                    $this->Messages[] = sprintf(
                        /**translators: 1: Festivity name, 2: Festivity date, 3: Requested calendar year, 4: Explicatory string for the transferral (ex. the Saturday preceding Palm Sunday), 5: actual date for the transferral, 6: Decree of the Congregation for Divine Worship */
                        _('The Solemnity \'%1$s\' falls on %2$s in the year %3$d, the celebration has been transferred to %4$s (%5$s) as per the %6$s.'),
                        $tempFestivity->name,
                        $this->Cal->solemnityFromDate($currentFeastDate)->name,
                        $this->CalendarParams->Year,
                        _('the Monday following the Second Sunday of Easter'),
                        $locale === LitLocale::LATIN
                            ? ( $tempFestivity->date->format('j') . ' ' . LitMessages::LATIN_MONTHS[ (int)$tempFestivity->date->format('n') ] )
                            : ( $locale === 'EN'
                                ? $tempFestivity->date->format('F jS')
                                : $this->dayAndMonth->format($tempFestivity->date->format('U'))
                            ),
                        '<a href="https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/2000/notitiae-42-(2006)/Notitiae-475-476-2006.pdf">'
                            . _('Decree of the Congregation for Divine Worship')
                        . '</a>'
                    );

                    //In some German churches it was the custom to keep the office of the Annunciation on the Saturday before Palm Sunday
                    // if the 25th of March fell in Holy Week.
                    // source: https://www.newadvent.org/cathen/01542a.htm
                    /*
                            else if( $tempFestivity->date == $this->Cal->getFestivity( "PalmSun" )->date ){
                            $tempFestivity->date->add( new \DateInterval( 'P15D' ) );
                            //$tempFestivity->date->sub( new \DateInterval( 'P1D' ) );
                            }
                    */
                } elseif (
                    in_array($row->event_key, [ "Annunciation", "StJoseph", "ImmaculateConception" ])
                    && $this->Cal->isSundayAdventLentEaster($currentFeastDate)
                ) {
                    $tempFestivity->date = clone( $currentFeastDate );
                    $tempFestivity->date->add(new \DateInterval('P1D'));
                    $this->Messages[] = sprintf(
                        /**translators:
                         * 1: Festivity name,
                         * 2: Festivity date,
                         * 3: Requested calendar year,
                         * 4: Explicatory string for the transferral,
                         * 5: actual date for the transferral,
                         * 6: Decree of the Congregation for Divine Worship
                         */
                        _('The Solemnity \'%1$s\' falls on %2$s in the year %3$d, the celebration has been transferred to %4$s (%5$s) as per the %6$s.'),
                        $tempFestivity->name,
                        $this->Cal->solemnityFromDate($currentFeastDate)->name,
                        $this->CalendarParams->Year,
                        _("the following Monday"),
                        $locale === LitLocale::LATIN
                            ? ( $tempFestivity->date->format('j') . ' ' . LitMessages::LATIN_MONTHS[ (int)$tempFestivity->date->format('n') ] )
                            : ( $locale === 'EN'
                                    ? $tempFestivity->date->format('F jS')
                                    : $this->dayAndMonth->format($tempFestivity->date->format('U'))
                        ),
                        '<a href="https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/1990/notitiae-26-(1990)/Notitiae-284-285-1990.pdf">' . _('Decree of the Congregation for Divine Worship') . '</a>'
                    );
                } else {
                    //In all other cases, let's make a note of what's happening and ask the Congegation for Divine Worship
                    $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                        /**translators: 1: Festivity name, 2: Coinciding Festivity name, 3: Requested calendar year */
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
                if ($row->event_key === "NativityJohnBaptist" && $this->Cal->solemnityKeyFromDate($currentFeastDate) === "SacredHeart") {
                    $NativityJohnBaptistNewDate = clone( $this->Cal->getFestivity("SacredHeart")->date );
                    $SacredHeart = $this->Cal->solemnityFromDate($currentFeastDate);
                    if (!$this->Cal->inSolemnities($NativityJohnBaptistNewDate->sub(new \DateInterval('P1D')))) {
                        $tempFestivity->date->sub(new \DateInterval('P1D'));
                        $decree = '<a href="'
                            . 'https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/2020/notitiae-56-(2020)/Notitiae-597-NS-005-2020.pdf'
                            . '">'
                            . _('Decree of the Congregation for Divine Worship')
                            . '</a>';
                        $this->Messages[] =
                        '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> '
                        . sprintf(
                            /**translators:
                             * 1: Festivity name,
                             * 2: Coinciding Festivity name,
                             * 3: Requested calendar year,
                             * 4: Decree of the Congregation for Divine Worship
                             */
                            _('Seeing that the Solemnity \'%1$s\' coincides with the Solemnity \'%2$s\' in the year %3$d, '
                                . 'it has been anticipated by one day as per %4$s.'),
                            $tempFestivity->name,
                            $SacredHeart->name,
                            $this->CalendarParams->Year,
                            $decree
                        );
                    }
                }
            }
            $this->Cal->addFestivity($row->event_key, $tempFestivity);
        }

        //let's add a displayGrade property for AllSouls so applications don't have to worry about fixing it
        $this->Cal->setProperty("AllSouls", 'display_grade', $this->LitGrade->i18n(LitGrade::COMMEMORATION, false));

        $this->Cal->addSolemnitiesLordBVM([
            "Easter",
            "Christmas",
            "Ascension",
            "Pentecost",
            "Trinity",
            "CorpusChristi",
            "SacredHeart",
            "ChristKing",
            "MotherGod",
            "Annunciation",
            "ImmaculateConception",
            "Assumption",
            "StJoseph",
            "NativityJohnBaptist"
        ]);
    }

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
        $this->PropriumDeTempore[ "BaptismLord" ][ "date" ] = DateTime::createFromFormat('!j-n-Y', $this->BaptismLordFmt, new \DateTimeZone('UTC'))
            ->modify($this->BaptismLordMod);
        $this->createPropriumDeTemporeFestivityByKey("BaptismLord");

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
            $festivity = new Festivity($row->name, $currentFeastDate, $row->color, LitFeastType::FIXED, $row->grade, $row->common);
            if ($row->event_key === 'DedicationLateran') {
                $festivity->display_grade = $this->LitGrade->i18n(LitGrade::FEAST, false);
            }
            $this->Cal->addFestivity($row->event_key, $festivity);
        }

        //Holy Family is celebrated the Sunday after Christmas, unless Christmas falls on a Sunday, in which case it is celebrated Dec. 30
        $locale = strtoupper(LitLocale::$PRIMARY_LANGUAGE);
        if (self::dateIsSunday($this->Cal->getFestivity("Christmas")->date)) {
            $this->PropriumDeTempore[ "HolyFamily" ][ "date" ] = DateTime::createFromFormat(
                '!j-n-Y',
                '30-12-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            );
            $HolyFamily = $this->createPropriumDeTemporeFestivityByKey("HolyFamily");
            $this->Messages[] = sprintf(
                /**translators: 1: Festivity name (Christmas), 2: Requested calendar year, 3: Festivity name (Holy Family), 4: New date for Holy Family */
                _('\'%1$s\' falls on a Sunday in the year %2$d, therefore the Feast \'%3$s\' is celebrated on %4$s rather than on the Sunday after Christmas.'),
                $this->Cal->getFestivity("Christmas")->name,
                $this->CalendarParams->Year,
                $HolyFamily->name,
                $locale === LitLocale::LATIN ? ( $HolyFamily->date->format('j') . ' ' . LitMessages::LATIN_MONTHS[ (int)$HolyFamily->date->format('n') ] ) :
                    ( $locale === 'EN' ? $HolyFamily->date->format('F jS') :
                        $this->dayAndMonth->format($HolyFamily->date->format('U'))
                    )
            );
        } else {
            $this->PropriumDeTempore[ "HolyFamily" ][ "date" ] = DateTime::createFromFormat(
                '!j-n-Y',
                '25-12-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            )->modify('next Sunday');
            $this->createPropriumDeTemporeFestivityByKey("HolyFamily");
        }

        // In 2012, Pope Benedict XVI gave faculty to the Episcopal Conferences
        //  to insert the Feast of Our Lord Jesus Christ, the Eternal High Priest
        //  in their own liturgical calendars on the Thursday after Pentecost,
        //  see https://notitiae.ipsissima-verba.org/pdf/notitiae-2012-335-368.pdf
        if ($this->CalendarParams->Year >= 2012 && true === $this->CalendarParams->EternalHighPriest) {
            $EternalHighPriestDate = clone( $this->Cal->getFestivity("Pentecost")->date );
            $EternalHighPriestDate->modify('next Thursday');
            $locale = strtoupper(LitLocale::$PRIMARY_LANGUAGE);
            $EternalHighPriestName = $locale === LitLocale::LATIN ? 'Domini Nostri Iesu Christi Summi et Aeterni Sacerdotis' :
                /**translators: You can ignore this translation if the Feast has not been inserted by the Episcopal Conference */
                _('Our Lord Jesus Christ, The Eternal High Priest');
            $festivity = new Festivity($EternalHighPriestName, $EternalHighPriestDate, LitColor::WHITE, LitFeastType::FIXED, LitGrade::FEAST_LORD, LitCommon::PROPRIO);
            $this->Cal->addFestivity('JesusChristEternalHighPriest', $festivity);
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
        $firstOrdinaryLimit = LitFunc::calcGregEaster($this->CalendarParams->Year)->sub(new \DateInterval('P53D'));
        $ordSun = 1;
        while ($firstOrdinary >= $this->Cal->getFestivity("BaptismLord")->date && $firstOrdinary < $firstOrdinaryLimit) {
            $firstOrdinary = DateTime::createFromFormat(
                '!j-n-Y',
                $this->BaptismLordFmt,
                new \DateTimeZone('UTC')
            )->modify($this->BaptismLordMod)->modify('next Sunday')->add(new \DateInterval('P' . ( ( $ordSun - 1 ) * 7 ) . 'D'));
            $ordSun++;
            if (!$this->Cal->inSolemnities($firstOrdinary)) {
                $this->Cal->addFestivity("OrdSunday" . $ordSun, new Festivity($this->PropriumDeTempore[ "OrdSunday" . $ordSun ][ "name" ], $firstOrdinary, LitColor::GREEN, LitFeastType::MOBILE, LitGrade::FEAST_LORD));
            } else {
                $this->Messages[] = sprintf(
                    /**translators: 1: Festivity name, 2: Superseding Festivity grade, 3: Superseding Festivity name, 4: Requested calendar year */
                    _('\'%1$s\' is superseded by the %2$s \'%3$s\' in the year %4$d.'),
                    $this->PropriumDeTempore[ "OrdSunday" . $ordSun ][ "name" ],
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
        $lastOrdinaryLowerLimit = LitFunc::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P' . ( 7 * 9 ) . 'D'));
        $ordSun = 34;
        $ordSunCycle = 4;

        while ($lastOrdinary <= $this->Cal->getFestivity("ChristKing")->date && $lastOrdinary > $lastOrdinaryLowerLimit) {
            $lastOrdinary = DateTime::createFromFormat(
                '!j-n-Y',
                '25-12-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            )->modify('last Sunday')->sub(new \DateInterval('P' . ( ++$ordSunCycle * 7 ) . 'D'));
            $ordSun--;
            if (!$this->Cal->inSolemnities($lastOrdinary)) {
                $this->Cal->addFestivity("OrdSunday" . $ordSun, new Festivity($this->PropriumDeTempore[ "OrdSunday" . $ordSun ][ "name" ], $lastOrdinary, LitColor::GREEN, LitFeastType::MOBILE, LitGrade::FEAST_LORD));
            } else {
                $this->Messages[] = sprintf(
                    /**translators: 1: Festivity name, 2: Superseding Festivity grade, 3: Superseding Festivity name, 4: Requested calendar year */
                    _('\'%1$s\' is superseded by the %2$s \'%3$s\' in the year %4$d.'),
                    $this->PropriumDeTempore[ "OrdSunday" . $ordSun ][ "name" ],
                    $this->Cal->solemnityFromDate($lastOrdinary)->grade > LitGrade::SOLEMNITY ? '<i>' . $this->LitGrade->i18n($this->Cal->solemnityFromDate($lastOrdinary)->grade, false) . '</i>' : $this->LitGrade->i18n($this->Cal->solemnityFromDate($lastOrdinary)->grade, false),
                    $this->Cal->solemnityFromDate($lastOrdinary)->name,
                    $this->CalendarParams->Year
                );
            }
        }
    }

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
                $festivity = new Festivity($row->name, $row->date, $row->color, LitFeastType::FIXED, $row->grade, $row->common);
                $this->Cal->addFestivity($row->event_key, $festivity);
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

    private function calculateWeekdaysAdvent(): void
    {
        //  Here we are calculating all weekdays of Advent, but we are giving a certain importance to the weekdays of Advent from 17 Dec. to 24 Dec.
        //  ( the same will be true of the Octave of Christmas and weekdays of Lent )
        //  on which days obligatory memorials can only be celebrated in partial form

        $DoMAdvent1     = $this->Cal->getFestivity("Advent1")->date->format('j'); //DoM == Day of Month
        $MonthAdvent1   = $this->Cal->getFestivity("Advent1")->date->format('n');
        $weekdayAdvent  = DateTime::createFromFormat('!j-n-Y', $DoMAdvent1 . '-' . $MonthAdvent1 . '-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
        $weekdayAdventCnt = 1;
        while ($weekdayAdvent >= $this->Cal->getFestivity("Advent1")->date && $weekdayAdvent < $this->Cal->getFestivity("Christmas")->date) {
            $weekdayAdvent = DateTime::createFromFormat(
                '!j-n-Y',
                $DoMAdvent1 . '-' . $MonthAdvent1 . '-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            )->add(new \DateInterval('P' . $weekdayAdventCnt . 'D'));

            //if we're not dealing with a sunday or a solemnity, then create the weekday
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($weekdayAdvent) && self::dateIsNotSunday($weekdayAdvent)) {
                $upper = (int)$weekdayAdvent->format('z');
                $diff = $upper - (int)$this->Cal->getFestivity("Advent1")->date->format('z'); //day count between current day and First Sunday of Advent
                $currentAdvWeek = ( ( $diff - $diff % 7 ) / 7 ) + 1; //week count between current day and First Sunday of Advent

                $dayOfTheWeek = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? LitMessages::LATIN_DAYOFTHEWEEK[ $weekdayAdvent->format('w') ]
                    : ucfirst($this->dayOfTheWeek->format($weekdayAdvent->format('U')));
                $ordinal = ucfirst(
                    LitMessages::getOrdinal($currentAdvWeek, $this->CalendarParams->Locale, $this->formatterFem, LitMessages::LATIN_ORDINAL_FEM_GEN)
                );
                $nthStr = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? sprintf("Hebdomadæ %s Adventus", $ordinal)
                    : sprintf(
                        /**translators: %s is an ordinal number (first, second...) */
                        _("of the %s Week of Advent"),
                        $ordinal
                    );
                $name = $dayOfTheWeek . " " . $nthStr;
                $festivity = new Festivity($name, $weekdayAdvent, LitColor::PURPLE, LitFeastType::MOBILE);
                $this->Cal->addFestivity("AdventWeekday" . $weekdayAdventCnt, $festivity);
            }

            $weekdayAdventCnt++;
        }
    }

    private function calculateWeekdaysChristmasOctave(): void
    {
        $weekdayChristmas = DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
        $weekdayChristmasCnt = 1;
        while (
            $weekdayChristmas >= $this->Cal->getFestivity("Christmas")->date
            && $weekdayChristmas < DateTime::createFromFormat('!j-n-Y', '31-12-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'))
        ) {
            $weekdayChristmas = DateTime::createFromFormat(
                '!j-n-Y',
                '25-12-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            )->add(new \DateInterval('P' . $weekdayChristmasCnt . 'D'));
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($weekdayChristmas) && self::dateIsNotSunday($weekdayChristmas)) {
                $ordinal = ucfirst(LitMessages::getOrdinal(( $weekdayChristmasCnt + 1 ), $this->CalendarParams->Locale, $this->formatter, LitMessages::LATIN_ORDINAL));
                $name = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? sprintf("Dies %s Octavæ Nativitatis", $ordinal)
                    : sprintf(
                        /**translators: %s is an ordinal number (first, second...) */
                        _("%s Day of the Octave of Christmas"),
                        $ordinal
                    );
                $festivity = new Festivity($name, $weekdayChristmas, LitColor::WHITE, LitFeastType::MOBILE);
                $this->Cal->addFestivity("ChristmasWeekday" . $weekdayChristmasCnt, $festivity);
            }
            $weekdayChristmasCnt++;
        }
    }

    private function calculateWeekdaysLent(): void
    {

        //Day of the Month of Ash Wednesday
        $DoMAshWednesday = $this->Cal->getFestivity("AshWednesday")->date->format('j');
        $MonthAshWednesday = $this->Cal->getFestivity("AshWednesday")->date->format('n');
        $weekdayLent = DateTime::createFromFormat(
            '!j-n-Y',
            $DoMAshWednesday . '-' . $MonthAshWednesday . '-' . $this->CalendarParams->Year,
            new \DateTimeZone('UTC')
        );
        $weekdayLentCnt = 1;
        while ($weekdayLent >= $this->Cal->getFestivity("AshWednesday")->date && $weekdayLent < $this->Cal->getFestivity("PalmSun")->date) {
            $weekdayLent = DateTime::createFromFormat('!j-n-Y', $DoMAshWednesday . '-' . $MonthAshWednesday . '-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'))->add(new \DateInterval('P' . $weekdayLentCnt . 'D'));
            if (!$this->Cal->inSolemnities($weekdayLent) && self::dateIsNotSunday($weekdayLent)) {
                if ($weekdayLent > $this->Cal->getFestivity("Lent1")->date) {
                    $upper =  (int)$weekdayLent->format('z');
                    $diff = $upper -  (int)$this->Cal->getFestivity("Lent1")->date->format('z'); //day count between current day and First Sunday of Lent
                    $currentLentWeek = ( ( $diff - $diff % 7 ) / 7 ) + 1; //week count between current day and First Sunday of Lent
                    $ordinal = ucfirst(LitMessages::getOrdinal($currentLentWeek, $this->CalendarParams->Locale, $this->formatterFem, LitMessages::LATIN_ORDINAL_FEM_GEN));
                    $dayOfTheWeek = $this->CalendarParams->Locale == LitLocale::LATIN
                        ? LitMessages::LATIN_DAYOFTHEWEEK[ $weekdayLent->format('w') ]
                        : ucfirst($this->dayOfTheWeek->format($weekdayLent->format('U')));
                    $nthStr = $this->CalendarParams->Locale === LitLocale::LATIN
                        ? sprintf("Hebdomadæ %s Quadragesimæ", $ordinal)
                        : sprintf(
                            /**translators: %s is an ordinal number (first, second...) */
                            _("of the %s Week of Lent"),
                            $ordinal
                        );
                    $name = $dayOfTheWeek . " " .  $nthStr;
                    $festivity = new Festivity($name, $weekdayLent, LitColor::PURPLE, LitFeastType::MOBILE);
                } else {
                    $dayOfTheWeek = $this->CalendarParams->Locale == LitLocale::LATIN
                        ? LitMessages::LATIN_DAYOFTHEWEEK[ $weekdayLent->format('w') ]
                        : ucfirst($this->dayOfTheWeek->format($weekdayLent->format('U')));
                    $postStr = $this->CalendarParams->Locale === LitLocale::LATIN ? "post Feria IV Cinerum" : _("after Ash Wednesday");
                    $name = $dayOfTheWeek . " " . $postStr;
                    $festivity = new Festivity($name, $weekdayLent, LitColor::PURPLE, LitFeastType::MOBILE);
                }
                $this->Cal->addFestivity("LentWeekday" . $weekdayLentCnt, $festivity);
            }
            $weekdayLentCnt++;
        }
    }

    private function addMissalMemorialMessage(object $row)
    {
        $locale = strtoupper(LitLocale::$PRIMARY_LANGUAGE);
        /**translators:
         * 1. Grade or rank of the festivity
         * 2. Name of the festivity
         * 3. Day of the festivity
         * 4. Year from which the festivity has been added
         * 5. Source of the information
         * 6. Requested calendar year
         */
        $message = _('The %1$s \'%2$s\' has been added on %3$s since the year %4$d (%5$s), applicable to the year %6$d.');
        $this->Messages[] = sprintf(
            $message,
            $this->LitGrade->i18n($row->grade, false),
            $row->name,
            $locale === LitLocale::LATIN ? ( $row->date->format('j') . ' ' . LitMessages::LATIN_MONTHS[ (int)$row->date->format('n') ] ) :
                ( $locale === 'EN' ? $row->date->format('F jS') :
                    $this->dayAndMonth->format($row->date->format('U'))
                ),
            $row->year_since,
            $row->decree,
            $this->CalendarParams->Year
        );
    }

    private function calculateMemorials(int $grade = LitGrade::MEMORIAL, string $missal = RomanMissal::EDITIO_TYPICA_1970): void
    {
        if ($missal === RomanMissal::EDITIO_TYPICA_1970 && $grade === LitGrade::MEMORIAL) {
            $this->createImmaculateHeart();
        }
        $tempCal = array_filter($this->tempCal[ $missal ], function ($el) use ($grade) {
            return $el->grade === $grade;
        });
        foreach ($tempCal as $row) {
            //If it doesn't occur on a Sunday or a Solemnity or a Feast of the Lord or a Feast or an obligatory memorial, then go ahead and create the optional memorial
            $row->date = DateTime::createFromFormat('!j-n-Y', $row->day . '-' . $row->month . '-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
            if (self::dateIsNotSunday($row->date) && $this->Cal->notInSolemnitiesFeastsOrMemorials($row->date)) {
                $newFestivity = new Festivity($row->name, $row->date, $row->color, LitFeastType::FIXED, $row->grade, $row->common);
                //Calendar::debugWrite( "adding new memorial '$row->name', common vartype = " . gettype( $row->common ) . ", common = " . implode(', ', $row->common) );
                //Calendar::debugWrite( ">>> added new memorial '$newFestivity->name', common vartype = " . gettype( $newFestivity->common ) . ", common = " . implode(', ', $newFestivity->common) );

                $this->Cal->addFestivity($row->event_key, $newFestivity);

                $this->reduceMemorialsInAdventLentToCommemoration($row->date, $row);

                if ($missal === RomanMissal::EDITIO_TYPICA_TERTIA_2002) {
                    $row->year_since = 2002;
                    $row->decree = '<a href="https://press.vatican.va/content/salastampa/it/bollettino/pubblico/2002/03/22/0150/00449.html">'
                        . _('Vatican Press conference: Presentation of the Editio Typica Tertia of the Roman Missal')
                        . '</a>';
                    $this->addMissalMemorialMessage($row);
                } elseif ($missal === RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008) {
                    $row->year_since = 2008;
                    switch ($row->event_key) {
                        case "StPioPietrelcina":
                            $row->decree = RomanMissal::getName($missal);
                            break;
                        /**both of the following tags refer to the same decree, no need for a break between them */
                        case "LadyGuadalupe":
                        case "JuanDiego":
                            $langs = ["la" => "lt", "es" => "es"];
                            $lang = in_array(LitLocale::$PRIMARY_LANGUAGE, array_keys($langs)) ? $langs[LitLocale::$PRIMARY_LANGUAGE] : "lt";
                            $row->decree = "<a href=\"https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_$lang.html\">"
                                . _('Decree of the Congregation for Divine Worship')
                                . '</a>';
                            break;
                    }
                    $this->addMissalMemorialMessage($row);
                }
                if ($grade === LitGrade::MEMORIAL && $this->Cal->getFestivity($row->event_key)->grade > LitGrade::MEMORIAL_OPT) {
                    $this->removeWeekdaysEpiphanyOverridenByMemorials($row->event_key);
                }
            } else {
                if (false === $this->checkImmaculateHeartCoincidence($row->date, $row)) {
                    $this->handleCoincidence($row, RomanMissal::EDITIO_TYPICA_1970);
                } elseif ($missal === RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008) {
                    $this->handleCoincidence($row, RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008);
                }
            }
        }
        if ($missal === RomanMissal::EDITIO_TYPICA_TERTIA_2002 && $grade === LitGrade::MEMORIAL_OPT) {
            $this->handleSaintJaneFrancesDeChantal();
        }
    }

    private function reduceMemorialsInAdventLentToCommemoration(DateTime $currentFeastDate, \stdClass $row)
    {
        //If a fixed date optional memorial falls between 17 Dec. to 24 Dec., the Octave of Christmas or weekdays of the Lenten season,
        //it is reduced in rank to a Commemoration ( only the collect can be used )
        if ($this->Cal->inWeekdaysAdventChristmasLent($currentFeastDate)) {
            $this->Cal->setProperty($row->event_key, "grade", LitGrade::COMMEMORATION);
            /**translators:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Requested calendar year
             */
            $message = _('The %1$s \'%2$s\' either falls between 17 Dec. and 24 Dec., or during the Octave of Christmas, or on the weekdays of the Lenten season in the year %3$d, rank reduced to Commemoration.');
            $this->Messages[] = sprintf(
                $message,
                $this->LitGrade->i18n($row->grade, false),
                $row->name,
                $this->CalendarParams->Year
            );
        }
    }

    private function removeWeekdaysEpiphanyOverridenByMemorials(string $tag)
    {
        $festivity = $this->Cal->getFestivity($tag);
        if ($this->Cal->inWeekdaysEpiphany($festivity->date)) {
            $key = $this->Cal->weekdayEpiphanyKeyFromDate($festivity->date);
            if (false !== $key) {
                /**translators:
                 * 1. Grade or rank of the festivity that has been superseded
                 * 2. Name of the festivity that has been superseded
                 * 3. Grade or rank of the festivity that is superseding
                 * 4. Name of the festivity that is superseding
                 * 5. Requested calendar year
                 */
                $message = _('The %1$s \'%2$s\' is superseded by the %3$s \'%4$s\' in the year %5$d.');
                $this->Messages[] = sprintf(
                    $message,
                    $this->LitGrade->i18n($this->Cal->getFestivity($key)->grade),
                    $this->Cal->getFestivity($key)->name,
                    $this->LitGrade->i18n($festivity->grade, false),
                    $festivity->name,
                    $this->CalendarParams->Year
                );
                $this->Cal->removeFestivity($key);
            }
        }
    }

    private function handleCoincidence(\stdClass $row, string $missal = RomanMissal::EDITIO_TYPICA_1970)
    {
        $coincidingFestivity = $this->Cal->determineSundaySolemnityOrFeast($row->date, $this->CalendarParams);
        switch ($missal) {
            case RomanMissal::EDITIO_TYPICA_1970:
                $YEAR = 1970;
                $lang = in_array(LitLocale::$PRIMARY_LANGUAGE, ["de","en","it","la","pt"]) ? LitLocale::$PRIMARY_LANGUAGE : "en";
                $DECREE = "<a href=\"https://www.vatican.va/content/paul-vi/$lang/apost_constitutions/documents/hf_p-vi_apc_19690403_missale-romanum.html\">"
                    . _('Apostolic Constitution Missale Romanum')
                    . "</a>";
                break;
            case RomanMissal::EDITIO_TYPICA_TERTIA_2002:
                $YEAR = 2002;
                $DECREE = '<a href="https://press.vatican.va/content/salastampa/it/bollettino/pubblico/2002/03/22/0150/00449.html">'
                    . _('Vatican Press conference: Presentation of the Editio Typica Tertia of the Roman Missal')
                    . '</a>';
                break;
            case RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008:
                $YEAR = 2008;
                $DECREE = '';
                break;
        }
        /**translators:
         * 1. Grade or rank of the festivity that has been superseded
         * 2. Name of the festivity that has been superseded
         * 3. Edition of the Roman Missal
         * 4. Year in which the Edition of the Roman Missal was published
         * 5. Any possible decrees or sources about the edition of the Roman Missal
         * 6. Date in which the superseded festivity is usually celebrated
         * 7. Grade or rank of the festivity that is superseding
         * 8. Name of the festivity that is superseding
         * 9. Requested calendar year
         */
        $locale = strtoupper(LitLocale::$PRIMARY_LANGUAGE);
        $message = _('The %1$s \'%2$s\', added in the %3$s of the Roman Missal since the year %4$d (%5$s) and usually celebrated on %6$s, is suppressed by the %7$s \'%8$s\' in the year %9$d.');
        $this->Messages[] = sprintf(
            $message,
            $this->LitGrade->i18n($row->grade, false),
            $row->name,
            RomanMissal::getName($missal),
            $YEAR,
            $DECREE,
            $locale === LitLocale::LATIN ? ( $row->date->format('j') . ' ' . LitMessages::LATIN_MONTHS[  (int)$row->date->format('n') ] ) :
                ( $locale === 'EN' ? $row->date->format('F jS') :
                    $this->dayAndMonth->format($row->date->format('U'))
                ),
            $coincidingFestivity->grade,
            $coincidingFestivity->event->name,
            $this->CalendarParams->Year
        );
    }

    private function handleCoincidenceDecree(object $row): void
    {
        $url = $row->metadata->url;
        if (property_exists($row->metadata, 'url_lang_map') && str_contains($url, '%s')) {
            $lang = $this->getBestLangFromMap($row->metadata->url_lang_map);
            $url = sprintf($url, $lang);
        }
        $decree = '<a href="' . $url . '">' . _("Decree of the Congregation for Divine Worship") . '</a>';
        $coincidingFestivity = $this->Cal->determineSundaySolemnityOrFeast($row->festivity->date, $this->CalendarParams);
        $locale = strtoupper(LitLocale::$PRIMARY_LANGUAGE);
        $this->Messages[] = sprintf(
            /**translators:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Grade or rank of the superseding festivity
             * 7. Name of the superseding festivity
             * 8. Requested calendar year
             */
            _('The %1$s \'%2$s\', added on %3$s since the year %4$d (%5$s), is however superseded by a %6$s \'%7$s\' in the year %8$d.'),
            $this->LitGrade->i18n($row->festivity->grade),
            $row->festivity->name,
            $locale === LitLocale::LATIN ? ( $row->festivity->date->format('j') . ' ' . LitMessages::LATIN_MONTHS[ (int)$row->festivity->date->format('n') ] ) :
                ( $locale === 'EN' ? $row->festivity->date->format('F jS') :
                    $this->dayAndMonth->format($row->festivity->date->format('U'))
                ),
            $row->metadata->since_year,
            $decree,
            $coincidingFestivity->grade,
            $coincidingFestivity->event->name,
            $this->CalendarParams->Year
        );
    }

    private function checkImmaculateHeartCoincidence(DateTime $currentFeastDate, \stdClass $row): bool
    {
        $coincidence = false;
        //IMMACULATEHEART: in years when the memorial of the Immaculate Heart of Mary coincides with another obligatory memorial,
        //as happened in 2014 [ 28 June, Saint Irenaeus ] and 2015 [ 13 June, Saint Anthony of Padua ], both must be considered optional for that year
        //source: https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
        $ImmaculateHeart = $this->Cal->getFestivity("ImmaculateHeart");
        if ($ImmaculateHeart !== null) {
            if ((int)$row->grade === LitGrade::MEMORIAL) {
                if ($currentFeastDate->format('U') === $ImmaculateHeart->date->format('U')) {
                    $this->Cal->setProperty("ImmaculateHeart", "grade", LitGrade::MEMORIAL_OPT);
                    $festivity = $this->Cal->getFestivity($row->event_key);
                    if ($festivity === null) {
                        $festivity = new Festivity($row->name, $currentFeastDate, $row->color, LitFeastType::FIXED, LitGrade::MEMORIAL_OPT, $row->common);
                        $this->Cal->addFestivity($row->event_key, $festivity);
                    } else {
                        $this->Cal->setProperty($row->event_key, "grade", LitGrade::MEMORIAL_OPT);
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
                        $festivity->name,
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

    private function createFestivityFromDecree(object $row): void
    {
        if ($row->festivity->type === "mobile") {
            //we won't have a date defined for mobile festivites, we'll have to calculate them here case by case
            //otherwise we'll have to create a language that we can interpret in an automated fashion...
            //for example we can use strtotime
            if (property_exists($row->metadata, 'strtotime')) {
                switch (gettype($row->metadata->strtotime)) {
                    case 'object':
                        if (
                            property_exists($row->metadata->strtotime, 'day_of_the_week')
                            && property_exists($row->metadata->strtotime, 'relative_time')
                            && property_exists($row->metadata->strtotime, 'festivity_key')
                        ) {
                            $festivity = $this->Cal->getFestivity($row->metadata->strtotime->festivity_key);
                            if ($festivity !== null) {
                                $relString = '';
                                $row->festivity->date = clone( $festivity->date );
                                switch ($row->metadata->strtotime->relative_time) {
                                    case 'before':
                                        $row->festivity->date->modify("previous {$row->metadata->strtotime->day_of_the_week}");
                                         /**translators: e.g. 'Monday before Palm Sunday' */
                                        $relString = _('before');
                                        break;
                                    case 'after':
                                        $row->festivity->date->modify("next {$row->metadata->strtotime->day_of_the_week}");
                                        /**translators: e.g. 'Monday after Pentecost' */
                                        $relString = _('after');
                                        break;
                                    default:
                                        $this->Messages[] = sprintf(
                                            /**translators: 1. Name of the mobile festivity being created, 2. Name of the festivity that it is relative to */
                                            _('Cannot create mobile festivity \'%1$s\': can only be relative to festivity with key \'%2$s\' using keywords %3$s'),
                                            $row->festivity->name,
                                            $row->metadata->strtotime->festivity_key,
                                            implode(', ', ['\'before\'', '\'after\''])
                                        );
                                        break;
                                }
                                $dayOfTheWeek = $this->CalendarParams->Locale === LitLocale::LATIN
                                    ? LitMessages::LATIN_DAYOFTHEWEEK[ $row->festivity->date->format('w') ]
                                    : ucfirst($this->dayOfTheWeek->format($row->festivity->date->format('U')));
                                $row->metadata->added_when = $dayOfTheWeek . ' ' . $relString . ' ' . $festivity->name;
                                if (true === $this->checkCoincidencesNewMobileFestivity($row)) {
                                    $this->createMobileFestivity($row);
                                }
                            } else {
                                $this->Messages[] = sprintf(
                                    /**translators: 1. Name of the mobile festivity being created, 2. Name of the festivity that it is relative to */
                                    _('Cannot create mobile festivity \'%1$s\' relative to festivity with key \'%2$s\''),
                                    $row->festivity->name,
                                    $row->metadata->strtotime->festivity_key
                                );
                            }
                        } else {
                            $this->Messages[] = sprintf(
                                /**translators: Do not translate 'strtotime'! 1. Name of the mobile festivity being created 2. list of properties */
                                _('Cannot create mobile festivity \'%1$s\': when the \'strtotime\' property is an object, it must have properties %2$s'),
                                $row->festivity->name,
                                implode(', ', ['\'day_of_the_week\'', '\'relative_time\'', '\'festivity_key\''])
                            );
                        }
                        break;
                    case 'string':
                        if ($row->metadata->strtotime !== '') {
                            $festivityDateTS = strtotime($row->metadata->strtotime . ' ' . $this->CalendarParams->Year . ' UTC');
                            $row->festivity->date = new DateTime("@$festivityDateTS");
                            $row->festivity->date->setTimeZone(new \DateTimeZone('UTC'));
                            $row->metadata->added_when = $row->metadata->strtotime;
                            if (true === $this->checkCoincidencesNewMobileFestivity($row)) {
                                $this->createMobileFestivity($row);
                            }
                        }
                        break;
                    default:
                        $this->Messages[] = sprintf(
                            /**translators: Do not translate 'strtotime'! 1. Name of the mobile festivity being created */
                            _('Cannot create mobile festivity \'%1$s\': \'strtotime\' property must be either an object or a string! Currently it has type \'%2$s\''),
                            $row->festivity->name,
                            gettype($row->metadata->strtotime)
                        );
                }
            } else {
                $this->Messages[] = sprintf(
                    /**translators: Do not translate 'strtotime'! 1. Name of the mobile festivity being created */
                    _('Cannot create mobile festivity \'%1$s\' without a \'strtotime\' property!'),
                    $row->festivity->name
                );
            }
        } else {
            $row->festivity->date = DateTime::createFromFormat(
                '!j-n-Y',
                "{$row->festivity->day}-{$row->festivity->month}-{$this->CalendarParams->Year}",
                new \DateTimeZone('UTC')
            );
            $decree = $this->elaborateDecreeSource($row);
            $locale = strtoupper(LitLocale::$PRIMARY_LANGUAGE);
            if ($row->festivity->grade === LitGrade::MEMORIAL_OPT) {
                if ($this->Cal->notInSolemnitiesFeastsOrMemorials($row->festivity->date)) {
                    $festivity = new Festivity(
                        $row->festivity->name,
                        $row->festivity->date,
                        $row->festivity->color,
                        LitFeastType::FIXED,
                        $row->festivity->grade,
                        $row->festivity->common
                    );
                    $this->Cal->addFestivity($row->festivity->event_key, $festivity);
                    $this->Messages[] = sprintf(
                        /**translators:
                         * 1. Grade or rank of the festivity
                         * 2. Name of the festivity
                         * 3. Day of the festivity
                         * 4. Year from which the festivity has been added
                         * 5. Source of the information
                         * 6. Requested calendar year
                         */
                        _('The %1$s \'%2$s\' has been added on %3$s since the year %4$d (%5$s), applicable to the year %6$d.'),
                        $this->LitGrade->i18n($row->festivity->grade, false),
                        $row->festivity->name,
                        $locale === LitLocale::LATIN
                            ? ( $row->festivity->date->format('j') . ' ' . LitMessages::LATIN_MONTHS[ (int)$row->festivity->date->format('n') ] )
                            : ( $locale === 'EN' ? $row->festivity->date->format('F jS') :
                                $this->dayAndMonth->format($row->festivity->date->format('U'))
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
    }

    private function setPropertyBasedOnDecree(object $row): void
    {
        $festivity = $this->Cal->getFestivity($row->festivity->event_key);
        if ($festivity !== null) {
            $decree = $this->elaborateDecreeSource($row);
            switch ($row->metadata->property) {
                case "name":
                    //example: StMartha becomes Martha, Mary and Lazarus in 2021
                    $this->Cal->setProperty($row->festivity->event_key, "name", $row->festivity->name);
                    /**translators:
                     * 1. Grade or rank of the festivity
                     * 2. Name of the festivity
                     * 3. New name of the festivity
                     * 4. Year from which the grade has been changed
                     * 5. Requested calendar year
                     * 6. Source of the information
                     */
                    $message = _('The name of the %1$s \'%2$s\' has been changed to %3$s since the year %4$d, applicable to the year %5$d (%6$s).');
                    $this->Messages[] = sprintf(
                        $message,
                        $this->LitGrade->i18n($festivity->grade, false),
                        '<i>' . $festivity->name . '</i>',
                        '<i>' . $row->festivity->name . '</i>',
                        $row->metadata->since_year,
                        $this->CalendarParams->Year,
                        $decree
                    );
                    break;
                case "grade":
                    if ($row->festivity->grade > $festivity->grade) {
                        //example: StMaryMagdalene raised to Feast in 2016
                        /**translators:
                         * 1. Grade or rank of the festivity
                         * 2. Name of the festivity
                         * 3. New grade of the festivity
                         * 4. Year from which the grade has been changed
                         * 5. Requested calendar year
                         * 6. Source of the information
                         */
                        $message = _('The %1$s \'%2$s\' has been raised to the rank of %3$s since the year %4$d, applicable to the year %5$d (%6$s).');
                    } else {
                        /**translators:
                         * 1. Grade or rank of the festivity
                         * 2. Name of the festivity
                         * 3. New grade of the festivity
                         * 4. Year from which the grade has been changed
                         * 5. Requested calendar year
                         * 6. Source of the information
                         */
                        $message = _('The %1$s \'%2$s\' has been lowered to the rank of %3$s since the year %4$d, applicable to the year %5$d (%6$s).');
                    }
                    $this->Messages[] = sprintf(
                        $message,
                        $this->LitGrade->i18n($festivity->grade, false),
                        $festivity->name,
                        $this->LitGrade->i18n($row->festivity->grade, false),
                        $row->metadata->since_year,
                        $this->CalendarParams->Year,
                        $decree
                    );
                    $this->Cal->setProperty($row->festivity->event_key, "grade", $row->festivity->grade);
                    break;
            }
        }
    }

    private function createDoctorsFromDecrees(): void
    {
        $DoctorsDecrees = array_filter(
            $this->tempCal[ "MEMORIALS_FROM_DECREES" ],
            function ($row) {
                return $row->metadata->action === "makeDoctor";
            }
        );
        foreach ($DoctorsDecrees as $row) {
            if ($this->CalendarParams->Year >= $row->metadata->since_year) {
                $festivity = $this->Cal->getFestivity($row->festivity->event_key);
                if ($festivity !== null) {
                    $decree = $this->elaborateDecreeSource($row);
                    /**translators:
                     * 1. Name of the festivity
                     * 2. Year in which was declared Doctor
                     * 3. Requested calendar year
                     * 4. Source of the information
                     */
                    $message = _('\'%1$s\' has been declared a Doctor of the Church since the year %2$d, applicable to the year %3$d (%4$s).');
                    $this->Messages[] = sprintf(
                        $message,
                        '<i>' . $festivity->name . '</i>',
                        $row->metadata->since_year,
                        $this->CalendarParams->Year,
                        $decree
                    );
                    $etDoctor = $this->CalendarParams->Locale === LitLocale::LATIN ? " et Ecclesiæ doctoris" : " " . _("and Doctor of the Church");
                    $this->Cal->setProperty($row->festivity->event_key, "name", $festivity->name . $etDoctor);
                }
            }
        }
    }

    private function getBestLangFromMap(object $map): string
    {
        if (property_exists($map, LitLocale::$PRIMARY_LANGUAGE)) {
            return $map->{LitLocale::$PRIMARY_LANGUAGE};
        } elseif (property_exists($map, 'la')) {
            return $map->la;
        } elseif (property_exists($map, 'en')) {
            return $map->en;
        } else {
            $objIterator = new \ArrayIterator($map);
            return $objIterator->key();
        }
    }

    private function elaborateDecreeSource(object $row): string
    {
        $url = $row->metadata->url;
        if (property_exists($row->metadata, 'url_lang_map') && str_contains($url, '%s')) {
            $lang = $this->getBestLangFromMap($row->metadata->url_lang_map);
            $url = sprintf($url, $lang);
        }
        return '<a href="' . $url . '">' . _("Decree of the Congregation for Divine Worship") . '</a>';
    }

    private function applyDecrees(int|string $grade = LitGrade::MEMORIAL): void
    {
        if (!isset($this->tempCal[ "MEMORIALS_FROM_DECREES" ]) || !is_array($this->tempCal[ "MEMORIALS_FROM_DECREES" ])) {
            $message = "We seem to be missing data for Memorials based on Decrees: array data was not found!";
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
        }
        if (gettype($grade) === "integer") {
            $MemorialsFromDecrees = array_filter(
                $this->tempCal[ "MEMORIALS_FROM_DECREES" ],
                function ($row) use ($grade) {
                    return $row->metadata->action !== "makeDoctor" && $row->festivity->grade === $grade;
                }
            );
            foreach ($MemorialsFromDecrees as $row) {
                if ($this->CalendarParams->Year >= $row->metadata->since_year) {
                    switch ($row->metadata->action) {
                        case "createNew":
                            //example: MaryMotherChurch in 2018
                            $this->createFestivityFromDecree($row);
                            break;
                        case "setProperty":
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
        } elseif (gettype($grade) === "string" && $grade === "DOCTORS") {
            $this->createDoctorsFromDecrees();
        }
    }

    private function createMobileFestivity(object $row): void
    {
        $festivity = new Festivity(
            $row->festivity->name,
            $row->festivity->date,
            $row->festivity->color,
            LitFeastType::MOBILE,
            $row->festivity->grade,
            $row->festivity->common
        );
        $this->Cal->addFestivity($row->festivity->event_key, $festivity);
        $url = $row->metadata->url;
        if (property_exists($row->metadata, 'url_lang_map') && str_contains($url, '%s')) {
            $lang = $this->getBestLangFromMap($row->metadata->url_lang_map);
            $url = sprintf($url, $lang);
        }
        $decree = '<a href="' . $url . '">' . _("Decree of the Congregation for Divine Worship") . '</a>';

        $this->Messages[] = sprintf(
            /**translators:
             * 1. Grade or rank of the festivity being created
             * 2. Name of the festivity being created
             * 3. Indication of the mobile date for the festivity being created
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Requested calendar year
             */
            _('The %1$s \'%2$s\' has been added on %3$s since the year %4$d (%5$s), applicable to the year %6$d.'),
            $this->LitGrade->i18n($row->festivity->grade, false),
            $row->festivity->name,
            $row->metadata->added_when,
            $row->metadata->since_year,
            $decree,
            $this->CalendarParams->Year
        );
    }

    private function checkCoincidencesNewMobileFestivity(object $row): bool
    {
        if ($row->festivity->grade === LitGrade::MEMORIAL) {
            $url = $row->metadata->url;
            if (property_exists($row->metadata, 'url_lang_map') && str_contains($url, '%s')) {
                $lang = $this->getBestLangFromMap($row->metadata->url_lang_map);
                $url = sprintf($url, $lang);
            }
            $decree = '<a href="' . $url . '">' . _("Decree of the Congregation for Divine Worship") . '</a>';

            //A Memorial is superseded by Solemnities and Feasts, but not by Memorials of Saints
            if ($this->Cal->inSolemnities($row->festivity->date) || $this->Cal->inFeasts($row->festivity->date)) {
                if ($this->Cal->inSolemnities($row->festivity->date)) {
                    $coincidingFestivity = $this->Cal->solemnityFromDate($row->festivity->date);
                } else {
                    $coincidingFestivity = $this->Cal->feastOrMemorialFromDate($row->festivity->date);
                }

                $this->Messages[] = sprintf(
                    /**translators:
                     * 1. Grade or rank of the festivity being created
                     * 2. Name of the festivity being created
                     * 3. Indication of the mobile date for the festivity being created
                     * 4. Year from which the festivity has been added
                     * 5. Source of the information
                     * 6. Grade or rank of superseding festivity
                     * 7. Name of superseding festivity
                     * 8. Requested calendar year
                     */
                    _('The %1$s \'%2$s\', added on %3$s since the year %4$d (%5$s), is however superseded by the %6$s \'%7$s\' in the year %8$d.'),
                    $this->LitGrade->i18n($row->festivity->grade, false),
                    '<i>' . $row->festivity->name . '</i>',
                    $row->metadata->added_when,
                    $row->metadata->since_year,
                    $decree,
                    $coincidingFestivity->grade,
                    '<i>' . $coincidingFestivity->name . '</i>',
                    $this->CalendarParams->Year
                );
                return false;
            } else {
                if ($this->Cal->inCalendar($row->festivity->date)) {
                    $coincidingFestivities = $this->Cal->getCalEventsFromDate($row->festivity->date);
                    if (count($coincidingFestivities) > 0) {
                        foreach ($coincidingFestivities as $coincidingFestivityKey => $coincidingFestivity) {
                            $this->Messages[] = sprintf(
                                /**translators:
                                 * 1. Requested calendar year
                                 * 2. Grade or rank of suppressed festivity
                                 * 3. Name of suppressed festivity
                                 * 4. Grade or rank of the festivity being created
                                 * 5. Name of the festivity being created
                                 * 6. Indication of the mobile date for the festivity being created
                                 * 7. Year from which the festivity has been added
                                 * 8. Source of the information
                                 */
                                _('In the year %1$d, the %2$s \'%3$s\' has been suppressed by the %4$s \'%5$s\', added on %6$s since the year %7$d (%8$s).'),
                                $this->LitGrade->i18n($coincidingFestivity->grade, false),
                                '<i>' . $coincidingFestivity->name . '</i>',
                                $this->LitGrade->i18n($row->festivity->grade, false),
                                '<i>' . $row->festivity->name . '</i>',
                                $row->metadata->added_when,
                                $row->metadata->since_year,
                                $decree
                            );
                            $this->Cal->removeFestivity($coincidingFestivityKey);
                        }
                    }
                }
                return true;
            }
        }
        return false;
    }

    private function createImmaculateHeart()
    {
        $row = new \stdClass();
        $row->date = LitFunc::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P' . ( 7 * 9 + 6 ) . 'D'));
        if ($this->Cal->notInSolemnitiesFeastsOrMemorials($row->date)) {
            //Immaculate Heart of Mary fixed on the Saturday following the second Sunday after Pentecost
            //( see Calendarium Romanum Generale in Missale Romanum Editio Typica 1970 )
            //Pentecost = LitFunc::calcGregEaster( $this->CalendarParams->Year )->add( new \DateInterval( 'P'.( 7*7 ).'D' ) )
            //Second Sunday after Pentecost = LitFunc::calcGregEaster( $this->CalendarParams->Year )->add( new \DateInterval( 'P'.( 7*9 ).'D' ) )
            //Following Saturday = LitFunc::calcGregEaster( $this->CalendarParams->Year )->add( new \DateInterval( 'P'.( 7*9+6 ).'D' ) )
            $this->Cal->addFestivity(
                "ImmaculateHeart",
                new Festivity(
                    $this->PropriumDeTempore[ "ImmaculateHeart" ][ "name" ],
                    $row->date,
                    LitColor::WHITE,
                    LitFeastType::MOBILE,
                    LitGrade::MEMORIAL
                )
            );

            //In years when this memorial coincides with another obligatory memorial, as happened in 2014 [ 28 June, Saint Irenaeus ] and 2015 [ 13 June, Saint Anthony of Padua ], both must be considered optional for that year
            //source: https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
            //This is taken care of in the next code cycle, see tag IMMACULATEHEART: in the code comments ahead
        } else {
            $row = (object)$this->PropriumDeTempore[ "ImmaculateHeart" ];
            $row->grade = LitGrade::MEMORIAL;
            $row->date = LitFunc::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P' . ( 7 * 9 + 6 ) . 'D'));
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
    private function handleSaintJaneFrancesDeChantal()
    {
        $StJaneFrancesNewDate = DateTime::createFromFormat('!j-n-Y', '12-8-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
        $langs = ["la" => "lt", "es" => "es"];
        $lang = in_array(LitLocale::$PRIMARY_LANGUAGE, array_keys($langs)) ? $langs[LitLocale::$PRIMARY_LANGUAGE] : "lt";
        if (self::dateIsNotSunday($StJaneFrancesNewDate) && $this->Cal->notInSolemnitiesFeastsOrMemorials($StJaneFrancesNewDate)) {
            $festivity = $this->Cal->getFestivity("StJaneFrancesDeChantal");
            if ($festivity !== null) {
                $this->Cal->moveFestivityDate("StJaneFrancesDeChantal", $StJaneFrancesNewDate);
                $this->Messages[] = sprintf(
                    /**translators: 1: Festivity name, 2: Source of the information, 3: Requested calendar year  */
                    _('The optional memorial \'%1$s\' has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d.'),
                    $festivity->name,
                    "<a href=\"https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_$lang.html\">"
                        . _('Decree of the Congregation for Divine Worship')
                        . '</a>',
                    $this->CalendarParams->Year
                );
            } else {
                //perhaps it wasn't created on December 12th because it was superseded by a Sunday, Solemnity or Feast
                //but seeing that there is no problem for August 12th, let's go ahead and try creating it again
                $row = $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ][ 'StJaneFrancesDeChantal' ];
                $festivity = new Festivity($row->name, $StJaneFrancesNewDate, $row->color, LitFeastType::FIXED, $row->grade, $row->common);
                $this->Cal->addFestivity("StJaneFrancesDeChantal", $festivity);
                $this->Messages[] = sprintf(
                    /**translators: 1: Festivity name, 2: Source of the information, 3: Requested calendar year  */
                    _('The optional memorial \'%1$s\', which would have been superseded this year by a Sunday or Solemnity were it on Dec. 12, has however been transferred to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d.'),
                    $festivity->name,
                    "<a href=\"https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_$lang.html\">"
                        . _('Decree of the Congregation for Divine Worship')
                        . '</a>',
                    $this->CalendarParams->Year
                );
            }
        } else {
            $coincidingFestivity = $this->Cal->determineSundaySolemnityOrFeast($StJaneFrancesNewDate);
            $festivity = $this->Cal->getFestivity("StJaneFrancesDeChantal");
            //we can't move it, but we still need to remove it from Dec 12th if it's there!!!
            if ($festivity !== null) {
                $this->Cal->removeFestivity("StJaneFrancesDeChantal");
            }
            $row = $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ][ 'StJaneFrancesDeChantal' ];
            $this->Messages[] = sprintf(
                _('The optional memorial \'%1$s\' has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d. However, it is superseded by a Sunday, a Solemnity, or a Feast \'%4$s\' in the year %3$d.'),
                $row->name,
                "<a href=\"https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_$lang.html\">"
                    . _('Decree of the Congregation for Divine Worship')
                    . '</a>',
                $this->CalendarParams->Year,
                $coincidingFestivity->event->name
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
        $festivity = $this->Cal->getFestivity("ConversionStPaul");
        if ($festivity === null) {
            $row = $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ][ "ConversionStPaul" ];
            $festivity = new Festivity(
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
            $this->Cal->addFestivity("ConversionStPaul", $festivity);
            $langs = ["fr" => "fr", "en" => "en", "it" => "it", "la" => "lt", "pt" => "pt", "es" => "sp", "de" => "ge"];
            $lang = in_array(LitLocale::$PRIMARY_LANGUAGE, array_keys($langs)) ? $langs[LitLocale::$PRIMARY_LANGUAGE] : "en";
            $this->Messages[] = sprintf(
                /**translators: 1: Festivity name, 2: Source of the information  */
                _('The Feast \'%1$s\' would have been suppressed this year ( 2009 ) since it falls on a Sunday, however being the Year of the Apostle Paul, as per the %2$s it has been reinstated so that local churches can optionally celebrate the memorial.'),
                '<i>' . $row->name . '</i>',
                "<a href=\"https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20080125_san-paolo_$lang.html\">"
                    . _('Decree of the Congregation for Divine Worship')
                    . '</a>'
            );
        }
    }

    //13. Weekdays of Advent up until Dec. 16 included ( already calculated and defined together with weekdays 17 Dec. - 24 Dec. )
    //    Weekdays of Christmas season from 2 Jan. until the Saturday after Epiphany
    //    Weekdays of the Easter season, from the Monday after the Octave of Easter to the Saturday before Pentecost
    private function calculateWeekdaysMajorSeasons(): void
    {
        $DoMEaster = $this->Cal->getFestivity("Easter")->date->format('j');      //day of the month of Easter
        $MonthEaster = $this->Cal->getFestivity("Easter")->date->format('n');    //month of Easter
        //let's start cycling dates one at a time starting from Easter itself
        $weekdayEaster = DateTime::createFromFormat('!j-n-Y', $DoMEaster . '-' . $MonthEaster . '-' . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
        $weekdayEasterCnt = 1;
        while ($weekdayEaster >= $this->Cal->getFestivity("Easter")->date && $weekdayEaster < $this->Cal->getFestivity("Pentecost")->date) {
            $weekdayEaster = DateTime::createFromFormat(
                '!j-n-Y',
                $DoMEaster . '-' . $MonthEaster . '-' . $this->CalendarParams->Year,
                new \DateTimeZone('UTC')
            )->add(new \DateInterval('P' . $weekdayEasterCnt . 'D'));
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($weekdayEaster) && self::dateIsNotSunday($weekdayEaster)) {
                $upper =  (int)$weekdayEaster->format('z');
                $diff = $upper - (int)$this->Cal->getFestivity("Easter")->date->format('z'); //day count between current day and Easter Sunday
                $currentEasterWeek = ( ( $diff - $diff % 7 ) / 7 ) + 1; //week count between current day and Easter Sunday
                $ordinal = ucfirst(LitMessages::getOrdinal($currentEasterWeek, $this->CalendarParams->Locale, $this->formatterFem, LitMessages::LATIN_ORDINAL_FEM_GEN));
                $dayOfTheWeek = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? LitMessages::LATIN_DAYOFTHEWEEK[ $weekdayEaster->format('w') ]
                    : ucfirst($this->dayOfTheWeek->format($weekdayEaster->format('U')));
                $t = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? sprintf("Hebdomadæ %s Temporis Paschali", $ordinal)
                    : sprintf(_("of the %s Week of Easter"), $ordinal);
                $name = $dayOfTheWeek . " " . $t;
                $festivity = new Festivity($name, $weekdayEaster, LitColor::WHITE, LitFeastType::MOBILE);
                $festivity->psalter_week = $this->Cal::psalterWeek($currentEasterWeek);
                $this->Cal->addFestivity("EasterWeekday" . $weekdayEasterCnt, $festivity);
            }
            $weekdayEasterCnt++;
        }
    }

    //    Weekdays of Ordinary time
    private function calculateWeekdaysOrdinaryTime(): void
    {

        //In the first part of the year, weekdays of ordinary time begin the day after the Baptism of the Lord
        $FirstWeekdaysLowerLimit = $this->Cal->getFestivity("BaptismLord")->date;
        //and end with Ash Wednesday
        $FirstWeekdaysUpperLimit = $this->Cal->getFestivity("AshWednesday")->date;

        $ordWeekday = 1;
        $currentOrdWeek = 1;
        $firstOrdinary = DateTime::createFromFormat('!j-n-Y', $this->BaptismLordFmt, new \DateTimeZone('UTC'))->modify($this->BaptismLordMod);
        $firstSunday = DateTime::createFromFormat('!j-n-Y', $this->BaptismLordFmt, new \DateTimeZone('UTC'))->modify($this->BaptismLordMod)->modify('next Sunday');
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
                $ordinal = ucfirst(LitMessages::getOrdinal($currentOrdWeek, $this->CalendarParams->Locale, $this->formatterFem, LitMessages::LATIN_ORDINAL_FEM_GEN));
                $dayOfTheWeek = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? LitMessages::LATIN_DAYOFTHEWEEK[ $firstOrdinary->format('w') ]
                    : ucfirst($this->dayOfTheWeek->format($firstOrdinary->format('U')));
                $nthStr = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? sprintf("Hebdomadæ %s Temporis Ordinarii", $ordinal)
                    : sprintf(_("of the %s Week of Ordinary Time"), $ordinal);
                $name = $dayOfTheWeek . " " . $nthStr;
                $festivity = new Festivity($name, $firstOrdinary, LitColor::GREEN, LitFeastType::MOBILE);
                $festivity->psalter_week = $this->Cal::psalterWeek($currentOrdWeek);
                $this->Cal->addFestivity("FirstOrdWeekday" . $ordWeekday, $festivity);
            }
            $ordWeekday++;
        }

        //In the second part of the year, weekdays of ordinary time begin the day after Pentecost
        $SecondWeekdaysLowerLimit = $this->Cal->getFestivity("Pentecost")->date;
        //and end with the Feast of Christ the King
        $SecondWeekdaysUpperLimit = DateTime::createFromFormat(
            '!j-n-Y',
            '25-12-' . $this->CalendarParams->Year,
            new \DateTimeZone('UTC')
        )->modify('last Sunday')->sub(new \DateInterval('P' . ( 3 * 7 ) . 'D'));

        $ordWeekday = 1;
        //$currentOrdWeek = 1;
        $lastOrdinary = LitFunc::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P' . ( 7 * 7 ) . 'D'));
        $dayLastSunday =  (int)DateTime::createFromFormat(
            '!j-n-Y',
            '25-12-' . $this->CalendarParams->Year,
            new \DateTimeZone('UTC')
        )->modify('last Sunday')->sub(new \DateInterval('P' . ( 3 * 7 ) . 'D'))->format('z');

        while ($lastOrdinary >= $SecondWeekdaysLowerLimit && $lastOrdinary < $SecondWeekdaysUpperLimit) {
            $lastOrdinary = LitFunc::calcGregEaster($this->CalendarParams->Year)->add(new \DateInterval('P' . ( 7 * 7 + $ordWeekday ) . 'D'));
            if ($this->Cal->notInSolemnitiesFeastsOrMemorials($lastOrdinary)) {
                $lower          = (int)$lastOrdinary->format('z');
                $diff           = $dayLastSunday - $lower; //day count between current day and Christ the King Sunday
                $weekDiff       = ( ( $diff - $diff % 7 ) / 7 ); //week count between current day and Christ the King Sunday;
                $currentOrdWeek = 34 - $weekDiff;

                $ordinal = ucfirst(LitMessages::getOrdinal($currentOrdWeek, $this->CalendarParams->Locale, $this->formatterFem, LitMessages::LATIN_ORDINAL_FEM_GEN));
                $dayOfTheWeek = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? LitMessages::LATIN_DAYOFTHEWEEK[ $lastOrdinary->format('w') ]
                    : ucfirst($this->dayOfTheWeek->format($lastOrdinary->format('U')));
                $nthStr = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? sprintf("Hebdomadæ %s Temporis Ordinarii", $ordinal)
                    : sprintf(_("of the %s Week of Ordinary Time"), $ordinal);
                $name = $dayOfTheWeek . " " . $nthStr;
                $festivity = new Festivity($name, $lastOrdinary, LitColor::GREEN, LitFeastType::MOBILE);
                $festivity->psalter_week = $this->Cal::psalterWeek($currentOrdWeek);
                $this->Cal->addFestivity("LastOrdWeekday" . $ordWeekday, $festivity);
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
        $lastSatDT = new DateTime("last Saturday December {$this->CalendarParams->Year}", new \DateTimeZone('UTC'));
        $SatMemBVM_cnt = 0;
        while ($currentSaturday <= $lastSatDT) {
            $currentSaturday = DateTime::createFromFormat('!j-n-Y', $currentSaturday->format('j-n-Y'), new \DateTimeZone('UTC'))->modify('next Saturday');
            if ($this->Cal->inOrdinaryTime($currentSaturday) && $this->Cal->notInSolemnitiesFeastsOrMemorials($currentSaturday)) {
                $memID = "SatMemBVM" . ++$SatMemBVM_cnt;
                $name = $this->CalendarParams->Locale === LitLocale::LATIN
                    ? "Memoria Sanctæ Mariæ in Sabbato"
                    : _("Saturday Memorial of the Blessed Virgin Mary");
                $festivity = new Festivity($name, $currentSaturday, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::MEMORIAL_OPT, LitCommon::BEATAE_MARIAE_VIRGINIS);
                $this->Cal->addFestivity($memID, $festivity);
            }
        }
    }

    private function loadNationalCalendarData(): void
    {
        $nationalDataFile = "nations/{$this->CalendarParams->NationalCalendar}/{$this->CalendarParams->NationalCalendar}.json";
        if (file_exists($nationalDataFile)) {
            $this->NationalData = json_decode(file_get_contents($nationalDataFile));
            if (json_last_error() === JSON_ERROR_NONE) {
                if (property_exists($this->NationalData, "metadata") && property_exists($this->NationalData->metadata, "wider_region")) {
                    $widerRegionDataFile = $this->NationalData->metadata->wider_region->json_file;
                    $widerRegionI18nFile = $this->NationalData->metadata->wider_region->i18n_file;
                    if (file_exists($widerRegionI18nFile)) {
                        $widerRegionI18nData = json_decode(file_get_contents($widerRegionI18nFile));
                        if (json_last_error() === JSON_ERROR_NONE && file_exists($widerRegionDataFile)) {
                            $this->WiderRegionData = json_decode(file_get_contents($widerRegionDataFile));
                            if (json_last_error() === JSON_ERROR_NONE && property_exists($this->WiderRegionData, "litcal")) {
                                foreach ($this->WiderRegionData->litcal as $idx => $value) {
                                    $tag = $value->festivity->event_key;
                                    $this->WiderRegionData->litcal[$idx]->festivity->name = $widerRegionI18nData->{ $tag };
                                }
                            } else {
                                $this->Messages[] = sprintf(_("Error retrieving and decoding Wider Region data from file %s."), $widerRegionDataFile) . ": " . json_last_error_msg();
                            }
                        } else {
                            $this->Messages[] = sprintf(_("Error retrieving and decoding Wider Region data from file %s."), $widerRegionI18nFile) . ": " . json_last_error_msg();
                        }
                    }
                } else {
                    $this->Messages[] = "Could not find a WiderRegion property in the Metadata for the National Calendar {$this->CalendarParams->NationalCalendar}";
                }
            } else {
                $this->Messages[] = sprintf(_("Error retrieving and decoding National data from file %s."), $nationalDataFile) . ": " . json_last_error_msg();
            }
        }
    }

    private function handleMissingFestivity(object $row): void
    {
        $currentFeastDate = DateTime::createFromFormat('!j-n-Y', "{$row->festivity->day}-{$row->festivity->month}-" . $this->CalendarParams->Year, new \DateTimeZone('UTC'));
        //let's also get the name back from the database, so we can give some feedback and maybe even recreate the festivity
        if ($this->Cal->inSolemnitiesFeastsOrMemorials($currentFeastDate) || self::dateIsSunday($currentFeastDate)) {
            $coincidingFestivity = $this->Cal->determineSundaySolemnityOrFeast($currentFeastDate, $this->CalendarParams);
            if ($this->Cal->inFeastsOrMemorials($currentFeastDate)) {
                //we should probably be able to create it anyways in this case?
                $this->Cal->addFestivity(
                    $row->festivity->event_key,
                    new Festivity(
                        $row->festivity->name,
                        $currentFeastDate,
                        $row->festivity->color,
                        LitFeastType::FIXED,
                        $row->festivity->grade,
                        LitCommon::PROPRIO
                    )
                );
            }
            $this->Messages[] =  '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                /**translators:
                 * 1. Grade of the festivity
                 * 2. Name of the festivity
                 * 3. Date on which the festivity is usually celebrated
                 * 4. Grade of the superseding festivity
                 * 5. Name of the superseding festivity
                 * 6. Requested calendar year
                 */
                _('The %1$s \'%2$s\', usually celebrated on %3$s, is suppressed by the %4$s \'%5$s\' in the year %6$d.'),
                $this->LitGrade->i18n($row->festivity->grade, false),
                $row->festivity->name,
                $this->dayAndMonth->format($currentFeastDate->format('U')),
                $coincidingFestivity->grade,
                $coincidingFestivity->event->name,
                $this->CalendarParams->Year
            );
        }
    }

    private function festivityCanBeCreated(object $row): bool
    {
        switch ($row->festivity->grade) {
            case LitGrade::MEMORIAL_OPT:
                return $this->Cal->notInSolemnitiesFeastsOrMemorials($row->festivity->date);
            case LitGrade::MEMORIAL:
                return $this->Cal->notInSolemnitiesOrFeasts($row->festivity->date);
                //however we still have to handle possible coincidences with another memorial
            case LitGrade::FEAST:
                return $this->Cal->notInSolemnities($row->festivity->date);
                //however we still have to handle possible coincidences with another feast
            case LitGrade::SOLEMNITY:
                return true;
                //however we still have to handle possible coincidences with another solemnity
        }
        return false;
    }

    private function festivityDoesNotCoincide(object $row): bool
    {
        switch ($row->festivity->grade) {
            case LitGrade::MEMORIAL_OPT:
                return true;
                //optional memorials never have problems as regards coincidence with another optional memorial
            case LitGrade::MEMORIAL:
                return $this->Cal->notInMemorials($row->festivity->date);
            case LitGrade::FEAST:
                return $this->Cal->notInFeasts($row->festivity->date);
            case LitGrade::SOLEMNITY:
                return $this->Cal->notInSolemnities($row->festivity->date);
        }
        //functions should generally have a default return value
        //however, it would make no sense to give a default return value here
        //we really need to cover all cases and give a sure return value
    }

    private function handleFestivityCreationWithCoincidence(object $row): void
    {
        switch ($row->festivity->grade) {
            case LitGrade::MEMORIAL:
                //both memorials become optional memorials
                $coincidingFestivities = $this->Cal->getCalEventsFromDate($row->festivity->date);
                $coincidingMemorials = array_filter($coincidingFestivities, function ($el) {
                    return $el->grade === LitGrade::MEMORIAL;
                });
                $coincidingMemorialName = '';
                foreach ($coincidingMemorials as $key => $value) {
                    $this->Cal->setProperty($key, "grade", LitGrade::MEMORIAL_OPT);
                    $coincidingMemorialName = $value->name;
                }
                $festivity = new Festivity(
                    $row->festivity->name,
                    $row->festivity->date,
                    $row->festivity->color,
                    LitFeastType::FIXED,
                    LitGrade::MEMORIAL_OPT,
                    $row->festivity->common
                );
                $this->Cal->addFestivity($row->festivity->event_key, $festivity);
                $this->Messages[] = sprintf(
                    /**translators:
                     * 1. Name of the first coinciding Memorial
                     * 2. Name of the second coinciding Memorial
                     * 3. Requested calendar year
                     * 4. Source of the information
                     */
                    _('The Memorial \'%1$s\' coincides with another Memorial \'%2$s\' in the year %3$d. They are both reduced in rank to optional memorials.'),
                    $row->festivity->name,
                    $coincidingMemorialName,
                    $this->CalendarParams->Year
                );
                break;
            case LitGrade::FEAST:
                //there seems to be a coincidence with a different Feast on the same day!
                //what should we do about this? perhaps move one of them?
                $coincidingFestivities = $this->Cal->getCalEventsFromDate($row->festivity->date);
                $coincidingFeasts = array_filter($coincidingFestivities, function ($el) {
                    return $el->grade === LitGrade::FEAST;
                });
                $coincidingFeastName = '';
                foreach ($coincidingFeasts as $key => $value) {
                    //$this->Cal->setProperty( $key, "grade", LitGrade::MEMORIAL_OPT );
                    $coincidingFeastName = $value->name;
                }
                $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> '
                    . $this->CalendarParams->NationalCalendar . ": "
                    . sprintf(
                        /**translators: 1. Festivity name, 2. Festivity date, 3. Coinciding festivity name, 4. Requested calendar year */
                        _('The Feast \'%1$s\', usually celebrated on %2$s, coincides with another Feast \'%3$s\' in the year %4$d! Does something need to be done about this?'),
                        '<b>' . $row->festivity->name . '</b>',
                        '<b>' . $this->dayAndMonth->format($row->festivity->date->format('U')) . '</b>',
                        '<b>' . $coincidingFeastName . '</b>',
                        $this->CalendarParams->Year
                    );
                break;
            case LitGrade::SOLEMNITY:
                //there seems to be a coincidence with a different Solemnity on the same day!
                //should we attempt to move to the next open slot?
                $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> '
                    . $this->CalendarParams->NationalCalendar . ": "
                    . sprintf(
                        /**translators: 1. Festivity name, 2. Festivity date, 3. Coinciding festivity name, 4. Requested calendar year */
                        _('The Solemnity \'%1$s\', usually celebrated on %2$s, coincides with the Sunday or Solemnity \'%3$s\' in the year %4$d! Does something need to be done about this?'),
                        '<i>' . $row->festivity->name . '</i>',
                        '<b>' . $this->dayAndMonth->format($row->festivity->date->format('U')) . '</b>',
                        '<i>' . $this->Cal->solemnityFromDate($row->festivity->date)->name . '</i>',
                        $this->CalendarParams->Year
                    );
                break;
        }
    }

    private function createNewRegionalOrNationalFestivity(object $row): void
    {
        if (
            property_exists($row->festivity, 'strtotime')
            && $row->festivity->strtotime !== ''
        ) {
            $festivityDateTS = strtotime($row->festivity->strtotime . ' ' . $this->CalendarParams->Year . ' UTC');
            $row->festivity->date = new DateTime("@$festivityDateTS");
            $row->festivity->date->setTimeZone(new \DateTimeZone('UTC'));
        } elseif (
            property_exists($row->festivity, 'month')
            && $row->festivity->month >= 1
            && $row->festivity->month <= 12
            && property_exists($row->festivity, 'day')
            && $row->festivity->day >= 1
            && $row->festivity->day <= cal_days_in_month(CAL_GREGORIAN, $row->festivity->month, $this->CalendarParams->Year)
        ) {
            $row->festivity->date = DateTime::createFromFormat(
                '!j-n-Y',
                "{$row->festivity->day}-{$row->festivity->month}-{$this->CalendarParams->Year}",
                new \DateTimeZone('UTC')
            );
        } else {
            ob_start();
            var_dump($row);
            $a = ob_get_contents();
            ob_end_clean();
            $this->Messages[] = _('We should be creating a new festivity, however we do not seem to have the correct date information in order to proceed') . ' :: ' . $a;
            return;
        }
        if ($this->festivityCanBeCreated($row)) {
            if ($this->festivityDoesNotCoincide($row)) {
                if (!property_exists($row->festivity, 'type') || !LitFeastType::isValid($row->festivity->type)) {
                    $row->festivity->type = property_exists($row->festivity, 'strtotime') ? LitFeastType::MOBILE : LitFeastType::FIXED;
                }
                $festivity = new Festivity(
                    $row->festivity->name,
                    $row->festivity->date,
                    $row->festivity->color,
                    $row->festivity->type,
                    $row->festivity->grade,
                    $row->festivity->common
                );
                $this->Cal->addFestivity($row->festivity->event_key, $festivity);
            } else {
                $this->handleFestivityCreationWithCoincidence($row);
            }
            $infoSource = 'unknown';
            if (property_exists($row->metadata, 'url')) {
                $infoSource = $this->elaborateDecreeSource($row);
            } elseif (property_exists($row->metadata, 'missal')) {
                $infoSource = RomanMissal::getName($row->metadata->missal);
            }

            $locale = strtoupper(LitLocale::$PRIMARY_LANGUAGE);
            $formattedDateStr = $this->CalendarParams->Locale === LitLocale::LATIN
                ? ( $row->festivity->date->format('j') . ' ' . LitMessages::LATIN_MONTHS[ (int)$row->festivity->date->format('n') ] )
                : ( $locale === 'EN'
                    ? $row->festivity->date->format('F jS')
                    : $this->dayAndMonth->format($row->festivity->date->format('U'))
                );
            $dateStr = property_exists($row->festivity, 'strtotime') && $row->festivity->strtotime !== ''
                ? '<i>' . $row->festivity->strtotime . '</i>'
                : $formattedDateStr;
            $this->Messages[] = sprintf(
                /**translators:
                 * 1. Grade or rank of the festivity
                 * 2. Name of the festivity
                 * 3. Day of the festivity
                 * 4. Year from which the festivity has been added
                 * 5. Source of the information
                 * 6. Requested calendar year
                 */
                _('The %1$s \'%2$s\' has been added on %3$s since the year %4$d (%5$s), applicable to the year %6$d.'),
                $this->LitGrade->i18n($row->festivity->grade, false),
                $row->festivity->name,
                $dateStr,
                $row->metadata->since_year,
                $infoSource,
                $this->CalendarParams->Year
            );
        }// else {
            //$this->handleCoincidenceDecree( $row );
        //}
    }

    private function handleNationalCalendarRows(array $rows): void
    {
        foreach ($rows as $row) {
            if ($this->CalendarParams->Year >= $row->metadata->since_year) {
                if (property_exists($row->metadata, "until_year") && $this->CalendarParams->Year >= $row->metadata->until_year) {
                    continue;
                } else {
                    //if either the property doesn't exist (so no limit is set)
                    //or there is a limit but we are within those limits
                    switch ($row->metadata->action) {
                        case "makePatron":
                            $festivity = $this->Cal->getFestivity($row->festivity->event_key);
                            if ($festivity !== null) {
                                if ($festivity->grade !== $row->festivity->grade) {
                                    $this->Cal->setProperty($row->festivity->event_key, "grade", $row->festivity->grade);
                                }
                                $this->Cal->setProperty($row->festivity->event_key, "name", $row->festivity->name);
                            } else {
                                $this->handleMissingFestivity($row);
                            }
                            break;
                        case "createNew":
                            $this->createNewRegionalOrNationalFestivity($row);
                            break;
                        case "setProperty":
                            switch ($row->metadata->property) {
                                case "name":
                                    $this->Cal->setProperty($row->festivity->event_key, "name", $row->festivity->name);
                                    break;
                                case "grade":
                                    $this->Cal->setProperty($row->festivity->event_key, "grade", $row->festivity->grade);
                                    break;
                            }
                            break;
                        case "moveFestivity":
                            $festivityNewDate = DateTime::createFromFormat(
                                '!j-n-Y',
                                $row->festivity->day . '-' . $row->festivity->month . '-' . $this->CalendarParams->Year,
                                new \DateTimeZone('UTC')
                            );
                            $this->moveFestivityDate($row->festivity->event_key, $festivityNewDate, $row->metadata->reason, $row->metadata->missal);
                            break;
                    }
                }
            }
        }
    }

    private function applyNationalCalendar(): void
    {
        //first thing is apply any wider region festivities, such as Patron Saints of the Wider Region (example: Europe)
        if ($this->WiderRegionData !== null && property_exists($this->WiderRegionData, "litcal")) {
            $this->handleNationalCalendarRows($this->WiderRegionData->litcal);
        }

        if ($this->NationalData !== null && property_exists($this->NationalData, "litcal")) {
            $this->handleNationalCalendarRows($this->NationalData->litcal);
        }

        if ($this->NationalData !== null && property_exists($this->NationalData, "metadata") && property_exists($this->NationalData->metadata, "missals")) {
            if ($this->NationalData->metadata->region === 'UNITED STATES') {
                $this->NationalData->metadata->region = 'USA';
            }
            $this->Messages[] = "Found Missals for region " . $this->NationalData->metadata->region . ": " . implode(', ', $this->NationalData->metadata->missals);
            foreach ($this->NationalData->metadata->missals as $missal) {
                $yearLimits = RomanMissal::getYearLimits($missal);
                if ($this->CalendarParams->Year >= $yearLimits->since_year) {
                    if (property_exists($yearLimits, "until_year") && $this->CalendarParams->Year >= $yearLimits->until_year) {
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
                                $currentFeastDate = DateTime::createFromFormat(
                                    '!j-n-Y',
                                    $row->day . '-' . $row->month . '-' . $this->CalendarParams->Year,
                                    new \DateTimeZone('UTC')
                                );
                                if (!$this->Cal->inSolemnitiesOrFeasts($currentFeastDate)) {
                                    $festivity = new Festivity(
                                        "[ {$this->NationalData->metadata->region} ] " . $row->name,
                                        $currentFeastDate,
                                        $row->color,
                                        LitFeastType::FIXED,
                                        $row->grade,
                                        $row->common,
                                        $row->display_grade
                                    );
                                    $this->Cal->addFestivity($row->event_key, $festivity);
                                } else {
                                    if (self::dateIsSunday($currentFeastDate) && $row->event_key === "PrayerUnborn") {
                                        $festivity = new Festivity(
                                            "[ USA ] " . $row->name,
                                            $currentFeastDate->add(new \DateInterval('P1D')),
                                            $row->color,
                                            LitFeastType::FIXED,
                                            $row->grade,
                                            $row->common,
                                            $row->display_grade
                                        );
                                        $this->Cal->addFestivity($row->event_key, $festivity);
                                        $this->Messages[] = sprintf(
                                            "USA: The National Day of Prayer for the Unborn is set to Jan 22 as per the 2011 Roman Missal issued by the USCCB, however since it coincides with a Sunday or a Solemnity in the year %d, it has been moved to Jan 23",
                                            $this->CalendarParams->Year
                                        );
                                    } else {
                                        $coincidingFestivity = $this->Cal->determineSundaySolemnityOrFeast($currentFeastDate, $this->CalendarParams);
                                        $this->Messages[] = sprintf(
                                            /**translators:
                                             * 1. Festivity grade
                                             * 2. Festivity name
                                             * 3. Festivity date
                                             * 4. Edition of the Roman Missal
                                             * 5. Superseding festivity grade
                                             * 6. Superseding festivity name
                                             * 7. Requested calendar year
                                             */
                                            $this->NationalData->metadata->region . ": " . _('The %1$s \'%2$s\' (%3$s), added to the national calendar in the %4$s, is superseded by the %5$s \'%6$s\' in the year %7$d'),
                                            $row->display_grade !== "" ? $row->display_grade : $this->LitGrade->i18n($row->grade, false),
                                            '<i>' . $row->name . '</i>',
                                            $this->dayAndMonth->format($currentFeastDate->format('U')),
                                            RomanMissal::getName($missal),
                                            $coincidingFestivity->grade,
                                            $coincidingFestivity->event->name,
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
            if ($this->NationalData !== null && property_exists($this->NationalData, 'metadata') && property_exists($this->NationalData->metadata, 'region')) {
                $this->Messages[] = "Did not find any Missals for region " . $this->NationalData->metadata->region;
            }
        }
    }

    /**
     * So far, the celebrations being transferred are all originally from the 1970 Editio Typica
     * and they are currently only celebrations from the USA calendar...
     * However the method should work for any calendar
     */
    private function moveFestivityDate(string $tag, DateTime $newDate, string $inFavorOf, $missal)
    {
        $festivity = $this->Cal->getFestivity($tag);
        $newDateStr = $newDate->format('F jS');
        if (!$this->Cal->inSolemnitiesFeastsOrMemorials($newDate)) {
            if ($festivity !== null) {
                $oldDateStr = $festivity->date->format('F jS');
                $this->Cal->moveFestivityDate($tag, $newDate);
            } else {
                //if it was suppressed on the original date because of a higher ranking celebration,
                //we should recreate it on the new date
                //except in the case of Saint Vincent Deacon when we're dealing with the Roman Missal USA edition,
                //since the National Day of Prayer will take over the new date
                if ($tag !== "StVincentDeacon" || $missal !== RomanMissal::USA_EDITION_2011) {
                    $row = $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ][ $tag ];
                    $festivity = new Festivity($row->name, $newDate, $row->color, LitFeastType::FIXED, $row->grade, $row->common);
                    $this->Cal->addFestivity($tag, $festivity);
                    $oldDate = DateTime::createFromFormat(
                        '!j-n-Y',
                        $row->day . '-' . $row->month . '-' . $this->CalendarParams->Year,
                        new \DateTimeZone('UTC')
                    );
                    $oldDateStr = $oldDate->format('F jS');
                }
            }

            //If the festivity has been successfully recreated, let's make a note about that
            if ($festivity !== null) {
                $this->Messages[] = sprintf(
                    /**translators: 1. Festivity grade, 2. Festivity name, 3. New festivity name, 4: Requested calendar year, 5. Old date, 6. New date */
                    _('The %1$s \'%2$s\' is transferred from %5$s to %6$s as per the %7$s, to make room for \'%3$s\': applicable to the year %4$d.'),
                    $this->LitGrade->i18n($festivity->grade),
                    '<i>' . $festivity->name . '</i>',
                    '<i>' . $inFavorOf . '</i>',
                    $this->CalendarParams->Year,
                    $oldDateStr,
                    $newDateStr,
                    RomanMissal::getName($missal)
                );
                //$this->Cal->setProperty( $tag, "name", "[ USA ] " . $festivity->name );
            }
        } else {
            if ($festivity !== null) {
                $oldDateStr = $festivity->date->format('F jS');
                $coincidingFestivity = $this->Cal->determineSundaySolemnityOrFeast($newDate);
                //If the new date is already covered by a Solemnity, Feast or Memorial, then we can't move the celebration, so we simply suppress it
                $this->Messages[] = sprintf(
                    /**translators: 1. Festivity grade, 2. Festivity name, 3. Old date, 4. New date, 5. Source of the information, 6. New festivity name, 7. Superseding festivity grade, 8. Superseding festivity name, 9: Requested calendar year */
                    _('The %1$s \'%2$s\' would have been transferred from %3$s to %4$s as per the %5$s, to make room for \'%6$s\', however it is suppressed by the %7$s \'%8$s\' in the year %9$d.'),
                    $this->LitGrade->i18n($festivity->grade),
                    '<i>' . $festivity->name . '</i>',
                    $oldDateStr,
                    $newDateStr,
                    RomanMissal::getName($missal),
                    '<i>' . $inFavorOf . '</i>',
                    $coincidingFestivity->grade,
                    $coincidingFestivity->event->name,
                    $this->CalendarParams->Year
                );
                $this->Cal->removeFestivity($tag);
            }
        }
    }

    private static function dateIsSunday(DateTime $dt): bool
    {
        return (int)$dt->format('N') === 7;
    }

    private static function dateIsNotSunday(DateTime $dt): bool
    {
        return (int)$dt->format('N') !== 7;
    }

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
        //these will be dealt with later when loading Local Calendar Data

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
        $this->applyDecrees("DOCTORS");

        //13. Weekdays of Advent up until Dec. 16 included ( already calculated and defined together with weekdays 17 Dec. - 24 Dec. )
        //    Weekdays of Christmas season from 2 Jan. until the Saturday after Epiphany
        //    Weekdays of the Easter season, from the Monday after the Octave of Easter to the Saturday before Pentecost
        //    Weekdays of Ordinary time
        $this->calculateWeekdaysMajorSeasons();
        $this->calculateWeekdaysOrdinaryTime();

        //15. On Saturdays in Ordinary Time when there is no obligatory memorial, an optional memorial of the Blessed Virgin Mary is allowed.
        // We will handle this after having set National and Diocesan calendar data
        // see :SATURDAY_MEMORIAL_BVM
    }

    private function interpretStrtotime(object $row): DateTime|false
    {
        switch (gettype($row->metadata->strtotime)) {
            case 'object':
                if (
                    property_exists($row->metadata->strtotime, 'day_of_the_week')
                    && property_exists($row->metadata->strtotime, 'relative_time')
                    && property_exists($row->metadata->strtotime, 'festivity_key')
                ) {
                    $festivity = $this->Cal->getFestivity($row->metadata->strtotime->festivity_key);
                    if ($festivity !== null) {
                        //$relString = '';
                        $DATE = clone( $festivity->date );
                        switch ($row->metadata->strtotime->relative_time) {
                            case 'before':
                                $DATE->modify("previous {$row->metadata->strtotime->day_of_the_week}");
                                    /**translators: e.g. 'Monday before Palm Sunday' */
                                //$relString = _( 'before' );
                                return $DATE;
                                //break; //unnecessary seeing we are returning immediately
                            case 'after':
                                $DATE->modify("next {$row->metadata->strtotime->day_of_the_week}");
                                /**translators: e.g. 'Monday after Pentecost' */
                                //$relString = _( 'after' );
                                return $DATE;
                                //break; //unnecessary seeing we are returning immediately
                            default:
                                $this->Messages[] = sprintf(
                                    /**translators: 1. Name of the mobile festivity being created, 2. Name of the festivity that it is relative to */
                                    _('Cannot create mobile festivity \'%1$s\': can only be relative to festivity with key \'%2$s\' using keywords %3$s'),
                                    $row->festivity->name,
                                    $row->metadata->strtotime->festivity_key,
                                    implode(', ', ['\'before\'', '\'after\''])
                                );
                                return false;
                                //break; //unnecessary seeing we are returning immediately
                        }
                        /*
                        $dayOfTheWeek = $this->CalendarParams->Locale === LitLocale::LATIN
                            ? LitMessages::LATIN_DAYOFTHEWEEK[ $DATE->format( 'w' ) ]
                            : ucfirst( $this->dayOfTheWeek->format( $row->festivity->date->format( 'U' ) ) );
                        $row->metadata->added_when = $day_of_the_week . ' ' . $relString . ' ' . $festivity->name;
                        if( true === $this->checkCoincidencesNewMobileFestivity( $row ) ) {
                            $this->createMobileFestivity( $row );
                        }
                        */
                    } else {
                        $this->Messages[] = sprintf(
                            /**translators: 1. Name of the mobile festivity being created, 2. Name of the festivity that it is relative to */
                            _('Cannot create mobile festivity \'%1$s\' relative to festivity with key \'%2$s\''),
                            $row->festivity->name,
                            $row->metadata->strtotime->festivity_key
                        );
                        return false;
                    }
                } else {
                    $this->Messages[] = sprintf(
                        /**translators: Do not translate 'strtotime'! 1. Name of the mobile festivity being created 2. list of properties */
                        _('Cannot create mobile festivity \'%1$s\': when the \'strtotime\' property is an object, it must have properties %2$s'),
                        $row->festivity->name,
                        implode(', ', ['\'day_of_the_week\'', '\'relative_time\'', '\'festivity_key\''])
                    );
                    return false;
                }
                break;
            case 'string':
                if ($row->metadata->strtotime !== '') {
                    if (preg_match('/(before|after)/', $row->metadata->strtotime) !== false && preg_match('/(before|after)/', $row->metadata->strtotime) !== 0) {
                        $match = preg_split('/(before|after)/', $row->metadata->strtotime, -1, PREG_SPLIT_DELIM_CAPTURE);
                        if ($match !== false && count($match) === 3) {
                            $festivityDateTS = strtotime($match[2] . ' ' . $this->CalendarParams->Year . ' UTC');
                            if ($festivityDateTS !== false) {
                                $DATE = new DateTime("@$festivityDateTS");
                                $DATE->setTimeZone(new \DateTimeZone('UTC'));
                                if ($match[1] === 'before') {
                                    $DATE->modify("previous {$match[0]}");
                                } elseif ($match[1] === 'after') {
                                    $DATE->modify("next {$match[0]}");
                                }
                                return $DATE;
                            } else {
                                $this->Messages[] = sprintf(
                                    /**translators: Do not translate 'strtotime'! */
                                    'Could not interpret the \'strtotime\' property with value %1$s into a timestamp',
                                    $row->metadata->strtotime
                                );
                                return false;
                            }
                        } else {
                            $this->Messages[] = sprintf(
                                /**translators: Do not translate 'strtotime'! */
                                'Could not interpret the \'strtotime\' property with value %1$s into a timestamp. Splitting failed: %2$s',
                                $row->metadata->strtotime,
                                json_encode($match)
                            );
                            return false;
                        }
                    } else {
                        $festivityDateTS = strtotime($row->metadata->strtotime . ' ' . $this->CalendarParams->Year . ' UTC');
                        if ($festivityDateTS !== false) {
                            $DATE = new DateTime("@$festivityDateTS");
                            $DATE->setTimeZone(new \DateTimeZone('UTC'));
                            //$row->metadata->added_when = $row->metadata->strtotime;
                            /*if( true === $this->checkCoincidencesNewMobileFestivity( $row ) ) {
                                $this->createMobileFestivity( $row );
                            }*/
                            return $DATE;
                        } else {
                            $this->Messages[] = sprintf(
                                /**translators: Do not translate 'strtotime'! */
                                'Could not interpret the \'strtotime\' property with value %1$s into a timestamp',
                                $row->metadata->strtotime
                            );
                            return false;
                        }
                    }
                } else {
                    return false;
                }
                break;
            default:
                $this->Messages[] = sprintf(
                    /**translators: Do not translate 'strtotime'! 1. Name of the mobile festivity being created */
                    _('Cannot create mobile festivity \'%1$s\': \'strtotime\' property must be either an object or a string! Currently it has type \'%2$s\''),
                    $row->festivity->name,
                    gettype($row->metadata->strtotime)
                );
                return false;
        }
    }

    private function applyDiocesanCalendar()
    {
        foreach ($this->DiocesanData->litcal as $key => $obj) {
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
                if (property_exists($obj->metadata, 'strtotime')) {
                    $currentFeastDate = $this->interpretStrtotime($obj, $key);
                } else {
                    $currentFeastDate = DateTime::createFromFormat(
                        '!j-n-Y',
                        $obj->festivity->day . '-' . $obj->festivity->month . '-' . $this->CalendarParams->Year,
                        new \DateTimeZone('UTC')
                    );
                }
                if ($currentFeastDate !== false) {
                    if ($obj->festivity->grade > LitGrade::FEAST) {
                        if ($this->Cal->inSolemnities($currentFeastDate) && $key != $this->Cal->solemnityKeyFromDate($currentFeastDate)) {
                            //there seems to be a coincidence with a different Solemnity on the same day!
                            //should we attempt to move to the next open slot?
                            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> '
                                . $this->CalendarParams->DiocesanCalendar . ": "
                                .  sprintf(
                                    /**translators: 1: Festivity name, 2: Name of the diocese, 3: Festivity date, 4: Coinciding festivity name, 5: Requested calendar year */
                                    _('The Solemnity \'%1$s\', proper to the calendar of the %2$s and usually celebrated on %3$s, coincides with the Sunday or Solemnity \'%4$s\' in the year %5$d! Does something need to be done about this?'),
                                    '<i>' . $obj->festivity->name . '</i>',
                                    $this->GeneralIndex->{$this->CalendarParams->DiocesanCalendar}->diocese,
                                    '<b>' . $this->dayAndMonth->format($currentFeastDate->format('U')) . '</b>',
                                    '<i>' . $this->Cal->solemnityFromDate($currentFeastDate)->name . '</i>',
                                    $this->CalendarParams->Year
                                );
                        }
                        $this->Cal->addFestivity(
                            $this->CalendarParams->DiocesanCalendar . "_" . $key,
                            new Festivity(
                                "[ " . $this->GeneralIndex->{$this->CalendarParams->DiocesanCalendar}->diocese . " ] " . $obj->festivity->name,
                                $currentFeastDate,
                                $obj->festivity->color,
                                LitFeastType::FIXED,
                                $obj->festivity->grade,
                                $obj->festivity->common
                            )
                        );
                    } elseif ($obj->festivity->grade <= LitGrade::FEAST && !$this->Cal->inSolemnities($currentFeastDate)) {
                        $this->Cal->addFestivity(
                            $this->CalendarParams->DiocesanCalendar . "_" . $key,
                            new Festivity(
                                "[ " . $this->GeneralIndex->{$this->CalendarParams->DiocesanCalendar}->diocese . " ] " . $obj->festivity->name,
                                $currentFeastDate,
                                $obj->festivity->color,
                                LitFeastType::FIXED,
                                $obj->festivity->grade,
                                $obj->festivity->common
                            )
                        );
                    } else {
                        $this->Messages[] = $this->CalendarParams->DiocesanCalendar . ": " . sprintf(
                            /**translators: 1: Festivity grade, 2: Festivity name, 3: Name of the diocese, 4: Festivity date, 5: Coinciding festivity name, 6: Requested calendar year */
                            _('The %1$s \'%2$s\', proper to the calendar of the %3$s and usually celebrated on %4$s, is suppressed by the Sunday or Solemnity %5$s in the year %6$d'),
                            $this->LitGrade->i18n($obj->festivity->grade, false),
                            '<i>' . $obj->festivity->name . '</i>',
                            $this->GeneralIndex->{$this->CalendarParams->DiocesanCalendar}->diocese,
                            '<b>' . $this->dayAndMonth->format($currentFeastDate->format('U')) . '</b>',
                            '<i>' . $this->Cal->solemnityFromDate($currentFeastDate)->name . '</i>',
                            $this->CalendarParams->Year
                        );
                    }
                }
            }
        }
    }

    private function getGithubReleaseInfo(): \stdClass
    {
        $returnObj = new \stdClass();
        $ghReleaseCacheFile = "engineCache/v" . str_replace(".", "_", self::API_VERSION) . "/GHRelease" . $this->CacheDuration . ".json";
        if (file_exists($ghReleaseCacheFile)) {
            $ghReleaseJsonStr = file_get_contents($ghReleaseCacheFile);
            $GitHubReleasesObj = json_decode($ghReleaseJsonStr);
        } else {
            $GithubReleasesAPI = "https://api.github.com/repos/Liturgical-Calendar/LiturgicalCalendarAPI/releases/latest";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $GithubReleasesAPI);
            curl_setopt($ch, CURLOPT_USERAGENT, 'LiturgicalCalendar');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $currentVersionForDownload = curl_exec($ch);

            if (curl_errno($ch)) {
                $returnObj->status = "error";
                $returnObj->message = curl_error($ch);
            }
            curl_close($ch);
            file_put_contents($ghReleaseCacheFile, $currentVersionForDownload);
            $GitHubReleasesObj = json_decode($currentVersionForDownload);
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            $returnObj->status = "error";
            $returnObj->message = json_last_error_msg();
        } else {
            $returnObj->status = "success";
            $returnObj->obj = $GitHubReleasesObj;
        }
        return $returnObj;
    }

    private function produceIcal(\stdClass $SerializeableLitCal, \stdClass $GitHubReleasesObj): string
    {
        $publishDate = $GitHubReleasesObj->published_at;
        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "PRODID:-//John Romano D'Orazio//Liturgical Calendar V1.0//EN\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "METHOD:PUBLISH\r\n";
        $ical .= "X-MS-OLK-FORCEINSPECTOROPEN:FALSE\r\n";
        $ical .= "X-WR-CALNAME:Roman Catholic Universal Liturgical Calendar " . strtoupper(substr($this->CalendarParams->Locale, 0, 2)) . "\r\n";
        $ical .= "X-WR-TIMEZONE:Europe/Vatican\r\n"; //perhaps allow this to be set through a GET or POST?
        $ical .= "X-PUBLISHED-TTL:PT1D\r\n";
        foreach ($SerializeableLitCal->litcal as $FestivityKey => $CalEvent) {
            $displayGrade = "";
            $displayGradeHTML = "";
            if ($FestivityKey === 'AllSouls') {
                $displayGrade = $this->LitGrade->i18n(LitGrade::COMMEMORATION, false);
                $displayGradeHTML = $this->LitGrade->i18n(LitGrade::COMMEMORATION, true);
            } elseif ($FestivityKey === 'DedicationLateran') {
                $displayGrade = $this->LitGrade->i18n(LitGrade::FEAST, false);
                $displayGradeHTML = $this->LitGrade->i18n(LitGrade::FEAST, true);
            } elseif ((int)$CalEvent->date->format('N') !== 7) {
                if (property_exists($CalEvent, 'display_grade') && $CalEvent->display_grade !== "") {
                    $displayGrade = $CalEvent->display_grade;
                    $displayGradeHTML = '<B>' . $CalEvent->display_grade . '</B>';
                } else {
                    $displayGrade = $this->LitGrade->i18n($CalEvent->grade, false);
                    $displayGradeHTML = $this->LitGrade->i18n($CalEvent->grade, true);
                }
            } elseif ((int)$CalEvent->grade > LitGrade::MEMORIAL) {
                if (property_exists($CalEvent, 'display_grade') && $CalEvent->display_grade !== "") {
                    $displayGrade = $CalEvent->display_grade;
                    $displayGradeHTML = '<B>' . $CalEvent->display_grade . '</B>';
                } else {
                    $displayGrade = $this->LitGrade->i18n($CalEvent->grade, false);
                    $displayGradeHTML = $this->LitGrade->i18n($CalEvent->grade, true);
                }
            }

            $description = $this->LitCommon->c($CalEvent->common);
            $description .=  '\n' . $displayGrade;
            $description .= (is_string($CalEvent->color) && $CalEvent->color != "")
                || (is_array($CalEvent->color) && count($CalEvent->color) > 0 )
                ? '\n' . LitMessages::parseColorString($CalEvent->color, $this->CalendarParams->Locale, false)
                : "";
            $description .= property_exists($CalEvent, 'liturgical_year')
                && $CalEvent->liturgical_year !== null
                && $CalEvent->liturgical_year != ""
                ? '\n' . $CalEvent->liturgical_year
                : "";
            $htmlDescription = "<P DIR=LTR>" . $this->LitCommon->c($CalEvent->common);
            $htmlDescription .=  '<BR>' . $displayGradeHTML;
            $htmlDescription .= (is_string($CalEvent->color) && $CalEvent->color != "")
                || (is_array($CalEvent->color) && count($CalEvent->color) > 0 )
                ? "<BR>" . LitMessages::parseColorString($CalEvent->color, $this->CalendarParams->Locale, true)
                : "";
            $htmlDescription .= property_exists($CalEvent, 'liturgical_year')
                && $CalEvent->liturgical_year !== null
                && $CalEvent->liturgical_year != ""
                ? '<BR>' . $CalEvent->liturgical_year . "</P>"
                : "</P>";
            $ical .= "BEGIN:VEVENT\r\n";
            $ical .= "CLASS:PUBLIC\r\n";
            $ical .= "DTSTART;VALUE=DATE:" . $CalEvent->date->format('Ymd') . "\r\n";// . "T" . $CalEvent->date->format( 'His' ) . "Z\r\n";
            //$CalEvent->date->add( new \DateInterval( 'P1D' ) );
            //$ical .= "DTEND:" . $CalEvent->date->format( 'Ymd' ) . "T" . $CalEvent->date->format( 'His' ) . "Z\r\n";
            $ical .= "DTSTAMP:" . date('Ymd') . "T" . date('His') . "Z\r\n";
            /** The event created in the calendar is specific to this year, next year it may be different.
             *  So UID must take into account the year
             *  Next year's event should not cancel this year's event, they are different events
             **/
            $ical .= "UID:" . md5("LITCAL-" . $FestivityKey . '-' . $CalEvent->date->format('Y')) . "\r\n";
            $ical .= "CREATED:" . str_replace(':', '', str_replace('-', '', $publishDate)) . "\r\n";
            $desc = "DESCRIPTION:" . str_replace(',', '\,', $description);
            $ical .= strlen($desc) > 75 ? rtrim(chunk_split($desc, 71, "\r\n\t")) . "\r\n" : "$desc\r\n";
            $ical .= "LAST-MODIFIED:" . str_replace(':', '', str_replace('-', '', $publishDate)) . "\r\n";
            $summaryLang = ";LANGUAGE=" . strtolower(preg_replace('/_/', '-', $this->CalendarParams->Locale));
            $summary = "SUMMARY" . $summaryLang . ":" . str_replace(',', '\,', str_replace("\r\n", " ", $CalEvent->name));
            $ical .= strlen($summary) > 75 ? rtrim(chunk_split($summary, 75, "\r\n\t")) . "\r\n" : $summary . "\r\n";
            $ical .= "TRANSP:TRANSPARENT\r\n";
            $ical .= "X-MICROSOFT-CDO-ALLDAYEVENT:TRUE\r\n";
            $ical .= "X-MICROSOFT-DISALLOW-COUNTER:TRUE\r\n";
            $xAltDesc = 'X-ALT-DESC;FMTTYPE=text/html:<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 3.2//EN">\n<HTML>\n<BODY>\n\n';
            $xAltDesc .= str_replace(',', '\,', $htmlDescription);
            $xAltDesc .= '\n\n</BODY>\n</HTML>';
            $ical .= strlen($xAltDesc) > 75 ? rtrim(chunk_split($xAltDesc, 71, "\r\n\t")) . "\r\n" : "$xAltDesc\r\n";
            $ical .= "END:VEVENT\r\n";
        }
        $ical .= "END:VCALENDAR";
        return $ical;
    }

    private function generateResponse()
    {
        $SerializeableLitCal                                = new \stdClass();
        $SerializeableLitCal->litcal                        = $this->Cal->getFestivities();

        $SerializeableLitCal->settings                      = new \stdClass();
        $SerializeableLitCal->settings->year                = $this->CalendarParams->Year;
        $SerializeableLitCal->settings->epiphany            = $this->CalendarParams->Epiphany;
        $SerializeableLitCal->settings->ascension           = $this->CalendarParams->Ascension;
        $SerializeableLitCal->settings->corpus_christi      = $this->CalendarParams->CorpusChristi;
        $SerializeableLitCal->settings->locale              = $this->CalendarParams->Locale;
        $SerializeableLitCal->settings->return_type         = $this->CalendarParams->ReturnType;
        $SerializeableLitCal->settings->calendar_type       = $this->CalendarParams->CalendarType;
        $SerializeableLitCal->settings->eternal_high_priest = $this->CalendarParams->EternalHighPriest;
        if ($this->CalendarParams->NationalCalendar !== null) {
            $SerializeableLitCal->settings->national_calendar = $this->CalendarParams->NationalCalendar;
        }
        if ($this->CalendarParams->DiocesanCalendar !== null) {
            $SerializeableLitCal->settings->diocesan_calendar = $this->CalendarParams->DiocesanCalendar;
        }

        $SerializeableLitCal->metadata                      = new \stdClass();
        $SerializeableLitCal->metadata->version             = self::API_VERSION;
        $SerializeableLitCal->metadata->timestamp           = time();
        $SerializeableLitCal->metadata->date_time           = date(DATE_ATOM);
        $SerializeableLitCal->metadata->request_headers     = self::$APICore->getRequestHeaders();
        $SerializeableLitCal->metadata->solemnities         = $this->Cal->getSolemnitiesCollection();
        $SerializeableLitCal->metadata->solemnities_keys    = $this->Cal->getSolemnitiesKeys();
        $SerializeableLitCal->metadata->feasts              = $this->Cal->getFeastsCollection();
        $SerializeableLitCal->metadata->feasts_keys         = $this->Cal->getFeastsKeys();
        $SerializeableLitCal->metadata->memorials           = $this->Cal->getMemorialsCollection();
        $SerializeableLitCal->metadata->memorials_keys      = $this->Cal->getMemorialsKeys();
        $SerializeableLitCal->messages                      = $this->Messages;

        //make sure we have an engineCache folder for the current Version
        if (realpath("engineCache/v" . str_replace(".", "_", self::API_VERSION)) === false) {
            mkdir("engineCache/v" . str_replace(".", "_", self::API_VERSION), 0755, true);
        }

        switch ($this->CalendarParams->ReturnType) {
            case ReturnType::JSON:
                $response = json_encode($SerializeableLitCal);
                break;
            case ReturnType::XML:
                // first convert the Object to an Array
                $jsonStr = json_encode($SerializeableLitCal);
                $jsonObj = json_decode($jsonStr, true);

                // then create an XML representation from the Array
                $ns = "http://www.bibleget.io/catholicliturgy";
                $xml = new \SimpleXMLElement(
                    "<?xml version=\"1.0\" encoding=\"UTF-8\"?" . "><LiturgicalCalendar xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\""
                    . " xsi:schemaLocation=\"$ns https://litcal.johnromanodorazio.com/api/dev/schemas/LiturgicalCalendar.xsd\""
                    . " xmlns=\"$ns\"/>"
                );
                LitFunc::convertArray2XML($jsonObj, $xml);
                $rawXML = $xml->asXML(); //this gives us non pretty XML, basically a single long string

                // finally let's pretty print the XML to make the cached file more readable
                $dom = new \DOMDocument();
                $dom->preserveWhiteSpace = false;
                $dom->formatOutput = true;
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
                if ($infoObj->status === "success") {
                    $response = $this->produceIcal($SerializeableLitCal, $infoObj->obj);
                } else {
                    $message = sprintf(
                        _('Error receiving or parsing info from github about latest release: %s.'),
                        $infoObj->message
                    );
                    self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $message);
                }
                break;
            default:
                $response = json_encode($SerializeableLitCal);
                break;
        }
        file_put_contents($this->CACHEFILE, $response);
        $responseHash = md5($response);

        $this->endTime = hrtime(true);
        $executionTime = $this->endTime - $this->startTime;
        header('X-LitCal-Starttime: ' . $this->startTime);
        header('X-LitCal-Endtime: ' . $this->endTime);
        header('X-LitCal-Executiontime: ' . $executionTime);

        header("Etag: \"{$responseHash}\"");
        if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $responseHash) {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 304 Not Modified");
            header('Content-Length: 0');
            header('X-LitCal-Generated: ClientCache');
        } else {
            header('X-LitCal-Generated: Calculation');
            echo $response;
        }
        die();
    }

    private function prepareL10N(): string|false
    {
        $baseLocale = $this->CalendarParams->Locale !== LitLocale::LATIN
            ? strtolower(\Locale::getPrimaryLanguage($this->CalendarParams->Locale))
            : strtolower(LitLocale::LATIN);
        LitLocale::$PRIMARY_LANGUAGE = $baseLocale;
        $localeArray = [
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
        $systemLocale = setlocale(LC_ALL, $localeArray);
        $this->createFormatters();
        bindtextdomain("litcal", "i18n");
        textdomain("litcal");
        $this->Cal          = new FestivityCollection($this->CalendarParams);
        $this->LitCommon    = new LitCommon($this->CalendarParams->Locale, $systemLocale);
        $this->LitGrade     = new LitGrade($this->CalendarParams->Locale);
        return $systemLocale;
    }

    public function setCacheDuration(string $duration): void
    {
        switch ($duration) {
            case CacheDuration::DAY:
                $this->CacheDuration = "_" . $duration . date("z"); //The day of the year ( starting from 0 through 365 )
                break;
            case CacheDuration::WEEK:
                $this->CacheDuration = "_" . $duration . date("W"); //ISO-8601 week number of year, weeks starting on Monday
                break;
            case CacheDuration::MONTH:
                $this->CacheDuration = "_" . $duration . date("m"); //Numeric representation of a month, with leading zeros
                break;
            case CacheDuration::YEAR:
                $this->CacheDuration = "_" . $duration . date("Y"); //A full numeric representation of a year, 4 digits
                break;
        }
    }

    public function setAllowedReturnTypes(array $returnTypes): void
    {
        $this->AllowedReturnTypes = array_values(array_intersect(ReturnType::$values, $returnTypes));
    }

    /**
     * The LitCalEngine will only work once you call the public Init() method
     * Do not change the order of the methods that follow,
     * each one can depend on the one before it in order to function correctly!
     */
    public function init(array $requestPathParts = [])
    {
        self::$APICore->init();
        $this->initParameterData($requestPathParts);
        $this->loadDiocesanCalendarData();
        $this->loadNationalCalendarData();
        $this->updateSettingsBasedOnNationalCalendar();
        $this->updateSettingsBasedOnDiocesanCalendar();
        self::$APICore->setResponseContentTypeHeader();

        if ($this->cacheFileIsAvailable()) {
            //If we already have done the calculation
            //and stored the results in a cache file
            //then we're done, just output this and die
            //or better, make the client use it's own cache copy!
            $response       = file_get_contents($this->CACHEFILE);
            $responseHash   = md5($response);

            $this->endTime  = hrtime(true);
            $executionTime  = $this->endTime - $this->startTime;
            header('X-LitCal-Starttime: ' . $this->startTime);
            header('X-LitCal-Endtime: ' . $this->endTime);
            header('X-LitCal-Executiontime: ' . $executionTime);

            header("Etag: \"{$responseHash}\"");
            if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $responseHash) {
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 304 Not Modified");
                header('Content-Length: 0');
                header('X-LitCal-Generated: ClientCache');
            } else {
                header('X-LitCal-Generated: ServerCache');
                echo $response;
            }
            die();
        } else {
            $this->dieIfBeforeMinYear();
            $systemLocale = $this->prepareL10N();
            Festivity::setLocale($this->CalendarParams->Locale, $systemLocale);
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

            if ($this->CalendarParams->CalendarType === CalendarType::LITURGICAL) {
                // Save the state of the current Calendar calculation
                $this->Cal->sortFestivities();
                $CalBackup = clone( $this->Cal );
                $Messages  = $this->Messages;
                $this->Messages = [];

                // let's calculate the calendar for the previous year
                $this->CalendarParams->Year--;
                $this->Cal           = new FestivityCollection($this->CalendarParams);

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
                $this->Cal->sortFestivities();

                $this->Cal->purgeDataBeforeAdvent();
                $CalBackup->purgeDataAdventChristmas();

                // Now we have to combine the two
                // the backup (which represents the main portion) should be appended to the calendar that was just generated
                $this->Cal->mergeFestivityCollection($CalBackup);

                // let's reset the year back to the original request before outputting results
                $this->CalendarParams->Year++;
                // and append the backed up messages
                array_push($this->Messages, ...$Messages);
                $this->generateResponse();
            } else {
                $this->Cal->sortFestivities();
                $this->generateResponse();
            }
        }
    }
}
