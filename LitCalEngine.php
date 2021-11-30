<?php

/**
 * Liturgical Calendar PHP engine script
 * Author: John Romano D'Orazio
 * Email: priest@johnromanodorazio.com
 * Licensed under the Apache 2.0 License
 * Version 3.0
 * Date Created: 27 December 2017
 * Note: it is necessary to set up the MySQL liturgy tables prior to using this script
 */


/**********************************************************************************
 *                          ABBREVIATIONS                                         *
 * CB     Cerimonial of Bishops                                                   *
 * CCL    Code of Canon Law                                                       *
 * IM     General Instruction of the Roman Missal                                 *
 * IH     General Instruction of the Liturgy of the Hours                         *
 * LH     Liturgy of the Hours                                                    *
 * LY     Universal Norms for the Liturgical Year and the Calendar (Roman Missal) *
 * OM     Order of Matrimony                                                      *
 * PC     Instruction regarding Proper Calendars                                  *
 * RM     Roman Missal                                                            *
 * SC     Sacrosanctum Concilium, Conciliar Constitution on the Sacred Liturgy    *
 *                                                                                *
 *********************************************************************************/


/**********************************************************************************
 *         EDITIONS OF THE ROMAN MISSAL AND OF THE GENERAL ROMAN CALENDAR         *
 *                                                                                *
 * Editio typica, 1970                                                            *
 * Reimpressio emendata, 1971                                                     *
 * Editio typica secunda, 1975                                                    *
 * Editio typica tertia, 2002                                                     *
 * Editio typica tertia emendata, 2008                                            *
 * -----------------------------------                                            *
 * Roman Missal [USA], 2011                                                       *
 * -----------------------------------                                            *
 * Messale Romano [ITALIA], 1983                                                  *
 * Messale Romano [ITALIA], 2020                                                  *
 *                                                                                *
 *********************************************************************************/

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('date.timezone', 'Europe/Vatican');

include_once( 'includes/enums/AcceptHeader.php' );
include_once( 'includes/enums/CacheDuration.php' );
include_once( 'includes/enums/RequestMethod.php' );
include_once( 'includes/enums/RequestContentType.php' );
include_once( 'includes/enums/ReturnType.php' );
include_once( 'includes/enums/RomanMissalEdition.php' );

include_once( "includes/Festivity.php" );
include_once( "includes/LitSettings.php" );
include_once( "includes/LitCalFunctions.php" );
include_once( "includes/LitCalMessages.php" );


$LitCalEngine = new LitCalEngine();
$LitCalEngine->setCacheDuration( CACHEDURATION::MONTH );
$LitCalEngine->setAllowedOrigins( [
    "https://johnromanodorazio.com",
    "https://www.johnromanodorazio.com"
] );
$LitCalEngine->setAllowedAcceptHeaders([ ACCEPT_HEADER::JSON, ACCEPT_HEADER::XML, ACCEPT_HEADER::ICS ]);
$LitCalEngine->setAllowedParameterReturnTypes([ RETURN_TYPE::JSON, RETURN_TYPE::XML, RETURN_TYPE::ICS ]);
$LitCalEngine->setAllowedRequestMethods([ REQUEST_METHOD::GET, REQUEST_METHOD::POST ]);
$LitCalEngine->setAllowedRequestContentTypes([ REQUEST_CONTENT_TYPE::JSON, REQUEST_CONTENT_TYPE::FORMDATA ]);
$LitCalEngine->Init();

class LitCalEngine {

    const API_VERSION                               = '3.0';


    private string $CACHEDURATION                   = "";
    private string $CACHEFILE                       = "";
    private array $ALLOWED_ORIGINS;
    private array $ALLOWED_ACCEPT_HEADERS;
    private array $ALLOWED_RETURN_TYPES;
    private array $ALLOWED_REQUEST_METHODS;
    private array $ALLOWED_REQUEST_CONTENT_TYPES;
    private array $REQUEST_HEADERS;
    private LITSETTINGS $LITSETTINGS;
    private mysqli $mysqli;

    private string $jsonEncodedRequestHeaders       = "";
    private string $responseContentType             = RETURN_TYPE::JSON;
    //private bool $isAjax                          = false;

    private ?object $DiocesanData                   = null;
    private ?object $GeneralIndex                   = null;
    private NumberFormatter $formatter;
    private NumberFormatter $formatterFem;


    private array $PROPRIUM_DE_TEMPORE              = [];
    private array $SOLEMNITIES                      = [];
    private array $FEASTS_MEMORIALS                 = [];
    private array $WEEKDAYS_ADVENT_CHRISTMAS_LENT   = [];
    private array $WEEKDAYS_EPIPHANY                = [];
    private array $SUNDAYS_ADVENT_LENT_EASTER       = [];
    private array $SOLEMNITIES_LORD_BVM             = [];
    private array $LitCal                           = [];
    private array $Messages                         = [];
    private string $BaptismLordFmt;
    private string $BaptismLordMod;

    public function __construct(){
        $this->CACHEDURATION                        = "_" . CACHEDURATION::MONTH . date("m");
        $this->ALLOWED_ORIGINS                      = [ "*" ];
        $this->ALLOWED_ACCEPT_HEADERS               = ACCEPT_HEADER::$values;
        $this->ALLOWED_RETURN_TYPES                 = RETURN_TYPE::$values;
        $this->ALLOWED_REQUEST_METHODS              = REQUEST_METHOD::$values;
        $this->ALLOWED_REQUEST_CONTENT_TYPES        = REQUEST_CONTENT_TYPE::$values;
        $this->REQUEST_HEADERS                      = getallheaders();
        $this->jsonEncodedRequestHeaders            = json_encode( $this->REQUEST_HEADERS );
    }


    private function setAllowedOriginHeader() {
        if( count($this->ALLOWED_ORIGINS) === 1 && $this->ALLOWED_ORIGINS[0] === "*" ) {
            header('Access-Control-Allow-Origin: *');
        }
        elseif( isset( $this->REQUEST_HEADERS["Origin"] ) && in_array( $this->REQUEST_HEADERS["Origin"], $this->ALLOWED_ORIGINS ) ) {
            header('Access-Control-Allow-Origin: ' . $this->REQUEST_HEADERS["Origin"]);
        }
        else {
            header('Access-Control-Allow-Origin: https://www.vatican.va');
        }
        header( 'Access-Control-Allow-Credentials: true' );
        header( 'Access-Control-Max-Age: 86400' );    // cache for 1 day
    }

    private static function setAccessControlAllowMethods() {
        if ( isset( $_SERVER['REQUEST_METHOD'] ) ) {
            if ( isset( $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ) )
                header( "Access-Control-Allow-Methods: GET, POST" );
            if ( isset( $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ) )
                header( "Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}" );
        }
    }

    private function validateRequestContentType() {
        if( isset( $_SERVER['CONTENT_TYPE'] ) && $_SERVER['CONTENT_TYPE'] !== '' && !in_array( explode( ';', $_SERVER['CONTENT_TYPE'] )[0], $this->ALLOWED_REQUEST_CONTENT_TYPES ) ){
            header( $_SERVER["SERVER_PROTOCOL"]." 415 Unsupported Media Type", true, 415 );
            die( '{"error":"You seem to be forming a strange kind of request? Allowed Content Types are '.implode( ' and ', $this->ALLOWED_REQUEST_CONTENT_TYPES ).', but your Content Type was '.$_SERVER['CONTENT_TYPE'].'"}' );
        }
    }

    private function initParameterData() {
        if ( isset( $_SERVER['CONTENT_TYPE'] ) && $_SERVER['CONTENT_TYPE'] === 'application/json' ) {
            $json = file_get_contents( 'php://input' );
            $data = json_decode( $json, true );
            if( NULL === $json || "" === $json ){
                header( $_SERVER["SERVER_PROTOCOL"]." 400 Bad Request", true, 400 );
                die( '{"error":"No JSON data received in the request: <' . $json . '>"' );
            } else if ( json_last_error() !== JSON_ERROR_NONE ) {
                header( $_SERVER["SERVER_PROTOCOL"]." 400 Bad Request", true, 400 );
                die( '{"error":"Malformed JSON data received in the request: <' . $json . '>, ' . json_last_error_msg() . '"}' );
            } else {
                $this->LITSETTINGS = new LITSETTINGS( $data );
            }
        } else {
            switch( strtoupper( $_SERVER["REQUEST_METHOD"] ) ) {
                case 'POST':
                    $this->LITSETTINGS = new LITSETTINGS( $_POST );
                    break;
                case 'GET':
                    $this->LITSETTINGS = new LITSETTINGS( $_GET );
                    break;
                default:
                    header( $_SERVER["SERVER_PROTOCOL"]." 405 Method Not Allowed", true, 405 );
                    $errorMessage = '{"error":"You seem to be forming a strange kind of request? Allowed Request Methods are ';
                    $errorMessage .= implode( ' and ', $this->ALLOWED_REQUEST_METHODS );
                    $errorMessage .= ', but your Request Method was ' . strtoupper( $_SERVER['REQUEST_METHOD'] ) . '"}';
                    die( $errorMessage );
            }
        }
        if( $this->LITSETTINGS->RETURNTYPE !== null ) {
            if( in_array( $this->LITSETTINGS->RETURNTYPE, $this->ALLOWED_RETURN_TYPES ) ) {
                $this->responseContentType = $this->ALLOWED_ACCEPT_HEADERS[array_search( $this->LITSETTINGS->RETURNTYPE, $this->ALLOWED_RETURN_TYPES )];
            } else {
                header( $_SERVER["SERVER_PROTOCOL"]." 406 Not Acceptable", true, 406 );
                $errorMessage = '{"error":"You are requesting a content type which this API cannot produce. Allowed content types are ';
                $errorMessage .= implode( ' and ', $this->ALLOWED_RETURN_TYPES );
                $errorMessage .= ', but you have issued a parameter requesting a Content Type of ' . strtoupper( $this->LITSETTINGS->RETURNTYPE ) . '"}';
                die( $errorMessage );
            }
        } else {
            if( isset( $this->REQUEST_HEADERS["Accept"] ) ) {
                if( in_array( $this->REQUEST_HEADERS["Accept"], $this->ALLOWED_ACCEPT_HEADERS ) ) {
                    $this->LITSETTINGS->RETURNTYPE = $this->ALLOWED_RETURN_TYPES[array_search( $this->REQUEST_HEADERS["Accept"], $this->ALLOWED_ACCEPT_HEADERS )];
                    $this->responseContentType = $this->REQUEST_HEADERS["Accept"];
                } else {
                    //Requests from browser windows using the address bar will probably have an Accept header of text/html
                    //In order to not be too drastic, let's treat text/html as though it were application/json
                    $acceptHeaders = explode( ",", $this->REQUEST_HEADERS["Accept"] );
                    if( in_array( 'text/html', $acceptHeaders ) ) {
                        $this->LITSETTINGS->RETURNTYPE = RETURN_TYPE::JSON;
                        $this->responseContentType = ACCEPT_HEADER::JSON;
                    } else {
                        header( $_SERVER["SERVER_PROTOCOL"]." 406 Not Acceptable", true, 406 );
                        $errorMessage = '{"error":"You are requesting a content type which this API cannot produce. Allowed Accept headers are ';
                        $errorMessage .= implode( ' and ', $this->ALLOWED_ACCEPT_HEADERS );
                        $errorMessage .= ', but you have issued an request with an Accept header of ' . $this->REQUEST_HEADERS["Accept"] . '"}';
                        die( $errorMessage );
                    }

                }
            } else {
                $this->LITSETTINGS->RETURNTYPE = $this->ALLOWED_RETURN_TYPES[0];
                $this->responseContentType = $this->ALLOWED_ACCEPT_HEADERS[0];
            }
        }
    }

    private function setReponseContentTypeHeader() {
        header( "Content-Type: {$this->responseContentType}; charset=utf-8" );
        if( $this->responseContentType === ACCEPT_HEADER::ICS ){
            header('Content-Disposition: attachment; filename="LiturgicalCalendar.ics"');
        }
    }


    private function loadLocalCalendarData() : void {
        if($this->LITSETTINGS->DIOCESAN !== null){
            //since a Diocesan calendar is being requested, we need to retrieve the JSON data
            //first we need to discover the path, so let's retrieve our index file
            if(file_exists("nations/index.json")){
                $this->GeneralIndex = json_decode(file_get_contents("nations/index.json"));
                if(property_exists($this->GeneralIndex,$this->LITSETTINGS->DIOCESAN)){
                    $diocesanDataFile = $this->GeneralIndex->{$this->LITSETTINGS->DIOCESAN}->path;
                    $this->LITSETTINGS->NATIONAL = $this->GeneralIndex->{$this->LITSETTINGS->DIOCESAN}->nation;
                    if( file_exists($diocesanDataFile) ){
                        $this->DiocesanData = json_decode( file_get_contents($diocesanDataFile) );
                    }
                }
            }
        }

        if($this->LITSETTINGS->NATIONAL !== null){
            switch($this->LITSETTINGS->NATIONAL){
                case 'VATICAN':
                    $this->LITSETTINGS->EPIPHANY        = EPIPHANY::JAN6;
                    $this->LITSETTINGS->ASCENSION       = ASCENSION::THURSDAY;
                    $this->LITSETTINGS->CORPUSCHRISTI   = CORPUSCHRISTI::THURSDAY;
                    $this->LITSETTINGS->LOCALE          = LIT_LOCALE::LA;
                break;
                case "ITALY":
                    $this->LITSETTINGS->EPIPHANY        = EPIPHANY::JAN6;
                    $this->LITSETTINGS->ASCENSION       = ASCENSION::SUNDAY;
                    $this->LITSETTINGS->CORPUSCHRISTI   = CORPUSCHRISTI::SUNDAY;
                    $this->LITSETTINGS->LOCALE          = LIT_LOCALE::IT;
                break;
                case "USA":
                    $this->LITSETTINGS->EPIPHANY        = EPIPHANY::SUNDAY_JAN2_JAN8;
                    $this->LITSETTINGS->ASCENSION       = ASCENSION::SUNDAY;
                    $this->LITSETTINGS->CORPUSCHRISTI   = CORPUSCHRISTI::SUNDAY;
                    $this->LITSETTINGS->LOCALE          = LIT_LOCALE::EN;
                break;
            }
        }
    }

    private function cacheFileIsAvailable() {
        $cacheFilePath = "engineCache/v" . str_replace( ".", "_", self::API_VERSION ) . "/";
        $cacheFileName = md5( serialize($this->LITSETTINGS) ) . $this->CACHEDURATION . "." . strtolower( $this->LITSETTINGS->RETURNTYPE );
        $this->CACHEFILE = $cacheFilePath . $cacheFileName;
        return file_exists( $this->CACHEFILE );
    }

    /**
     * INITIATE CONNECTION TO THE DATABASE
     * AND CHECK FOR CONNECTION ERRORS
     * THE DATABASECONNECT() FUNCTION IS DEFINED IN LITCALFUNCTIONS.PHP
     * WHICH IN TURN LOADS DATABASE CONNECTION INFORMATION FROM LITCALCONFIG.PHP
     * IF THE CONNECTION SUCCEEDS, THE FUNCTION WILL RETURN THE MYSQLI CONNECTION RESOURCE
     * IN THE MYSQLI PROPERTY OF THE RETURNED OBJECT
     */
    private function initiateDbConnection() : bool {
        $dbConnect = LitCalFf::databaseConnect();
        if ($dbConnect->retString != "" && preg_match("/^Connected to MySQL Database:/", $dbConnect->retString) == 0) {
            die('{"error": "There was an error while connecting to the database: ' . $dbConnect->retString . '"}');
        } else {
            $this->mysqli = $dbConnect->mysqli;
            return $this->mysqli !== null;
        }
        return false;
    }

    private function createNumberFormatters() : void {
        //ini_set('intl.default_locale', strtolower( $this->LITSETTINGS->LOCALE) . '_' . $this->LITSETTINGS->LOCALE);
        setlocale(LC_TIME, strtolower( $this->LITSETTINGS->LOCALE) . '_' . $this->LITSETTINGS->LOCALE);
        $this->formatter = new NumberFormatter(strtolower( $this->LITSETTINGS->LOCALE), NumberFormatter::SPELLOUT);
        switch( $this->LITSETTINGS->LOCALE){
            case 'EN':
                $this->formatter->setTextAttribute(NumberFormatter::DEFAULT_RULESET, "%spellout-ordinal");
                $this->formatterFem = $this->formatter;
            break;
            default:
                $this->formatter->setTextAttribute(NumberFormatter::DEFAULT_RULESET, "%spellout-ordinal-masculine");
                $this->formatterFem = new NumberFormatter(strtolower( $this->LITSETTINGS->LOCALE), NumberFormatter::SPELLOUT);
                $this->formatterFem->setTextAttribute(NumberFormatter::DEFAULT_RULESET, "%spellout-ordinal-feminine");
        }
    }

    private function dieIfBeforeMinYear() : void {
        //for the time being, we cannot accept a year any earlier than 1970, since this engine is based on the liturgical reform from Vatican II
        //with the Prima Editio Typica of the Roman Missal and the General Norms promulgated with the Motu Proprio "Mysterii Paschali" in 1969
        if ($this->LITSETTINGS->YEAR < 1970) {
            $this->Messages[] = sprintf(LITCAL_MESSAGES::__( "Only years from 1970 and after are supported. You tried requesting the year %d.",$this->LITSETTINGS->LOCALE),$this->LITSETTINGS->YEAR);
            $this->GenerateResponseToRequest();
        }
    }

    private function retrieveHigherSolemnityTranslations() : void {
        /**
         * Retrieve Higher Ranking Solemnities from Proprium de Tempore
         */
        $result = $this->mysqli->query("SELECT * FROM LITURGY__calendar_propriumdetempore");
        if ( $result ) {
            while ($row = mysqli_fetch_assoc($result)) {
                $this->PROPRIUM_DE_TEMPORE[$row["TAG"]] = array( "NAME_" . $this->LITSETTINGS->LOCALE => $row["NAME_" . $this->LITSETTINGS->LOCALE] );
            }
        }
    }

    private function calculateEasterTriduum() : void {
        $this->LitCal["HolyThurs"]        = new Festivity($this->PROPRIUM_DE_TEMPORE["HolyThurs"]["NAME_" . $this->LITSETTINGS->LOCALE],    LitCalFf::calcGregEaster($this->LITSETTINGS->YEAR)->sub(new DateInterval('P3D')), "white", "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["GoodFri"]          = new Festivity($this->PROPRIUM_DE_TEMPORE["GoodFri"]["NAME_" . $this->LITSETTINGS->LOCALE],      LitCalFf::calcGregEaster($this->LITSETTINGS->YEAR)->sub(new DateInterval('P2D')), "red",   "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["EasterVigil"]      = new Festivity($this->PROPRIUM_DE_TEMPORE["EasterVigil"]["NAME_" . $this->LITSETTINGS->LOCALE],  LitCalFf::calcGregEaster($this->LITSETTINGS->YEAR)->sub(new DateInterval('P1D')), "white", "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["Easter"]           = new Festivity($this->PROPRIUM_DE_TEMPORE["Easter"]["NAME_" . $this->LITSETTINGS->LOCALE],       LitCalFf::calcGregEaster($this->LITSETTINGS->YEAR),                               "white", "mobile", LitGrade::HIGHER_SOLEMNITY);

        $this->SOLEMNITIES["HolyThurs"]   = $this->LitCal["HolyThurs"]->date;
        $this->SOLEMNITIES["GoodFri"]     = $this->LitCal["GoodFri"]->date;
        $this->SOLEMNITIES["EasterVigil"] = $this->LitCal["EasterVigil"]->date;
        $this->SOLEMNITIES["Easter"]      = $this->LitCal["Easter"]->date;
    }

    private function calculateChristmasEpiphany() : void {
        $this->LitCal["Christmas"]        = new Festivity($this->PROPRIUM_DE_TEMPORE["Christmas"]["NAME_" . $this->LITSETTINGS->LOCALE],    DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC')), "white", "fixed",  LitGrade::HIGHER_SOLEMNITY);
        $this->SOLEMNITIES["Christmas"]   = $this->LitCal["Christmas"]->date;

        if ( $this->LITSETTINGS->EPIPHANY === EPIPHANY::JAN6 ) {

            $this->LitCal["Epiphany"]     = new Festivity($this->PROPRIUM_DE_TEMPORE["Epiphany"]["NAME_" . $this->LITSETTINGS->LOCALE],     DateTime::createFromFormat('!j-n-Y', '6-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC')),  "white", "fixed",  LitGrade::HIGHER_SOLEMNITY);

            //If a Sunday occurs on a day from Jan. 2 through Jan. 5, it is called the "Second Sunday of Christmas"
            //Weekdays from Jan. 2 through Jan. 5 are called "*day before Epiphany"
            $nth = 0;
            for ($i = 2; $i <= 5; $i++) {
                if ((int)DateTime::createFromFormat('!j-n-Y', $i . '-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->format('N') === 7) {
                    $this->LitCal["Christmas2"]       = new Festivity($this->PROPRIUM_DE_TEMPORE["Christmas2"]["NAME_" . $this->LITSETTINGS->LOCALE], DateTime::createFromFormat('!j-n-Y', $i . '-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC')), "white",     "mobile", LitGrade::FEAST_LORD);
                    $this->SOLEMNITIES["Christmas2"]  = $this->LitCal["Christmas2"]->date;
                } else {
                    $nth++;
                    $this->LitCal["DayBeforeEpiphany" . $nth] = new Festivity(sprintf(LITCAL_MESSAGES::__( "%s day before Epiphany", $this->LITSETTINGS->LOCALE), ( $this->LITSETTINGS->LOCALE == 'LA' ? LITCAL_MESSAGES::LATIN_ORDINAL[$nth] : ucfirst($this->formatter->format($nth)) ) ), DateTime::createFromFormat('!j-n-Y', $i . '-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC')), "white",     "mobile");
                    $this->WEEKDAYS_EPIPHANY["DayBeforeEpiphany" . $nth] = $this->LitCal["DayBeforeEpiphany" . $nth]->date;
                }
            }

            //Weekdays from Jan. 7 until the following Sunday are called "*day after Epiphany"
            $SundayAfterEpiphany = (int) DateTime::createFromFormat('!j-n-Y', '6-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('next Sunday')->format('j');
            if ($SundayAfterEpiphany !== 7) {
                $nth = 0;
                for ($i = 7; $i < $SundayAfterEpiphany; $i++) {
                    $nth++;
                    $this->LitCal["DayAfterEpiphany" . $nth] = new Festivity(sprintf(LITCAL_MESSAGES::__( "%s day after Epiphany", $this->LITSETTINGS->LOCALE), ( $this->LITSETTINGS->LOCALE == 'LA' ? LITCAL_MESSAGES::LATIN_ORDINAL[$nth] : ucfirst($this->formatter->format($nth)) ) ), DateTime::createFromFormat('!j-n-Y', $i . '-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC')), "white",     "mobile");
                    $this->WEEKDAYS_EPIPHANY["DayAfterEpiphany" . $nth] = $this->LitCal["DayAfterEpiphany" . $nth]->date;
                }
            }
        } else if ( $this->LITSETTINGS->EPIPHANY === EPIPHANY::SUNDAY_JAN2_JAN8 ) {
            //If January 2nd is a Sunday, then go with Jan 2nd
            if ((int)DateTime::createFromFormat('!j-n-Y', '2-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->format('N') === 7) {
                $this->LitCal["Epiphany"] = new Festivity($this->PROPRIUM_DE_TEMPORE["Epiphany"]["NAME_" . $this->LITSETTINGS->LOCALE],      DateTime::createFromFormat('!j-n-Y', '2-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC')), "white",    "mobile",    LitGrade::HIGHER_SOLEMNITY);
            }
            //otherwise find the Sunday following Jan 2nd
            else {
                $SundayOfEpiphany = DateTime::createFromFormat('!j-n-Y', '2-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('next Sunday');
                $this->LitCal["Epiphany"] = new Festivity($this->PROPRIUM_DE_TEMPORE["Epiphany"]["NAME_" . $this->LITSETTINGS->LOCALE],      $SundayOfEpiphany,                                    "white",    "mobile",    LitGrade::HIGHER_SOLEMNITY);

                //Weekdays from Jan. 2 until the following Sunday are called "*day before Epiphany"
                //echo $SundayOfEpiphany->format('j');
                $DayOfEpiphany = (int) $SundayOfEpiphany->format('j');

                $nth = 0;

                for ($i = 2; $i < $DayOfEpiphany; $i++) {
                    $nth++;
                    $this->LitCal["DayBeforeEpiphany" . $nth] = new Festivity(sprintf(LITCAL_MESSAGES::__( "%s day before Epiphany", $this->LITSETTINGS->LOCALE), ( $this->LITSETTINGS->LOCALE == 'LA' ? LITCAL_MESSAGES::LATIN_ORDINAL[$nth] : ucfirst($this->formatter->format($nth)) ) ), DateTime::createFromFormat('!j-n-Y', $i . '-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC')), "white",     "mobile");
                    $this->WEEKDAYS_EPIPHANY["DayBeforeEpiphany" . $nth] = $this->LitCal["DayBeforeEpiphany" . $nth]->date;
                }

                //If Epiphany occurs on or before Jan. 6, then the days of the week following Epiphany are called "*day after Epiphany" and the Sunday following Epiphany is the Baptism of the Lord.
                if ($DayOfEpiphany < 7) {
                    $SundayAfterEpiphany = (int)DateTime::createFromFormat('!j-n-Y', '2-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('next Sunday')->modify('next Sunday')->format('j');
                    $nth = 0;
                    for ($i = $DayOfEpiphany + 1; $i < $SundayAfterEpiphany; $i++) {
                        $nth++;
                        $this->LitCal["DayAfterEpiphany" . $nth] = new Festivity(sprintf(LITCAL_MESSAGES::__( "%s day after Epiphany", $this->LITSETTINGS->LOCALE), ( $this->LITSETTINGS->LOCALE == 'LA' ? LITCAL_MESSAGES::LATIN_ORDINAL[$nth] : ucfirst($this->formatter->format($nth)) ) ), DateTime::createFromFormat('!j-n-Y', $i . '-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC')), "white",     "mobile");
                        $this->WEEKDAYS_EPIPHANY["DayAfterEpiphany" . $nth] = $this->LitCal["DayAfterEpiphany" . $nth]->date;
                    }
                }
            }
        }

        $this->SOLEMNITIES["Epiphany"]  = $this->LitCal["Epiphany"]->date;

    }

    private function calculateAscensionPentecost() : void {

        if ( $this->LITSETTINGS->ASCENSION === ASCENSION::THURSDAY ) {
            $this->LitCal["Ascension"]  = new Festivity($this->PROPRIUM_DE_TEMPORE["Ascension"]["NAME_" . $this->LITSETTINGS->LOCALE],    LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P39D')),           "white",    "mobile", LitGrade::HIGHER_SOLEMNITY);
            $this->LitCal["Easter7"]    = new Festivity($this->PROPRIUM_DE_TEMPORE["Easter7"]["NAME_" . $this->LITSETTINGS->LOCALE],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 6) . 'D')),    "white",    "mobile", LitGrade::HIGHER_SOLEMNITY);
        } else if ($this->LITSETTINGS->ASCENSION === "SUNDAY") {
            $this->LitCal["Ascension"]  = new Festivity($this->PROPRIUM_DE_TEMPORE["Ascension"]["NAME_" . $this->LITSETTINGS->LOCALE],    LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 6) . 'D')),    "white",    "mobile", LitGrade::HIGHER_SOLEMNITY);
        }
        $this->SOLEMNITIES["Ascension"] = $this->LitCal["Ascension"]->date;

        $this->LitCal["Pentecost"]      = new Festivity($this->PROPRIUM_DE_TEMPORE["Pentecost"]["NAME_" . $this->LITSETTINGS->LOCALE],    LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 7) . 'D')),    "red",      "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->SOLEMNITIES["Pentecost"] = $this->LitCal["Pentecost"]->date;

    }

    private function calculateSundaysMajorSeasons() : void {
        $this->LitCal["Advent1"]        = new Festivity($this->PROPRIUM_DE_TEMPORE["Advent1"]["NAME_" . $this->LITSETTINGS->LOCALE],      DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday')->sub(new DateInterval('P' . (3 * 7) . 'D')),    "purple",   "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["Advent2"]        = new Festivity($this->PROPRIUM_DE_TEMPORE["Advent2"]["NAME_" . $this->LITSETTINGS->LOCALE],      DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday')->sub(new DateInterval('P' . (2 * 7) . 'D')),    "purple",   "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["Advent3"]        = new Festivity($this->PROPRIUM_DE_TEMPORE["Advent3"]["NAME_" . $this->LITSETTINGS->LOCALE],      DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday')->sub(new DateInterval('P7D')),            "pink",     "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["Advent4"]        = new Festivity($this->PROPRIUM_DE_TEMPORE["Advent4"]["NAME_" . $this->LITSETTINGS->LOCALE],      DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday'),                                          "purple",   "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["Lent1"]          = new Festivity($this->PROPRIUM_DE_TEMPORE["Lent1"]["NAME_" . $this->LITSETTINGS->LOCALE],        LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->sub(new DateInterval('P' . (6 * 7) . 'D')),    "purple",   "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["Lent2"]          = new Festivity($this->PROPRIUM_DE_TEMPORE["Lent2"]["NAME_" . $this->LITSETTINGS->LOCALE],        LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->sub(new DateInterval('P' . (5 * 7) . 'D')),    "purple",   "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["Lent3"]          = new Festivity($this->PROPRIUM_DE_TEMPORE["Lent3"]["NAME_" . $this->LITSETTINGS->LOCALE],        LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->sub(new DateInterval('P' . (4 * 7) . 'D')),    "purple",   "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["Lent4"]          = new Festivity($this->PROPRIUM_DE_TEMPORE["Lent4"]["NAME_" . $this->LITSETTINGS->LOCALE],        LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->sub(new DateInterval('P' . (3 * 7) . 'D')),    "pink",     "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["Lent5"]          = new Festivity($this->PROPRIUM_DE_TEMPORE["Lent5"]["NAME_" . $this->LITSETTINGS->LOCALE],        LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->sub(new DateInterval('P' . (2 * 7) . 'D')),    "purple",   "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["PalmSun"]        = new Festivity($this->PROPRIUM_DE_TEMPORE["PalmSun"]["NAME_" . $this->LITSETTINGS->LOCALE],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->sub(new DateInterval('P7D')),            "red",      "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["Easter2"]        = new Festivity($this->PROPRIUM_DE_TEMPORE["Easter2"]["NAME_" . $this->LITSETTINGS->LOCALE],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P7D')),            "white",    "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["Easter3"]        = new Festivity($this->PROPRIUM_DE_TEMPORE["Easter3"]["NAME_" . $this->LITSETTINGS->LOCALE],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 2) . 'D')),    "white",    "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["Easter4"]        = new Festivity($this->PROPRIUM_DE_TEMPORE["Easter4"]["NAME_" . $this->LITSETTINGS->LOCALE],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 3) . 'D')),    "white",    "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["Easter5"]        = new Festivity($this->PROPRIUM_DE_TEMPORE["Easter5"]["NAME_" . $this->LITSETTINGS->LOCALE],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 4) . 'D')),    "white",    "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["Easter6"]        = new Festivity($this->PROPRIUM_DE_TEMPORE["Easter6"]["NAME_" . $this->LITSETTINGS->LOCALE],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 5) . 'D')),    "white",    "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["Trinity"]        = new Festivity($this->PROPRIUM_DE_TEMPORE["Trinity"]["NAME_" . $this->LITSETTINGS->LOCALE],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 8) . 'D')),    "white",    "mobile", LitGrade::HIGHER_SOLEMNITY);
        if ( $this->LITSETTINGS->CORPUSCHRISTI === CORPUSCHRISTI::THURSDAY ) {
            $this->LitCal["CorpusChristi"]  = new Festivity($this->PROPRIUM_DE_TEMPORE["CorpusChristi"]["NAME_" . $this->LITSETTINGS->LOCALE], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 8 + 4) . 'D')),  "white",    "mobile", LitGrade::HIGHER_SOLEMNITY);
        } else if ( $this->LITSETTINGS->CORPUSCHRISTI === CORPUSCHRISTI::SUNDAY ) {
            $this->LitCal["CorpusChristi"]  = new Festivity($this->PROPRIUM_DE_TEMPORE["CorpusChristi"]["NAME_" . $this->LITSETTINGS->LOCALE], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 9) . 'D')),    "white",    "mobile", LitGrade::HIGHER_SOLEMNITY);
        }

        //We don't use array_push for Solemnities because it's an associative array
        //It's associative because we may need to look them up by tag (key)
        $this->SOLEMNITIES["Advent1"]       = $this->LitCal["Advent1"]->date;
        $this->SOLEMNITIES["Advent2"]       = $this->LitCal["Advent2"]->date;
        $this->SOLEMNITIES["Advent3"]       = $this->LitCal["Advent3"]->date;
        $this->SOLEMNITIES["Advent4"]       = $this->LitCal["Advent4"]->date;
        $this->SOLEMNITIES["Lent1"]         = $this->LitCal["Lent1"]->date;
        $this->SOLEMNITIES["Lent2"]         = $this->LitCal["Lent2"]->date;
        $this->SOLEMNITIES["Lent3"]         = $this->LitCal["Lent3"]->date;
        $this->SOLEMNITIES["Lent4"]         = $this->LitCal["Lent4"]->date;
        $this->SOLEMNITIES["Lent5"]         = $this->LitCal["Lent5"]->date;
        $this->SOLEMNITIES["PalmSun"]       = $this->LitCal["PalmSun"]->date;
        $this->SOLEMNITIES["Easter2"]       = $this->LitCal["Easter2"]->date;
        $this->SOLEMNITIES["Easter3"]       = $this->LitCal["Easter3"]->date;
        $this->SOLEMNITIES["Easter4"]       = $this->LitCal["Easter4"]->date;
        $this->SOLEMNITIES["Easter5"]       = $this->LitCal["Easter5"]->date;
        $this->SOLEMNITIES["Easter6"]       = $this->LitCal["Easter6"]->date;
        $this->SOLEMNITIES["Trinity"]       = $this->LitCal["Trinity"]->date;
        $this->SOLEMNITIES["CorpusChristi"] = $this->LitCal["CorpusChristi"]->date;

        //Whereas SUNDAYS_ADVENT_LENT_EASTER is not an associative array
        //This is because we no longer need to look these up by tag
        array_push( $this->SUNDAYS_ADVENT_LENT_EASTER,
            $this->LitCal["Advent1"]->date,
            $this->LitCal["Advent2"]->date,
            $this->LitCal["Advent3"]->date,
            $this->LitCal["Advent4"]->date,
            $this->LitCal["Lent1"]->date,
            $this->LitCal["Lent2"]->date,
            $this->LitCal["Lent3"]->date,
            $this->LitCal["Lent4"]->date,
            $this->LitCal["Lent5"]->date,
            $this->LitCal["Easter2"]->date,
            $this->LitCal["Easter3"]->date,
            $this->LitCal["Easter4"]->date,
            $this->LitCal["Easter5"]->date,
            $this->LitCal["Easter6"]->date
        );

        $this->LitCal["Advent1"]->psalterWeek   = 1;
        $this->LitCal["Advent2"]->psalterWeek   = 2;
        $this->LitCal["Advent3"]->psalterWeek   = 3;
        $this->LitCal["Advent4"]->psalterWeek   = 4;
        $this->LitCal["Lent1"]->psalterWeek     = 1;
        $this->LitCal["Lent2"]->psalterWeek     = 2;
        $this->LitCal["Lent3"]->psalterWeek     = 3;
        $this->LitCal["Lent4"]->psalterWeek     = 4;
        $this->LitCal["Lent5"]->psalterWeek     = 1;
        $this->LitCal["Easter2"]->psalterWeek   = 2;
        $this->LitCal["Easter3"]->psalterWeek   = 3;
        $this->LitCal["Easter4"]->psalterWeek   = 4;
        $this->LitCal["Easter5"]->psalterWeek   = 1;
        $this->LitCal["Easter6"]->psalterWeek   = 2;

    }

    private function calculateAshWednesday() : void {
        $this->LitCal["AshWednesday"]           = new Festivity($this->PROPRIUM_DE_TEMPORE["AshWednesday"]["NAME_" . $this->LITSETTINGS->LOCALE], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->sub(new DateInterval('P46D')),           "purple",   "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->SOLEMNITIES["AshWednesday"]      = $this->LitCal["AshWednesday"]->date;
    }

    private function calculateWeekdaysHolyWeek() : void {
        //Weekdays of Holy Week from Monday to Thursday inclusive (that is, thursday morning chrism mass... the In Coena Domini mass begins the Easter Triduum)
        $this->LitCal["MonHolyWeek"]      = new Festivity($this->PROPRIUM_DE_TEMPORE["MonHolyWeek"]["NAME_" . $this->LITSETTINGS->LOCALE], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->sub(new DateInterval('P6D')),            "purple",   "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["TueHolyWeek"]      = new Festivity($this->PROPRIUM_DE_TEMPORE["TueHolyWeek"]["NAME_" . $this->LITSETTINGS->LOCALE], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->sub(new DateInterval('P5D')),            "purple",   "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["WedHolyWeek"]      = new Festivity($this->PROPRIUM_DE_TEMPORE["WedHolyWeek"]["NAME_" . $this->LITSETTINGS->LOCALE], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->sub(new DateInterval('P4D')),            "purple",   "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->SOLEMNITIES["MonHolyWeek"]         = $this->LitCal["MonHolyWeek"]->date;
        $this->SOLEMNITIES["TueHolyWeek"]         = $this->LitCal["TueHolyWeek"]->date;
        $this->SOLEMNITIES["WedHolyWeek"]         = $this->LitCal["WedHolyWeek"]->date;
    }

    private function calculateEasterOctave() : void {
        //Days within the octave of Easter
        $this->LitCal["MonOctaveEaster"]  = new Festivity($this->PROPRIUM_DE_TEMPORE["MonOctaveEaster"]["NAME_" . $this->LITSETTINGS->LOCALE], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P1D')),            "white",    "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["TueOctaveEaster"]  = new Festivity($this->PROPRIUM_DE_TEMPORE["TueOctaveEaster"]["NAME_" . $this->LITSETTINGS->LOCALE], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P2D')),            "white",    "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["WedOctaveEaster"]  = new Festivity($this->PROPRIUM_DE_TEMPORE["WedOctaveEaster"]["NAME_" . $this->LITSETTINGS->LOCALE], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P3D')),            "white",    "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["ThuOctaveEaster"]  = new Festivity($this->PROPRIUM_DE_TEMPORE["ThuOctaveEaster"]["NAME_" . $this->LITSETTINGS->LOCALE], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P4D')),            "white",    "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["FriOctaveEaster"]  = new Festivity($this->PROPRIUM_DE_TEMPORE["FriOctaveEaster"]["NAME_" . $this->LITSETTINGS->LOCALE], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P5D')),            "white",    "mobile", LitGrade::HIGHER_SOLEMNITY);
        $this->LitCal["SatOctaveEaster"]  = new Festivity($this->PROPRIUM_DE_TEMPORE["SatOctaveEaster"]["NAME_" . $this->LITSETTINGS->LOCALE], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P6D')),            "white",    "mobile", LitGrade::HIGHER_SOLEMNITY);

        $this->SOLEMNITIES["MonOctaveEaster"] = $this->LitCal["MonOctaveEaster"]->date;
        $this->SOLEMNITIES["TueOctaveEaster"] = $this->LitCal["TueOctaveEaster"]->date;
        $this->SOLEMNITIES["WedOctaveEaster"] = $this->LitCal["WedOctaveEaster"]->date;
        $this->SOLEMNITIES["ThuOctaveEaster"] = $this->LitCal["ThuOctaveEaster"]->date;
        $this->SOLEMNITIES["FriOctaveEaster"] = $this->LitCal["FriOctaveEaster"]->date;
        $this->SOLEMNITIES["SatOctaveEaster"] = $this->LitCal["SatOctaveEaster"]->date;
    }

    private function calculateMobileSolemnitiesOfTheLord() : void {
        $this->LitCal["SacredHeart"]      = new Festivity($this->PROPRIUM_DE_TEMPORE["SacredHeart"]["NAME_" . $this->LITSETTINGS->LOCALE],    LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 9 + 5) . 'D')),  "red",      "mobile", LitGrade::SOLEMNITY);
        $this->SOLEMNITIES["SacredHeart"] = $this->LitCal["SacredHeart"]->date;

        //Christ the King is calculated backwards from the first sunday of advent
        $this->LitCal["ChristKing"]       = new Festivity($this->PROPRIUM_DE_TEMPORE["ChristKing"]["NAME_" . $this->LITSETTINGS->LOCALE],     DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday')->sub(new DateInterval('P' . (4 * 7) . 'D')),    "red",  "mobile", LitGrade::SOLEMNITY);
        $this->SOLEMNITIES["ChristKing"]  = $this->LitCal["ChristKing"]->date;
    }

    private function calculateFixedSolemnities() : void {
        //even though Mary Mother of God is a fixed date solemnity, however it is found in the Proprium de Tempore and not in the Proprium de Sanctis
        $this->LitCal["MotherGod"]        = new Festivity($this->PROPRIUM_DE_TEMPORE["MotherGod"]["NAME_" . $this->LITSETTINGS->LOCALE], DateTime::createFromFormat('!j-n-Y', '1-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC')),      "white",    "fixed", LitGrade::SOLEMNITY);
        $this->SOLEMNITIES["MotherGod"]   = $this->LitCal["MotherGod"]->date;


        //all the other fixed date solemnities are found in the Proprium de Sanctis
        //so we will look them up in the MySQL table of festivities of the Roman Calendar from the Proper of Saints
        $result = $this->mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE GRADE = " . LitGrade::SOLEMNITY);
        if ( $result ) {
            while ($row = mysqli_fetch_assoc($result)) {
                $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row["DAY"] . '-' . $row["MONTH"] . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                $this->LitCal[$row["TAG"]] = new Festivity($row["NAME_" . $this->LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);

                //A Solemnity impeded in any given year is transferred to the nearest day following designated in nn. 1-8 of the Tables given above (LY 60)
                //However if a solemnity is impeded by a Sunday of Advent, Lent or Easter Time, the solemnity is transferred to the Monday following,
                //or to the nearest free day, as laid down by the General Norms.
                //This affects Joseph, Husband of Mary (Mar 19), Annunciation (Mar 25), and Immaculate Conception (Dec 8).
                //It is not possible for a fixed date Solemnity to fall on a Sunday of Easter.

                //However, if a solemnity is impeded by Palm Sunday or by Easter Sunday, it is transferred to the first free day (Monday?)
                //after the Second Sunday of Easter (decision of the Congregation of Divine Worship, dated 22 April 1990, in Notitiæ vol. 26 [1990] num. 3/4, p. 160, Prot. CD 500/89).
                //Any other celebrations that are impeded are omitted for that year.

                /**
                 * <<
                 *   Quando vero sollemnitates in his dominicis (i.e. Adventus, Quadragesimae et Paschae), iuxta n.5 "Normarum universalium de anno liturgico et de calendario"
                 * sabbato anticipari debent. Experientia autem pastoralis ostendit quod solutio huiusmodi nonnullas praebet difficultates praesertim quoad occurrentiam
                 * celebrationis Missae vespertinae et II Vesperarum Liturgiae Horarum cuiusdam sollemnitatis cum celebratione Missae vespertinae et I Vesperarum diei dominicae.
                 * [... Perciò facciamo la seguente modifica al n. 5 delle norme universali: ]
                 * Sollemnitates autem in his dominicis occurrentes ad feriam secundam sequentem transferuntur, nisi agatur de occurrentia in Dominica in Palmis
                 * aut in Dominica Resurrectionis Domini.
                 *  >>
                 *
                 * http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/1990/284-285.html
                 */

                if(in_array($currentFeastDate,$this->SOLEMNITIES)){

                    //if Joseph, Husband of Mary (Mar 19) falls on Palm Sunday or during Holy Week, it is moved to the Saturday preceding Palm Sunday
                    //this is correct and the reason for this is that, in this case, Annunciation will also fall during Holy Week,
                    //and the Annunciation will be transferred to the Monday following the Second Sunday of Easter
                    //Notitiæ vol. 42 [2006] num. 3/4, 475-476, p. 96
                    //http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/2006/475-476.html
                    if($row["TAG"] === "StJoseph" && $currentFeastDate >= $this->LitCal["PalmSun"]->date && $currentFeastDate <= $this->LitCal["Easter"]->date){
                        $this->LitCal["StJoseph"]->date = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->sub(new DateInterval('P8D'));
                        $this->Messages[] = sprintf(
                            LITCAL_MESSAGES::__( "The Solemnity '%s' falls on %s in the year %d, the celebration has been transferred to %s (%s) as per the %s.", $this->LITSETTINGS->LOCALE),
                            $this->LitCal["StJoseph"]->name,
                            $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)]->name,
                            $this->LITSETTINGS->YEAR,
                            LITCAL_MESSAGES::__( "the Saturday preceding Palm Sunday",$this->LITSETTINGS->LOCALE),
                            $this->LITSETTINGS->LOCALE === 'LA' ? ( $this->LitCal["StJoseph"]->date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$this->LitCal["StJoseph"]->date->format('n')] ) :
                                ( $this->LITSETTINGS->LOCALE === 'EN' ? $this->LitCal["StJoseph"]->date->format('F jS') :
                                    trim(utf8_encode(strftime('%e %B', $this->LitCal["StJoseph"]->date->format('U'))))
                                ),
                            '<a href="http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/2006/475-476.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>'
                        );
                    }
                    else if($row["TAG"] === "Annunciation" && $currentFeastDate >= $this->LitCal["PalmSun"]->date && $currentFeastDate <= $this->LitCal["Easter2"]->date){
                        //if the Annunciation falls during Holy Week or within the Octave of Easter, it is transferred to the Monday after the Second Sunday of Easter.
                        $this->LitCal["Annunciation"]->date = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P8D'));
                        $this->Messages[] = sprintf(
                            LITCAL_MESSAGES::__( "The Solemnity '%s' falls on %s in the year %d, the celebration has been transferred to %s (%s) as per the %s.", $this->LITSETTINGS->LOCALE),
                            $this->LitCal["Annunciation"]->name,
                            $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)]->name,
                            $this->LITSETTINGS->YEAR,
                            LITCAL_MESSAGES::__( 'the Monday following the Second Sunday of Easter',$this->LITSETTINGS->LOCALE),
                            $this->LITSETTINGS->LOCALE === 'LA' ? ( $this->LitCal["Annunciation"]->date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$this->LitCal["Annunciation"]->date->format('n')] ) :
                                ( $this->LITSETTINGS->LOCALE === 'EN' ? $this->LitCal["Annunciation"]->date->format('F jS') :
                                    trim(utf8_encode(strftime('%e %B', $this->LitCal["Annunciation"]->date->format('U'))))
                                ),
                            '<a href="http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/2006/475-476.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>'
                        );

                        //In some German churches it was the custom to keep the office of the Annunciation on the Saturday before Palm Sunday if the 25th of March fell in Holy Week.
                        //source: http://www.newadvent.org/cathen/01542a.htm
                        /*
                            else if($this->LitCal["Annunciation"]->date == $this->LitCal["PalmSun"]->date){
                            $this->LitCal["Annunciation"]->date->add(new DateInterval('P15D'));
                            //$this->LitCal["Annunciation"]->date->sub(new DateInterval('P1D'));
                            }
                        */

                    }
                    else if(in_array($row["TAG"],["Annunciation","StJoseph","ImmaculateConception"]) && in_array($currentFeastDate,$this->SUNDAYS_ADVENT_LENT_EASTER)){
                        $this->LitCal[$row["TAG"]]->date = clone($currentFeastDate);
                        $this->LitCal[$row["TAG"]]->date->add(new DateInterval('P1D'));
                        $this->Messages[] = sprintf(
                            LITCAL_MESSAGES::__( "The Solemnity '%s' falls on %s in the year %d, the celebration has been transferred to %s (%s) as per the %s.", $this->LITSETTINGS->LOCALE),
                            $this->LitCal[$row["TAG"]]->name,
                            $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)]->name,
                            $this->LITSETTINGS->YEAR,
                            LITCAL_MESSAGES::__( "the following Monday",$this->LITSETTINGS->LOCALE),
                            $this->LITSETTINGS->LOCALE === 'LA' ? ( $this->LitCal[$row["TAG"]]->date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$this->LitCal[$row["TAG"]]->date->format('n')] ) :
                                ( $this->LITSETTINGS->LOCALE === 'EN' ? $this->LitCal[$row["TAG"]]->date->format('F jS') :
                                    trim(utf8_encode(strftime('%e %B', $this->LitCal[$row["TAG"]]->date->format('U'))))
                                ),
                            '<a href="http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/1990/284-285.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>'
                        );
                    }
                    else{
                        //In all other cases, let's make a note of what's happening and ask the Congegation for Divine Worship
                        $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                            LITCAL_MESSAGES::__( "The Solemnity '%s' coincides with the Solemnity '%s' in the year %d. We should ask the Congregation for Divine Worship what to do about this!", $this->LITSETTINGS->LOCALE),
                            $row["NAME_" . $this->LITSETTINGS->LOCALE],
                            $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)]->name,
                            $this->LITSETTINGS->YEAR
                        );
                    }

                    //In the year 2022, the Solemnity Nativity of John the Baptist coincides with the Solemnity of the Sacred Heart
                    //Nativity of John the Baptist anticipated by one day to June 23
                    //(except in cases where John the Baptist is patron of a nation, diocese, city or religious community, then the Sacred Heart can be anticipated by one day to June 23)
                    //http://www.cultodivino.va/content/cultodivino/it/documenti/responsa-ad-dubia/2020/de-calendario-liturgico-2022.html
                    //This will happen again in 2033 and 2044
                    if($row["TAG"] === "NativityJohnBaptist" && array_search($currentFeastDate,$this->SOLEMNITIES) === "SacredHeart" ){
                        $NativityJohnBaptistNewDate = clone($this->LitCal["NativityJohnBaptist"]->date);
                        if( !in_array( $NativityJohnBaptistNewDate->sub(new DateInterval('P1D')), $this->SOLEMNITIES ) ){
                            $this->LitCal["NativityJohnBaptist"]->date->sub(new DateInterval('P1D'));
                            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                LITCAL_MESSAGES::__( "Seeing that the Solemnity '%s' coincides with the Solemnity '%s' in the year %d, it has been anticipated by one day as per %s.", $this->LITSETTINGS->LOCALE),
                                $this->LitCal["NativityJohnBaptist"]->name,
                                $this->LitCal["SacredHeart"]->name,
                                $this->LITSETTINGS->YEAR,
                                '<a href="http://www.cultodivino.va/content/cultodivino/it/documenti/responsa-ad-dubia/2020/de-calendario-liturgico-2022.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>'
                            );
                        }
                    }
                }
            }
        }


        $this->SOLEMNITIES["NativityJohnBaptist"] = $this->LitCal["NativityJohnBaptist"]->date;
        $this->SOLEMNITIES["StsPeterPaulAp"]      = $this->LitCal["StsPeterPaulAp"]->date;
        $this->SOLEMNITIES["Assumption"]          = $this->LitCal["Assumption"]->date;
        $this->SOLEMNITIES["AllSaints"]           = $this->LitCal["AllSaints"]->date;
        $this->SOLEMNITIES["AllSouls"]            = $this->LitCal["AllSouls"]->date;
        $this->SOLEMNITIES["StJoseph"]            = $this->LitCal["StJoseph"]->date;
        $this->SOLEMNITIES["Annunciation"]        = $this->LitCal["Annunciation"]->date;
        $this->SOLEMNITIES["ImmaculateConception"]= $this->LitCal["ImmaculateConception"]->date;

        //let's add a displayGrade property for AllSouls so applications don't have to worry about fixing it
        $this->LitCal["AllSouls"]->displayGrade = strip_tags(LITCAL_MESSAGES::__( "COMMEMORATION",$this->LITSETTINGS->LOCALE));

        $this->SOLEMNITIES_LORD_BVM = [
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
        ];

    }

    private function calculateFeastsOfTheLord() : void {
        //Baptism of the Lord is celebrated the Sunday after Epiphany, for exceptions see immediately below...
        $this->BaptismLordFmt = '6-1-' . $this->LITSETTINGS->YEAR;
        $this->BaptismLordMod = 'next Sunday';
        //If Epiphany is celebrated on Sunday between Jan. 2 - Jan 8, and Jan. 7 or Jan. 8 is Sunday, then Baptism of the Lord is celebrated on the Monday immediately following that Sunday
        if ( $this->LITSETTINGS->EPIPHANY === EPIPHANY::SUNDAY_JAN2_JAN8 ) {
            if ((int)DateTime::createFromFormat('!j-n-Y', '7-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->format('N') === 7) {
                $this->BaptismLordFmt = '7-1-' . $this->LITSETTINGS->YEAR;
                $this->BaptismLordMod = 'next Monday';
            } else if ((int)DateTime::createFromFormat('!j-n-Y', '8-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->format('N') === 7) {
                $this->BaptismLordFmt = '8-1-' . $this->LITSETTINGS->YEAR;
                $this->BaptismLordMod = 'next Monday';
            }
        }
        $this->LitCal["BaptismLord"]      = new Festivity($this->PROPRIUM_DE_TEMPORE["BaptismLord"]["NAME_" . $this->LITSETTINGS->LOCALE], DateTime::createFromFormat('!j-n-Y', $this->BaptismLordFmt, new DateTimeZone('UTC'))->modify($this->BaptismLordMod), "white", "mobile", LitGrade::FEAST_LORD);
        $this->SOLEMNITIES["BaptismLord"]     = $this->LitCal["BaptismLord"]->date;

        //the other feasts of the Lord (Presentation, Transfiguration and Triumph of the Holy Cross) are fixed date feasts
        //and are found in the Proprium de Sanctis
        //so we will look them up in the MySQL table of festivities of the Roman Calendar from the Proper of Saints
        $result = $this->mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE GRADE = " . LitGrade::FEAST_LORD);
        if ( $result ) {
            while ($row = mysqli_fetch_assoc($result)) {
                $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row["DAY"] . '-' . $row["MONTH"] . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                $this->LitCal[$row["TAG"]] = new Festivity($row["NAME_" . $this->LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);
            }
        }
        $this->SOLEMNITIES["Presentation"]    = $this->LitCal["Presentation"]->date;
        $this->SOLEMNITIES["Transfiguration"] = $this->LitCal["Transfiguration"]->date;
        $this->SOLEMNITIES["ExaltationCross"] = $this->LitCal["ExaltationCross"]->date;

        //Holy Family is celebrated the Sunday after Christmas, unless Christmas falls on a Sunday, in which case it is celebrated Dec. 30
        if ((int)DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->format('N') === 7) {
            $this->LitCal["HolyFamily"]   = new Festivity($this->PROPRIUM_DE_TEMPORE["HolyFamily"]["NAME_" . $this->LITSETTINGS->LOCALE], DateTime::createFromFormat('!j-n-Y', '30-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC')),           "white",    "mobile", LitGrade::FEAST_LORD);
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "'%s' falls on a Sunday in the year %d, therefore the Feast '%s' is celebrated on %s rather than on the Sunday after Christmas.", $this->LITSETTINGS->LOCALE),
                $this->LitCal["Christmas"]->name,
                $this->LITSETTINGS->YEAR,
                $this->LitCal["HolyFamily"]->name,
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $this->LitCal["HolyFamily"]->date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$this->LitCal["HolyFamily"]->date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $this->LitCal["HolyFamily"]->date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $this->LitCal["HolyFamily"]->date->format('U'))))
                    )
            );
        } else {
            $this->LitCal["HolyFamily"]   = new Festivity($this->PROPRIUM_DE_TEMPORE["HolyFamily"]["NAME_" . $this->LITSETTINGS->LOCALE], DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('next Sunday'),                                          "white", "mobile", LitGrade::FEAST_LORD);
        }
        $this->SOLEMNITIES["HolyFamily"]      = $this->LitCal["HolyFamily"]->date;

    }

    private function calculateSundaysChristmasOrdinaryTime() : void {
        //If a fixed date Solemnity occurs on a Sunday of Ordinary Time or on a Sunday of Christmas, the Solemnity is celebrated in place of the Sunday. (e.g., Birth of John the Baptist, 1990)
        //If a fixed date Feast of the Lord occurs on a Sunday in Ordinary Time, the feast is celebrated in place of the Sunday

        //Sundays of Ordinary Time in the First part of the year are numbered from after the Baptism of the Lord (which begins the 1st week of Ordinary Time) until Ash Wednesday
        $firstOrdinary = DateTime::createFromFormat('!j-n-Y', $this->BaptismLordFmt, new DateTimeZone('UTC'))->modify($this->BaptismLordMod);
        //Basically we take Ash Wednesday as the limit...
        //Here is (Ash Wednesday - 7) since one more cycle will complete...
        $firstOrdinaryLimit = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->sub(new DateInterval('P53D'));
        $ordSun = 1;
        while ($firstOrdinary >= $this->LitCal["BaptismLord"]->date && $firstOrdinary < $firstOrdinaryLimit) {
            $firstOrdinary = DateTime::createFromFormat('!j-n-Y', $this->BaptismLordFmt, new DateTimeZone('UTC'))->modify($this->BaptismLordMod)->modify('next Sunday')->add(new DateInterval('P' . (($ordSun - 1) * 7) . 'D'));
            $ordSun++;
            if (!in_array($firstOrdinary, $this->SOLEMNITIES)) {
                $this->LitCal["OrdSunday" . $ordSun] = new Festivity($this->PROPRIUM_DE_TEMPORE["OrdSunday" . $ordSun]["NAME_" . $this->LITSETTINGS->LOCALE], $firstOrdinary, "green", "mobile", LitGrade::FEAST_LORD);
            $this->LitCal["OrdSunday" . $ordSun]->psalterWeek = LitCalFf::psalterWeek($ordSun);
                //add Sundays to our priority list for next checking against ordinary Feasts not of Our Lord
                $this->SOLEMNITIES["OrdSunday" . $ordSun]      = $firstOrdinary;

            } else {
                $this->Messages[] = sprintf(
                    LITCAL_MESSAGES::__( "'%s' is superseded by the %s '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                    $this->PROPRIUM_DE_TEMPORE["OrdSunday" . $ordSun]["NAME_" . $this->LITSETTINGS->LOCALE],
                    $this->LitCal[array_search($firstOrdinary,$this->SOLEMNITIES)]->grade > LitGrade::SOLEMNITY ? '<i>' . LITCAL_MESSAGES::_G( $this->LitCal[array_search($firstOrdinary,$this->SOLEMNITIES)]->grade,$this->LITSETTINGS->LOCALE,false) . '</i>' : LITCAL_MESSAGES::_G( $this->LitCal[array_search($firstOrdinary,$this->SOLEMNITIES)]->grade,$this->LITSETTINGS->LOCALE,false),
                    $this->LitCal[array_search($firstOrdinary,$this->SOLEMNITIES)]->name,
                    $this->LITSETTINGS->YEAR
                );
            }
        }

        //Sundays of Ordinary Time in the Latter part of the year are numbered backwards from Christ the King (34th) to Pentecost
        $lastOrdinary = DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday')->sub(new DateInterval('P' . (4 * 7) . 'D'));
        //We take Trinity Sunday as the limit...
        //Here is (Trinity Sunday + 7) since one more cycle will complete...
        $lastOrdinaryLowerLimit = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 9) . 'D'));
        $ordSun = 34;
        $ordSunCycle = 4;

        while ($lastOrdinary <= $this->LitCal["ChristKing"]->date && $lastOrdinary > $lastOrdinaryLowerLimit) {
            $lastOrdinary = DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday')->sub(new DateInterval('P' . (++$ordSunCycle * 7) . 'D'));
            $ordSun--;
            if (!in_array($lastOrdinary, $this->SOLEMNITIES)) {
                $this->LitCal["OrdSunday" . $ordSun] = new Festivity($this->PROPRIUM_DE_TEMPORE["OrdSunday" . $ordSun]["NAME_" . $this->LITSETTINGS->LOCALE], $lastOrdinary, "green", "mobile", LitGrade::FEAST_LORD);
            $this->LitCal["OrdSunday" . $ordSun]->psalterWeek = LitCalFf::psalterWeek($ordSun);	
                //add Sundays to our priority list for next checking against ordinary Feasts not of Our Lord
                $this->SOLEMNITIES["OrdSunday" . $ordSun]      = $lastOrdinary;
            } else {
                $this->Messages[] = sprintf(
                    LITCAL_MESSAGES::__( "'%s' is superseded by the %s '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                    $this->PROPRIUM_DE_TEMPORE["OrdSunday" . $ordSun]["NAME_" . $this->LITSETTINGS->LOCALE],
                    $this->LitCal[array_search($lastOrdinary,$this->SOLEMNITIES)]->grade > LitGrade::SOLEMNITY ? '<i>' . LITCAL_MESSAGES::_G( $this->LitCal[array_search($lastOrdinary,$this->SOLEMNITIES)]->grade,$this->LITSETTINGS->LOCALE,false) . '</i>' : LITCAL_MESSAGES::_G( $this->LitCal[array_search($lastOrdinary,$this->SOLEMNITIES)]->grade,$this->LITSETTINGS->LOCALE,false),
                    $this->LitCal[array_search($lastOrdinary,$this->SOLEMNITIES)]->name,
                    $this->LITSETTINGS->YEAR
                );
            }
        }

    }

    private function calculateFeastsMarySaints() : void {
        //We will look up Feasts from the MySQL table of festivities of the General Roman Calendar
        //First we get the Calendarium Romanum Generale from the Missale Romanum Editio Typica 1970
        $result = $this->mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE GRADE = " . LitGrade::FEAST);
        if ( $result ) {
            while ($row = mysqli_fetch_assoc($result)) {

                //If a Feast (not of the Lord) occurs on a Sunday in Ordinary Time, the Sunday is celebrated.  (e.g., St. Luke, 1992)
                //obviously solemnities also have precedence
                $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row["DAY"] . '-' . $row["MONTH"] . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                if ((int)$currentFeastDate->format('N') !== 7 && !in_array($currentFeastDate, $this->SOLEMNITIES)) {
                    $this->LitCal[$row["TAG"]] = new Festivity($row["NAME_" . $this->LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);
                    $this->FEASTS_MEMORIALS[$row["TAG"]]      = $currentFeastDate;
                } else {
                    $coincidingFestivity = $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)];
                    $coincidingFestivity_grade = '';
                    if((int)$currentFeastDate->format('N') === 7 && $coincidingFestivity->grade < LitGrade::SOLEMNITY ){
                        //it's a Sunday
                        $coincidingFestivity_grade = $this->LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
                    } else{
                        //it's a Feast of the Lord or a Solemnity
                        $coincidingFestivity_grade = ($coincidingFestivity->grade > LitGrade::SOLEMNITY ? '<i>' . LITCAL_MESSAGES::_G( $coincidingFestivity->grade,$this->LITSETTINGS->LOCALE,false) . '</i>' : LITCAL_MESSAGES::_G( $coincidingFestivity->grade,$this->LITSETTINGS->LOCALE,false));
                    }

                    $this->Messages[] = sprintf(
                        LITCAL_MESSAGES::__( "The %s '%s', usually celebrated on %s, is suppressed by the %s '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                        LITCAL_MESSAGES::_G( $row["GRADE"],$this->LITSETTINGS->LOCALE,false),
                        $row["NAME_" . $this->LITSETTINGS->LOCALE],
                        $this->LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                            ( $this->LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                                trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                            ),
                        $coincidingFestivity_grade,
                        $coincidingFestivity->name,
                        $this->LITSETTINGS->YEAR
                    );
                }
            }
        }

        //With the decree Apostolorum Apostola (June 3rd 2016), the Congregation for Divine Worship
        //with the approval of Pope Francis elevated the memorial of Saint Mary Magdalen to a Feast
        //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/articolo-roche-maddalena_it.pdf
        //This is taken care of ahead when the memorials are created, see comment tag MARYMAGDALEN:

    }

    private function calculateWeekdaysAdvent() : void {
        //  Here we are calculating all weekdays of Advent, but we are giving a certain importance to the weekdays of Advent from 17 Dec. to 24 Dec.
        //  (the same will be true of the Octave of Christmas and weekdays of Lent)
        //  on which days obligatory memorials can only be celebrated in partial form

        $DoMAdvent1 = $this->LitCal["Advent1"]->date->format('j'); //DoM == Day of Month
        $MonthAdvent1 = $this->LitCal["Advent1"]->date->format('n');
        $weekdayAdvent = DateTime::createFromFormat('!j-n-Y', $DoMAdvent1 . '-' . $MonthAdvent1 . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        $weekdayAdventCnt = 1;
        while ($weekdayAdvent >= $this->LitCal["Advent1"]->date && $weekdayAdvent < $this->LitCal["Christmas"]->date) {
            $weekdayAdvent = DateTime::createFromFormat('!j-n-Y', $DoMAdvent1 . '-' . $MonthAdvent1 . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->add(new DateInterval('P' . $weekdayAdventCnt . 'D'));

            //if we're not dealing with a sunday or a solemnity, then create the weekday
            if (!in_array($weekdayAdvent, $this->SOLEMNITIES) && !in_array($weekdayAdvent, $this->FEASTS_MEMORIALS) && (int)$weekdayAdvent->format('N') !== 7) {
                $upper = (int)$weekdayAdvent->format('z');
                $diff = $upper - (int)$this->LitCal["Advent1"]->date->format('z'); //day count between current day and First Sunday of Advent
                $currentAdvWeek = (($diff - $diff % 7) / 7) + 1; //week count between current day and First Sunday of Advent

                $ordinal = ucfirst(LitCalFf::getOrdinal($currentAdvWeek,$this->LITSETTINGS->LOCALE,$this->formatterFem,LITCAL_MESSAGES::LATIN_ORDINAL_FEM_GEN));
                $this->LitCal["AdventWeekday" . $weekdayAdventCnt] = new Festivity(( $this->LITSETTINGS->LOCALE == 'LA' ? LITCAL_MESSAGES::LATIN_DAYOFTHEWEEK[$weekdayAdvent->format('w')] : ucfirst(utf8_encode(strftime('%A',$weekdayAdvent->format('U'))))) . " " . sprintf(LITCAL_MESSAGES::__( "of the %s Week of Advent",$this->LITSETTINGS->LOCALE),$ordinal), $weekdayAdvent, "purple", "mobile");
                // Weekday of Advent from 17 to 24 Dec.
                if ($this->LitCal["AdventWeekday" . $weekdayAdventCnt]->date->format('j') >= 17 && $this->LitCal["AdventWeekday" . $weekdayAdventCnt]->date->format('j') <= 24) {
                    array_push($this->WEEKDAYS_ADVENT_CHRISTMAS_LENT, $this->LitCal["AdventWeekday" . $weekdayAdventCnt]->date);
                }
            }

            $weekdayAdventCnt++;
        }
    }

    private function calculateWeekdaysChristmasOctave() : void {
        $weekdayChristmas = DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        $weekdayChristmasCnt = 1;
        while ($weekdayChristmas >= $this->LitCal["Christmas"]->date && $weekdayChristmas < DateTime::createFromFormat('!j-n-Y', '31-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))) {
            $weekdayChristmas = DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->add(new DateInterval('P' . $weekdayChristmasCnt . 'D'));

            if (!in_array($weekdayChristmas, $this->SOLEMNITIES) && !in_array($weekdayChristmas, $this->FEASTS_MEMORIALS) && (int)$weekdayChristmas->format('N') !== 7) {
                $ordinal = ucfirst( LitCalFf::getOrdinal( ($weekdayChristmasCnt + 1), $this->LITSETTINGS->LOCALE, $this->formatter, LITCAL_MESSAGES::LATIN_ORDINAL ) );
                $this->LitCal["ChristmasWeekday" . $weekdayChristmasCnt] = new Festivity(sprintf(LITCAL_MESSAGES::__( "%s Day of the Octave of Christmas",$this->LITSETTINGS->LOCALE),$ordinal), $weekdayChristmas, "white", "mobile");
                array_push($this->WEEKDAYS_ADVENT_CHRISTMAS_LENT, $this->LitCal["ChristmasWeekday" . $weekdayChristmasCnt]->date);
            }

            $weekdayChristmasCnt++;
        }
    }

    private function calculateWeekdaysLent() : void {

        //Day of the Month of Ash Wednesday
        $DoMAshWednesday = $this->LitCal["AshWednesday"]->date->format('j');
        $MonthAshWednesday = $this->LitCal["AshWednesday"]->date->format('n');
        $weekdayLent = DateTime::createFromFormat('!j-n-Y', $DoMAshWednesday . '-' . $MonthAshWednesday . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        $weekdayLentCnt = 1;
        while ($weekdayLent >= $this->LitCal["AshWednesday"]->date && $weekdayLent < $this->LitCal["PalmSun"]->date) {
            $weekdayLent = DateTime::createFromFormat('!j-n-Y', $DoMAshWednesday . '-' . $MonthAshWednesday . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->add(new DateInterval('P' . $weekdayLentCnt . 'D'));
        
            if (!in_array($weekdayLent, $this->SOLEMNITIES) && (int)$weekdayLent->format('N') !== 7) {
        
                if ($weekdayLent > $this->LitCal["Lent1"]->date) {
                    $upper = (int)$weekdayLent->format('z');
                    $diff = $upper - (int)$this->LitCal["Lent1"]->date->format('z'); //day count between current day and First Sunday of Lent
                    $currentLentWeek = (($diff - $diff % 7) / 7) + 1; //week count between current day and First Sunday of Lent
                    $ordinal = ucfirst(LitCalFf::getOrdinal($currentLentWeek,$this->LITSETTINGS->LOCALE,$this->formatterFem,LITCAL_MESSAGES::LATIN_ORDINAL_FEM_GEN));
                    $this->LitCal["LentWeekday" . $weekdayLentCnt] = new Festivity(( $this->LITSETTINGS->LOCALE == 'LA' ? LITCAL_MESSAGES::LATIN_DAYOFTHEWEEK[$weekdayLent->format('w')] : ucfirst(utf8_encode(strftime('%A',$weekdayLent->format('U'))))) . " ".  sprintf(LITCAL_MESSAGES::__( "of the %s Week of Lent",$this->LITSETTINGS->LOCALE),$ordinal), $weekdayLent, "purple", "mobile");
                } else {
                    $this->LitCal["LentWeekday" . $weekdayLentCnt] = new Festivity(( $this->LITSETTINGS->LOCALE == 'LA' ? LITCAL_MESSAGES::LATIN_DAYOFTHEWEEK[$weekdayLent->format('w')] : ucfirst(utf8_encode(strftime('%A',$weekdayLent->format('U'))))) . " ". LITCAL_MESSAGES::__( "after Ash Wednesday",$this->LITSETTINGS->LOCALE), $weekdayLent, "purple", "mobile");
                }
                array_push($this->WEEKDAYS_ADVENT_CHRISTMAS_LENT, $this->LitCal["LentWeekday" . $weekdayLentCnt]->date);
            }
        
            $weekdayLentCnt++;
        }

    }

    private function calculateMemorials() : void {

        $ImmaculateHeart_date = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 9 + 6) . 'D'));
        if (!in_array($ImmaculateHeart_date, $this->SOLEMNITIES) && !in_array($ImmaculateHeart_date, $this->FEASTS_MEMORIALS) ) {
            //Immaculate Heart of Mary fixed on the Saturday following the second Sunday after Pentecost
            //(see Calendarium Romanum Generale in Missale Romanum Editio Typica 1970)
            //Pentecost = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P'.(7*7).'D'))
            //Second Sunday after Pentecost = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P'.(7*9).'D'))
            //Following Saturday = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P'.(7*9+6).'D'))
            $this->LitCal["ImmaculateHeart"]  = new Festivity($this->PROPRIUM_DE_TEMPORE["ImmaculateHeart"]["NAME_" . $this->LITSETTINGS->LOCALE],       $ImmaculateHeart_date,  "white",      "mobile", LitGrade::MEMORIAL);
            $this->FEASTS_MEMORIALS["ImmaculateHeart"]      = $this->LitCal["ImmaculateHeart"]->date;
        
            //In years when this memorial coincides with another obligatory memorial, as happened in 2014 [28 June, Saint Irenaeus] and 2015 [13 June, Saint Anthony of Padua], both must be considered optional for that year
            //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
            //This is taken care of in the next code cycle, see tag IMMACULATEHEART: in the code comments ahead
        } else {
            $coincidingFeast_grade = '';
            if(in_array($ImmaculateHeart_date, $this->SOLEMNITIES)){
                $coincidingFeast = $this->LitCal[array_search($ImmaculateHeart_date,$this->SOLEMNITIES)];
                if((int)$ImmaculateHeart_date->format('N') === 7 && $coincidingFeast->grade < LitGrade::SOLEMNITY ){
                    $coincidingFestivity_grade = $this->LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
                } else {
                    $coincidingFeast_grade = LITCAL_MESSAGES::_G( $coincidingFeast->grade,$this->LITSETTINGS->LOCALE);
                }
            }
            else if(in_array($ImmaculateHeart_date, $this->FEASTS_MEMORIALS)){
                $coincidingFeast = $this->LitCal[array_search($ImmaculateHeart_date,$this->FEASTS_MEMORIALS)];
                $coincidingFeast_grade = LITCAL_MESSAGES::_G( $coincidingFeast->grade,$this->LITSETTINGS->LOCALE);
            }
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The %s '%s', usually celebrated on %s, is suppressed by the %s '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                LITCAL_MESSAGES::_G( "MEMORIAL",$this->LITSETTINGS->LOCALE),
                $this->PROPRIUM_DE_TEMPORE["ImmaculateHeart"]["NAME_" . $this->LITSETTINGS->LOCALE],
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $ImmaculateHeart_date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$ImmaculateHeart_date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $ImmaculateHeart_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $ImmaculateHeart_date->format('U'))))
                    ),
                $coincidingFeast_grade,
                $coincidingFeast->name,
                $this->LITSETTINGS->YEAR
            );
        }

        $result = $this->mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE GRADE = " . LitGrade::MEMORIAL);
        if ( $result ) {
            while ($row = mysqli_fetch_assoc($result)) {

                //If it doesn't occur on a Sunday or a Solemnity or a Feast of the Lord, then go ahead and create the Memorial
                $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row["DAY"] . '-' . $row["MONTH"] . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                if ((int)$currentFeastDate->format('N') !== 7 && !in_array($currentFeastDate, $this->SOLEMNITIES) && !in_array($currentFeastDate, $this->FEASTS_MEMORIALS) ) {
                    $this->LitCal[$row["TAG"]] = new Festivity($row["NAME_" . $this->LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);

                    //If a fixed date Memorial falls within the Lenten season, it is reduced in rank to a Commemoration.
                    if ($currentFeastDate > $this->LitCal["AshWednesday"]->date && $currentFeastDate < $this->LitCal["HolyThurs"]->date) {
                        $this->LitCal[$row["TAG"]]->grade = LitGrade::COMMEMORATION;
                        $this->Messages[] = sprintf(
                            LITCAL_MESSAGES::__( "The %s '%s' falls within the Lenten season in the year %d, rank reduced to Commemoration.",$this->LITSETTINGS->LOCALE),
                            LITCAL_MESSAGES::_G( $row["GRADE"],$this->LITSETTINGS->LOCALE),
                            $row["NAME_" . $this->LITSETTINGS->LOCALE],
                            $this->LITSETTINGS->YEAR
                        );
                    }

                    //We can now add, for logical reasons, Feasts and Memorials to the $this->FEASTS_MEMORIALS array
                    if ($this->LitCal[$row["TAG"]]->grade > LitGrade::MEMORIAL_OPT) {
                        $this->FEASTS_MEMORIALS[$row["TAG"]]      = $currentFeastDate;

                        //Also, while we're add it, let's remove the weekdays of Epiphany that get overriden by memorials
                        $key = array_search($this->LitCal[$row["TAG"]]->date, $this->WEEKDAYS_EPIPHANY);
                        if ( false !== $key ) {
                            $this->Messages[] = sprintf(
                                LITCAL_MESSAGES::__( "'%s' is superseded by the %s '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                                $this->LitCal[$key]->name,
                                LITCAL_MESSAGES::_G( $this->LitCal[$row["TAG"]]->grade,$this->LITSETTINGS->LOCALE,false),
                                $this->LitCal[$row["TAG"]]->name,
                                $this->LITSETTINGS->YEAR
                            );
                            unset($this->LitCal[$key]);
                        }
                        //IMMACULATEHEART: in years when the memorial of the Immaculate Heart of Mary coincides with another obligatory memorial,
                        //as happened in 2014 [28 June, Saint Irenaeus] and 2015 [13 June, Saint Anthony of Padua], both must be considered optional for that year
                        //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
                        if (isset($this->LitCal["ImmaculateHeart"]) && $currentFeastDate == $this->LitCal["ImmaculateHeart"]->date) {
                            $this->LitCal["ImmaculateHeart"]->grade = LitGrade::MEMORIAL_OPT;
                            $this->LitCal[$row["TAG"]]->grade = LitGrade::MEMORIAL_OPT;
                            //unset($this->LitCal[$key]); $this->FEASTS_MEMORIALS ImmaculateHeart
                            $this->Messages[] = sprintf(
                                LITCAL_MESSAGES::__( "The Memorial '%s' coincides with another Memorial '%s' in the year %d. They are both reduced in rank to optional memorials (%s).",$this->LITSETTINGS->LOCALE),
                                $this->LitCal["ImmaculateHeart"]->name,
                                $this->LitCal[$row["TAG"]]->name,
                                $this->LITSETTINGS->YEAR,
                                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>'
                            );
                        }
                    }

                } else {
                    $coincidingFestivity_grade = '';
                    if((int)$currentFeastDate->format('N') === 7 && $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)]->grade < LitGrade::SOLEMNITY ){
                        //it's a Sunday
                        $coincidingFestivity = $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)];
                        $coincidingFestivity_grade = $this->LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
                    } else if (in_array($currentFeastDate, $this->SOLEMNITIES)){
                        //it's a Feast of the Lord or a Solemnity
                        $coincidingFestivity = $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)];
                        $coincidingFestivity_grade = ($coincidingFestivity->grade > LitGrade::SOLEMNITY ? '<i>' . LITCAL_MESSAGES::_G( $coincidingFestivity->grade,$this->LITSETTINGS->LOCALE,false) . '</i>' : LITCAL_MESSAGES::_G( $coincidingFestivity->grade,$this->LITSETTINGS->LOCALE,false));
                    } else if(in_array($currentFeastDate, $this->FEASTS_MEMORIALS)){
                        $coincidingFestivity = $this->LitCal[array_search($currentFeastDate,$this->FEASTS_MEMORIALS)];
                        $coincidingFestivity_grade = LITCAL_MESSAGES::_G( $coincidingFestivity->grade,$this->LITSETTINGS->LOCALE,false);
                    }
        
                    $this->Messages[] = sprintf(
                        LITCAL_MESSAGES::__( "The %s '%s', usually celebrated on %s, is suppressed by the %s '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                        LITCAL_MESSAGES::_G( $row["GRADE"],$this->LITSETTINGS->LOCALE,false),
                        $row["NAME_" . $this->LITSETTINGS->LOCALE],
                        $this->LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                            ( $this->LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                                trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                            ),
                        $coincidingFestivity_grade,
                        $coincidingFestivity->name,
                        $this->LITSETTINGS->YEAR
                    );
                }
            }
        }

    }

    private function applyDoctorDecree1998() : void {

        if(array_key_exists("StThereseChildJesus",$this->LitCal) ){
            $etDoctor = '';
            switch( $this->LITSETTINGS->LOCALE){
                case 'LA':
                    $etDoctor = " et doctoris";
                break;
                case 'EN':
                    $etDoctor = " and doctor of the Church";
                break;
                case 'IT':
                    $etDoctor = " e dottore della Chiesa";
                break;
            }
            $this->LitCal['StThereseChildJesus']->name .= $etDoctor;
        }

    }

    private function applyFeastDecree2016() : void {

        //MARYMAGDALEN: With the decree Apostolorum Apostola (June 3rd 2016), the Congregation for Divine Worship
        //with the approval of Pope Francis elevated the memorial of Saint Mary Magdalen to a Feast
        //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/articolo-roche-maddalena_it.pdf

        if (array_key_exists("StMaryMagdalene",$this->LitCal)) {
            if ($this->LitCal["StMaryMagdalene"]->grade == LitGrade::MEMORIAL) {
                $this->Messages[] = sprintf(
                    LITCAL_MESSAGES::__( "The %s '%s' has been raised to the rank of %s since the year %d, applicable to the year %d (%s).",$this->LITSETTINGS->LOCALE),
                    LITCAL_MESSAGES::_G( $this->LitCal["StMaryMagdalene"]->grade,$this->LITSETTINGS->LOCALE),
                    $this->LitCal["StMaryMagdalene"]->name,
                    LITCAL_MESSAGES::_G( LitGrade::FEAST,$this->LITSETTINGS->LOCALE),
                    2016,
                    $this->LITSETTINGS->YEAR,
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/articolo-roche-maddalena_it.pdf">' . LITCAL_MESSAGES::__( "Decree of the Congregation for Divine Worship", $this->LITSETTINGS->LOCALE) . '</a>'
                );
                $this->LitCal["StMaryMagdalene"]->grade = LitGrade::FEAST;
            }
        }

    }

    /*if we are dealing with a calendar from the year 2002 onwards we need to add the new obligatory memorials from the Tertia Editio Typica:
    14 augusti:  S. Maximiliani Mariæ Kolbe, presbyteri et martyris;
    20 septembris:  Ss. Andreæ Kim Taegon, presbyteri, et Pauli Chong Hasang et sociorum, martyrum;
    24 novembris:  Ss. Andreæ Dung-Lac, presbyteri, et sociorum, martyrum.
    source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_it.html
    */
    private function applyMemorialsTertiaEditioTypica2002() : void {
        $result = $this->mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis_2002 WHERE GRADE = " . LitGrade::MEMORIAL);
        if ( $result ) {
            while ($row = mysqli_fetch_assoc($result)) {

                //If it doesn't occur on a Sunday or a Solemnity or a Feast of the Lord, then go ahead and create the Festivity
                $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row["DAY"] . '-' . $row["MONTH"] . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                if ((int)$currentFeastDate->format('N') !== 7 && !in_array($currentFeastDate, $this->SOLEMNITIES) && !in_array($currentFeastDate, $this->FEASTS_MEMORIALS) ) {
                    $this->LitCal[$row["TAG"]] = new Festivity($row["NAME_" . $this->LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);
                    $this->Messages[] = sprintf(
                        LITCAL_MESSAGES::__( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$this->LITSETTINGS->LOCALE),
                        LITCAL_MESSAGES::_G( $row["GRADE"],$this->LITSETTINGS->LOCALE),
                        $row["NAME_" . $this->LITSETTINGS->LOCALE],
                        $this->LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                            ( $this->LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                                trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                            ),
                        2002,
                        '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_it.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                        $this->LITSETTINGS->YEAR
                    );

                    //If a fixed date Memorial falls within the Lenten season, it is reduced in rank to a Commemoration.
                    if ($currentFeastDate > $this->LitCal["AshWednesday"]->date && $currentFeastDate < $this->LitCal["HolyThurs"]->date) {
                        $this->LitCal[$row["TAG"]]->grade = LitGrade::COMMEMORATION;
                        $this->Messages[] = sprintf(
                            LITCAL_MESSAGES::__( "The %s '%s', added on %s since the year %d (%s), falls within the Lenten season in the year %d, rank reduced to Commemoration.",$this->LITSETTINGS->LOCALE),
                            LITCAL_MESSAGES::_G( $row["GRADE"],$this->LITSETTINGS->LOCALE),
                            $row["NAME_" . $this->LITSETTINGS->LOCALE],
                            $this->LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                                ( $this->LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                                    trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                                ),
                            2002,
                            '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_it.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                            $this->LITSETTINGS->YEAR
                        );
                    }

                    //We can now add, for logical reasons, Feasts and Memorials to the $this->FEASTS_MEMORIALS array
                    if ($this->LitCal[$row["TAG"]]->grade > LitGrade::MEMORIAL_OPT) {
                        $this->FEASTS_MEMORIALS[$row["TAG"]]      = $currentFeastDate;

                        //Also, while we're add it, let's remove the weekdays of Epiphany that get overriden by memorials
                        $key = array_search($this->LitCal[$row["TAG"]]->date, $this->WEEKDAYS_EPIPHANY);
                        if ( false !== $key ) {
                            $this->Messages[] = sprintf(
                                LITCAL_MESSAGES::__( "In the year %d '%s' is superseded by the %s '%s', added on %s since the year %d (%s).",$this->LITSETTINGS->LOCALE),
                                $this->LITSETTINGS->YEAR,
                                $this->LitCal[$key]->name,
                                LITCAL_MESSAGES::_G( $this->LitCal[$row["TAG"]]->grade,$this->LITSETTINGS->LOCALE),
                                $this->LitCal[$row["TAG"]]->name,
                                $this->LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                                        trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                                    ),
                                2002,
                                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_it.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>'
                            );
                            unset($this->LitCal[$key]);
                        }
                        //IMMACULATEHEART: in years when the memorial of the Immaculate Heart of Mary coincides with another obligatory memorial,
                        //as happened in 2014 [28 June, Saint Irenaeus] and 2015 [13 June, Saint Anthony of Padua], both must be considered optional for that year
                        //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
                        if (isset($this->LitCal["ImmaculateHeart"]) && $currentFeastDate == $this->LitCal["ImmaculateHeart"]->date) {
                            $this->LitCal["ImmaculateHeart"]->grade = LitGrade::MEMORIAL_OPT;
                            $this->LitCal[$row["TAG"]]->grade = LitGrade::MEMORIAL_OPT;
                            //unset($this->LitCal[$key]); $this->FEASTS_MEMORIALS ImmaculateHeart
                            $this->Messages[] = sprintf(
                                LITCAL_MESSAGES::__( "The Memorial '%s' coincides with another Memorial '%s' in the year %d. They are both reduced in rank to optional memorials (%s).",$this->LITSETTINGS->LOCALE),
                                $this->LitCal["ImmaculateHeart"]->name,
                                $this->LitCal[$row["TAG"]]->name,
                                $this->LITSETTINGS->YEAR,
                                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>'
                            );
                        }
                    }
                } else {
                    $coincidingFestivity_grade = '';
                    if((int)$currentFeastDate->format('N') === 7 && $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)]->grade < LitGrade::SOLEMNITY ){
                        //it's a Sunday
                        $coincidingFestivity = $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)];
                        $coincidingFestivity_grade = $this->LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
                    } else if (in_array($currentFeastDate, $this->SOLEMNITIES)){
                        //it's a Feast of the Lord or a Solemnity
                        $coincidingFestivity = $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)];
                        $coincidingFestivity_grade = ($coincidingFestivity->grade > LitGrade::SOLEMNITY ? '<i>' . LITCAL_MESSAGES::_G( $coincidingFestivity->grade,$this->LITSETTINGS->LOCALE,false) . '</i>' : LITCAL_MESSAGES::_G( $coincidingFestivity->grade,$this->LITSETTINGS->LOCALE,false));
                    } else if(in_array($currentFeastDate, $this->FEASTS_MEMORIALS)){
                        $coincidingFestivity = $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)];
                        $coincidingFestivity_grade = LITCAL_MESSAGES::_G( $coincidingFestivity->grade,$this->LITSETTINGS->LOCALE,false);
                    }

                    $this->Messages[] = sprintf(
                        LITCAL_MESSAGES::__( "The %s '%s', added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s) and usually celebrated on %s, is suppressed by the %s '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                        LITCAL_MESSAGES::_G( $row["GRADE"],$this->LITSETTINGS->LOCALE,false),
                        $row["NAME_" . $this->LITSETTINGS->LOCALE],
                        '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_it.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                        $this->LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                            ( $this->LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                                trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                            ),
                        $coincidingFestivity_grade,
                        $coincidingFestivity->name,
                        $this->LITSETTINGS->YEAR
                    );
                }
            }
        }

    }

    private function applyMemorialsTertiaEditioTypicaEmendata2008() : void {

        //Saint Pio of Pietrelcina "Padre Pio" was canonized on June 16 2002, so did not make it for the Calendar of the 2002 editio typica III
        //The memorial was added in the 2008 editio typica III emendata as an obligatory memorial
        $StPioPietrelcina_tag = array("LA" => "S. Pii de Pietrelcina, presbyteri", "IT" => "San Pio da Pietrelcina, presbitero", "EN" => "Saint Pius of Pietrelcina, Priest");
        $StPioPietrelcina_date = DateTime::createFromFormat('!j-n-Y', '23-9-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if(!in_array($StPioPietrelcina_date,$this->SOLEMNITIES) && !in_array($StPioPietrelcina_date,$this->FEASTS_MEMORIALS)){
            $this->LitCal["StPioPietrelcina"] = new Festivity($StPioPietrelcina_tag[$this->LITSETTINGS->LOCALE], $StPioPietrelcina_date, "white", "fixed", LitGrade::MEMORIAL, "Pastors:For One Pastor,Holy Men and Women:For Religious");
            $this->FEASTS_MEMORIALS["StPioPietrelcina"]      = $StPioPietrelcina_date;
            /**
             * TRANSLATORS:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Current year
             */
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$this->LITSETTINGS->LOCALE),
                LITCAL_MESSAGES::_G( $this->LitCal["StPioPietrelcina"]->grade,$this->LITSETTINGS->LOCALE),
                $this->LitCal["StPioPietrelcina"]->name,
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $this->LitCal["StPioPietrelcina"]->date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$this->LitCal["StPioPietrelcina"]->date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $this->LitCal["StPioPietrelcina"]->date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $this->LitCal["StPioPietrelcina"]->date->format('U'))))
                    ),
                2008,
                'Missale Romanum, ed. Typica Tertia Emendata 2008',
                $this->LITSETTINGS->YEAR
            );
        }
        else{
            if(in_array($StPioPietrelcina_date,$this->SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($StPioPietrelcina_date,$this->SOLEMNITIES);
            }
            else if(in_array($StPioPietrelcina_date,$this->FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($StPioPietrelcina_date,$this->FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $this->LitCal[$coincidingFestivityKey];
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                $StPioPietrelcina_tag[$this->LITSETTINGS->LOCALE],
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $StPioPietrelcina_date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$StPioPietrelcina_date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $StPioPietrelcina_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $StPioPietrelcina_date->format('U'))))
                    ),
                2008,
                'Missale Romanum, ed. Typica Tertia Emendata 2008',
                $coincidingFestivity->name,
                $this->LITSETTINGS->YEAR
            );
        }

    }

    private function applyMemorialDecree2018() : void {
        //With the Decree of the Congregation of Divine Worship on March 24, 2018,
        //the Obligatory Memorial of the Blessed Virgin Mary, Mother of the Church was added on the Monday after Pentecost
        //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20180211_decreto-mater-ecclesiae_la.html
        $MaryMotherChurch_tag = ["LA" => "Beatæ Mariæ Virginis, Ecclesiæ Matris", "IT" => "Beata Vergine Maria, Madre della Chiesa", "EN" => "Blessed Virgin Mary, Mother of the Church"];
        $MaryMotherChurch_date = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 7 + 1) . 'D'));
        //The Memorial is superseded by Solemnities and Feasts, but not by Memorials of Saints
        if(!in_array($MaryMotherChurch_date,$this->SOLEMNITIES) && !in_array($MaryMotherChurch_date,$this->FEASTS_MEMORIALS)){
            $this->LitCal["MaryMotherChurch"] = new Festivity($MaryMotherChurch_tag[$this->LITSETTINGS->LOCALE], $MaryMotherChurch_date, "white", "mobile", LitGrade::MEMORIAL, "Proper");
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$this->LITSETTINGS->LOCALE),
                LITCAL_MESSAGES::_G( $this->LitCal["MaryMotherChurch"]->grade,$this->LITSETTINGS->LOCALE),
                $this->LitCal["MaryMotherChurch"]->name,
                LITCAL_MESSAGES::__( 'the Monday after Pentecost',$this->LITSETTINGS->LOCALE),
                2018,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20180211_decreto-mater-ecclesiae_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $this->LITSETTINGS->YEAR
            );
        }
        else if (in_array($MaryMotherChurch_date,$this->FEASTS_MEMORIALS) ){
            //we have to find out what it coincides with. If it's a feast, it is superseded by the feast. If a memorial, it will suppress the memorial
            $coincidingFestivityKey = array_search($MaryMotherChurch_date,$this->FEASTS_MEMORIALS);
            $coincidingFestivity = $this->LitCal[$coincidingFestivityKey];
    
            if($coincidingFestivity->grade <= LitGrade::MEMORIAL){
                $this->LitCal["MaryMotherChurch"] = new Festivity($MaryMotherChurch_tag[$this->LITSETTINGS->LOCALE], $MaryMotherChurch_date, "white", "mobile", LitGrade::MEMORIAL, "Proper");
                $this->Messages[] = sprintf(
                    LITCAL_MESSAGES::__( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$this->LITSETTINGS->LOCALE),
                    LITCAL_MESSAGES::_G( $this->LitCal["MaryMotherChurch"]->grade,$this->LITSETTINGS->LOCALE),
                    $this->LitCal["MaryMotherChurch"]->name,
                    LITCAL_MESSAGES::__( 'the Monday after Pentecost',$this->LITSETTINGS->LOCALE),
                    2018,
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20180211_decreto-mater-ecclesiae_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                    $this->LITSETTINGS->YEAR
                );
                $this->Messages[] = sprintf(
                    LITCAL_MESSAGES::__( "The %s '%s' has been suppressed by the Memorial '%s', added on %s since the year %d (%s).",$this->LITSETTINGS->LOCALE),
                    LITCAL_MESSAGES::_G( $this->LitCal[$coincidingFestivityKey]->grade,$this->LITSETTINGS->LOCALE,false),
                    '<i>' . $this->LitCal[$coincidingFestivityKey]->name . '</i>',
                    $this->LitCal["MaryMotherChurch"]->name,
                    LITCAL_MESSAGES::__( 'the Monday after Pentecost',$this->LITSETTINGS->LOCALE),
                    2018,
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20180211_decreto-mater-ecclesiae_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>'
                );
                unset($this->LitCal[$coincidingFestivityKey]);
            }else{
                $this->Messages[] = sprintf(
                    LITCAL_MESSAGES::__( "The Memorial '%s', added on %s since the year %d (%s), is however superseded by a Solemnity or a Feast '%s' in the year %d.", $this->LITSETTINGS->LOCALE),
                    $MaryMotherChurch_tag[$this->LITSETTINGS->LOCALE],
                    LITCAL_MESSAGES::__( 'the Monday after Pentecost',$this->LITSETTINGS->LOCALE),
                    2018,
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20180211_decreto-mater-ecclesiae_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                    $coincidingFestivity->name,
                    $this->LITSETTINGS->YEAR
                );
            }
        }
        else if(in_array($MaryMotherChurch_date,$this->SOLEMNITIES)){
            $coincidingFestivityKey = array_search($MaryMotherChurch_date,$this->SOLEMNITIES);
            $coincidingFestivity = $this->LitCal[$coincidingFestivityKey];
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The Memorial '%s', added on %s since the year %d (%s), is however superseded by a Solemnity or a Feast '%s' in the year %d.", $this->LITSETTINGS->LOCALE),
                $this->LitCal["MaryMotherChurch"]->name,
                LITCAL_MESSAGES::__( 'the Monday after Pentecost',$this->LITSETTINGS->LOCALE),
                2018,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20180211_decreto-mater-ecclesiae_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $coincidingFestivity->name,
                $this->LITSETTINGS->YEAR
            );
        }

    }

    //With the Decree of the Congregation for Divine Worship on January 26, 2021,
    //the Memorial of Saint Martha on July 29th will now be of Mary, Martha and Lazarus
    //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20210126_decreto-santi_la.html
    private function applyMemorialDecree2021() : void {
        if(array_key_exists("StMartha",$this->LitCal)){
            $StMartha_tag = ["LA" => "Sanctorum Marthæ, Mariæ et Lazari", "IT" => "Santi Marta, Maria e Lazzaro", "EN" => "Saints Martha, Mary and Lazarus"];
            $this->LitCal["StMartha"]->name = $StMartha_tag[$this->LITSETTINGS->LOCALE];
        }

    }

    private function calculateOptionalMemorials() : void {
        $result = $this->mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE GRADE = " . LitGrade::MEMORIAL_OPT);
        if ( $result ) {
            while ($row = mysqli_fetch_assoc($result)) {

                //If it doesn't occur on a Sunday or a Solemnity or a Feast of the Lord or a Feast or an obligatory memorial, then go ahead and create the optional memorial
                $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row["DAY"] . '-' . $row["MONTH"] . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                if ((int)$currentFeastDate->format('N') !== 7 && !in_array($currentFeastDate, $this->SOLEMNITIES) && !in_array($currentFeastDate, $this->FEASTS_MEMORIALS)) {
                    $this->LitCal[$row["TAG"]] = new Festivity($row["NAME_" . $this->LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);

                    //If a fixed date optional memorial falls between 17 Dec. to 24 Dec., the Octave of Christmas or weekdays of the Lenten season,
                    //it is reduced in rank to a Commemoration (only the collect can be used
                    if (in_array($currentFeastDate, $this->WEEKDAYS_ADVENT_CHRISTMAS_LENT)) {
                        $this->LitCal[$row["TAG"]]->grade = LitGrade::COMMEMORATION;
                        $this->Messages[] = sprintf(
                            LITCAL_MESSAGES::__( "The optional memorial '%s' either falls between 17 Dec. and 24 Dec., or during the Octave of Christmas, or on the weekdays of the Lenten season in the year %d, rank reduced to Commemoration.",$this->LITSETTINGS->LOCALE),
                            $row["NAME_" . $this->LITSETTINGS->LOCALE],
                            $this->LITSETTINGS->YEAR
                        );
                    }
                } else {
                    $coincidingFestivity_grade = '';
                    if((int)$currentFeastDate->format('N') === 7 && $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)]->grade < LitGrade::SOLEMNITY ){
                        //it's a Sunday
                        $coincidingFestivity = $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)];
                        $coincidingFestivity_grade = $this->LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
                    } else if (in_array($currentFeastDate, $this->SOLEMNITIES)){
                        //it's a Feast of the Lord or a Solemnity
                        $coincidingFestivity = $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)];
                        $coincidingFestivity_grade = ($coincidingFestivity->grade > LitGrade::SOLEMNITY ? '<i>' . LITCAL_MESSAGES::_G( $coincidingFestivity->grade,$this->LITSETTINGS->LOCALE,false) . '</i>' : LITCAL_MESSAGES::_G( $coincidingFestivity->grade,$this->LITSETTINGS->LOCALE,false));
                    } else if(in_array($currentFeastDate, $this->FEASTS_MEMORIALS)){
                        $coincidingFestivity = $this->LitCal[array_search($currentFeastDate,$this->FEASTS_MEMORIALS)];
                        $coincidingFestivity_grade = LITCAL_MESSAGES::_G( $coincidingFestivity->grade,$this->LITSETTINGS->LOCALE,false);
                    }

                    $this->Messages[] = sprintf(
                        LITCAL_MESSAGES::__( "The %s '%s', usually celebrated on %s, is suppressed by the %s '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                        LITCAL_MESSAGES::_G( $row["GRADE"],$this->LITSETTINGS->LOCALE,false),
                        $row["NAME_" . $this->LITSETTINGS->LOCALE],
                        $this->LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                            ( $this->LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                                trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                            ),
                        $coincidingFestivity_grade,
                        $coincidingFestivity->name,
                        $this->LITSETTINGS->YEAR
                    );
                }
            }
        }

    }

    /*if we are dealing with a calendar from the year 2002 onwards we need to add the optional memorials from the Tertia Editio Typica:
        23 aprilis:  S. Adalberti, episcopi et martyris
        28 aprilis:  S. Ludovici Mariæ Grignion de Montfort, presbyteri
        2 augusti:  S. Petri Iuliani Eymard, presbyteri
        9 septembris:  S. Petri Claver, presbyteri
        28 septembris:  Ss. Laurentii Ruiz et sociorum, martyrum

        11 new celebrations (I believe all considered optional memorials?)
        3 ianuarii:  SS.mi Nominis Iesu
        8 februarii:  S. Iosephinæ Bakhita, virginis
        13 maii:  Beatæ Mariæ Virginis de Fatima
        21 maii:  Ss. Christophori Magallanes, presbyteri, et sociorum, martyrum
        22 maii:  S. Ritæ de Cascia, religiosæ
        9 iulii:  Ss. Augustini Zhao Rong, presbyteri et sociorum, martyrum
        20 iulii:  S. Apollinaris, episcopi et martyris
        24 iulii:  S. Sarbelii Makhluf, presbyteri
        9 augusti:  S. Teresiæ Benedictæ a Cruce, virginis et martyris
        12 septembris:  SS.mi Nominis Mariæ
        25 novembris:  S. Catharinæ Alexandrinæ, virginis et martyris
        source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_it.html
        */
    private function applyOptionalMemorialsTertiaEditioTypica2002() : void {

        $result = $this->mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis_2002 WHERE GRADE = " . LitGrade::MEMORIAL_OPT);
        if ( $result ) {
            while ($row = mysqli_fetch_assoc($result)) {

                //If it doesn't occur on a Sunday or a Solemnity or a Feast of the Lord or a Feast or an obligatory memorial, then go ahead and create the optional memorial
                $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row["DAY"] . '-' . $row["MONTH"] . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                if ((int)$currentFeastDate->format('N') !== 7 && !in_array($currentFeastDate, $this->SOLEMNITIES) && !in_array($currentFeastDate, $this->FEASTS_MEMORIALS)) {
                    $this->LitCal[$row["TAG"]] = new Festivity($row["NAME_" . $this->LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);
                    /**
                     * TRANSLATORS:
                     * 1. Grade or rank of the festivity
                     * 2. Name of the festivity
                     * 3. Day of the festivity
                     * 4. Year from which the festivity has been added
                     * 5. Source of the information
                     * 6. Current year
                     */
                    $this->Messages[] = sprintf(
                        LITCAL_MESSAGES::__( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$this->LITSETTINGS->LOCALE),
                        LITCAL_MESSAGES::_G( $row["GRADE"],$this->LITSETTINGS->LOCALE),
                        $row["NAME_" . $this->LITSETTINGS->LOCALE],
                        $this->LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                            ( $this->LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                                trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                            ),
                        2002,
                        '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                        $this->LITSETTINGS->YEAR
                    );

                    //If a fixed date optional memorial falls between 17 Dec. to 24 Dec., the Octave of Christmas or weekdays of the Lenten season,
                    //it is reduced in rank to a Commemoration (only the collect can be used
                    if (in_array($currentFeastDate, $this->WEEKDAYS_ADVENT_CHRISTMAS_LENT)) {
                        $this->LitCal[$row["TAG"]]->grade = LitGrade::COMMEMORATION;
                        $this->Messages[] = sprintf(
                            LITCAL_MESSAGES::__( "The optional memorial '%s', added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s), either falls between 17 Dec. and 24 Dec., during the Octave of Christmas, or on a weekday of the Lenten season in the year %d, rank reduced to Commemoration.",$this->LITSETTINGS->LOCALE),
                            $this->LitCal[$row["TAG"]]->name,
                            '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                            $this->LITSETTINGS->YEAR
                        );
                    }
                } else {
                    $coincidingFestivity_grade = '';
                    if((int)$currentFeastDate->format('N') === 7 && $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)]->grade < LitGrade::SOLEMNITY ){
                        //it's a Sunday
                        $coincidingFestivity = $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)];
                        $coincidingFestivity_grade = $this->LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
                    } else if (in_array($currentFeastDate, $this->SOLEMNITIES)){
                        //it's a Feast of the Lord or a Solemnity
                        $coincidingFestivity = $this->LitCal[array_search($currentFeastDate,$this->SOLEMNITIES)];
                        $coincidingFestivity_grade = ($coincidingFestivity->grade > LitGrade::SOLEMNITY ? '<i>' . LITCAL_MESSAGES::_G( $coincidingFestivity->grade,$this->LITSETTINGS->LOCALE,false) . '</i>' : LITCAL_MESSAGES::_G( $coincidingFestivity->grade,$this->LITSETTINGS->LOCALE,false));
                    } else if(in_array($currentFeastDate, $this->FEASTS_MEMORIALS)){
                        $coincidingFestivity = $this->LitCal[array_search($currentFeastDate,$this->FEASTS_MEMORIALS)];
                        $coincidingFestivity_grade = LITCAL_MESSAGES::_G( $coincidingFestivity->grade,$this->LITSETTINGS->LOCALE,false);
                    }
                    $this->Messages[] = sprintf(
                        LITCAL_MESSAGES::__( "The %s '%s', added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s) and usually celebrated on %s, is suppressed by the %s '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                        LITCAL_MESSAGES::_G( $row["GRADE"],$this->LITSETTINGS->LOCALE,false),
                        $row["NAME_" . $this->LITSETTINGS->LOCALE],
                        '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                        $this->LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                            ( $this->LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                                trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                            ),
                        $coincidingFestivity_grade,
                        $coincidingFestivity->name,
                        $this->LITSETTINGS->YEAR
                    );
                }
            }
        }

        //Saint Jane Frances de Chantal was moved from December 12 to August 12,
        //probably to allow local bishop's conferences to insert Our Lady of Guadalupe as an optional memorial on December 12
        //seeing that with the decree of March 25th 1999 of the Congregation of Divine Worship
        //Our Lady of Guadalupe was granted as a Feast day for all dioceses and territories of the Americas
        //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_lt.html

        $StJaneFrancesNewDate = DateTime::createFromFormat('!j-n-Y', '12-8-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if ( (int)$StJaneFrancesNewDate->format('N') !== 7 && !in_array($StJaneFrancesNewDate, $this->SOLEMNITIES) && !in_array($StJaneFrancesNewDate, $this->FEASTS_MEMORIALS) ) {
            if( array_key_exists("StJaneFrancesDeChantal", $this->LitCal) ){
                $this->LitCal["StJaneFrancesDeChantal"]->date = $StJaneFrancesNewDate;
                $this->Messages[] = sprintf(
                    LITCAL_MESSAGES::__( "The optional memorial '%s' has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%s), applicable to the year %d.",$this->LITSETTINGS->LOCALE),
                    $this->LitCal["StJaneFrancesDeChantal"]->name,
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                    $this->LITSETTINGS->YEAR
                );
            } else {
                //perhaps it wasn't created on December 12th because it was superseded by a Sunday, Solemnity or Feast
                //but seeing that there is no problem for August 12th, let's go ahead and try creating it again
                $result = $this->mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'StJaneFrancesDeChantal'");
                if ( $result ) {
                    $row = mysqli_fetch_assoc($result);
                    $this->LitCal["StJaneFrancesDeChantal"] = new Festivity( $row["NAME_" . $this->LITSETTINGS->LOCALE], $StJaneFrancesNewDate, $row["COLOR"], 'fixed', $row["GRADE"], $row["COMMON"] );
                    $this->Messages[] = sprintf(
                        LITCAL_MESSAGES::__( "The optional memorial '%s', which would have been superseded this year by a Sunday or Solemnity were it on Dec. 12, has however been transferred to Aug. 12 since the year 2002 (%s), applicable to the year %d.",$this->LITSETTINGS->LOCALE),
                        $this->LitCal["StJaneFrancesDeChantal"]->name,
                        '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                        $this->LITSETTINGS->YEAR
                    );
                }
            }
        } else {
            if(in_array($StJaneFrancesNewDate,$this->SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($StJaneFrancesNewDate,$this->SOLEMNITIES);
            }
            else if(in_array($StJaneFrancesNewDate,$this->FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($StJaneFrancesNewDate,$this->FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $this->LitCal[$coincidingFestivityKey];
            //we can't move it, but we still need to remove it from Dec 12th if it's there!!!
            if( array_key_exists("StJaneFrancesDeChantal", $this->LitCal) ){
                $this->Messages[] = sprintf(
                    LITCAL_MESSAGES::__( 'The optional memorial \'%1$s\' has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d. However, it is superseded by a Sunday, a Solemnity, or a Feast \'%4$s\' in the year %3$d.',$this->LITSETTINGS->LOCALE),
                    $this->LitCal["StJaneFrancesDeChantal"]->name,
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                    $this->LITSETTINGS->YEAR,
                    $coincidingFestivity->name
                );
                unset($this->LitCal["StJaneFrancesDeChantal"]);
            } else {
                //in order to give any kind of feedback message about what is going on, we will need to at least re-acquire the Name of this festivity,
                //which has already been removed from our LitCal array
                $result = $this->mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'StJaneFrancesDeChantal'");
                if ( $result ) {
                    $row = mysqli_fetch_assoc($result);
                    $this->Messages[] = sprintf(
                        LITCAL_MESSAGES::__( 'The optional memorial \'%1$s\' has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d. However, it is superseded by a Sunday, a Solemnity, or a Feast \'%4$s\' in the year %3$d.',$this->LITSETTINGS->LOCALE),
                        $row["NAME_" . $this->LITSETTINGS->LOCALE],
                        '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                        $this->LITSETTINGS->YEAR,
                        $coincidingFestivity->name
                    );
                }
            }
        }

    }

    private function applyOptionalMemorialsTertiaEditioTypicaEmendata2008() : void {

        //Saint Juan Diego was canonized in 2002, so did not make it to the Tertia Editio Typica 2002
        //The optional memorial was added in the Tertia Editio Typica emendata in 2008,
        //together with the optional memorial of Our Lady of Guadalupe
        $Guadalupe_tag = ["LA" => "Beatæ Mariæ Virginis Guadalupensis", "EN" => "Our Lady of Guadalupe", "IT" => "Beata Vergine Maria di Guadalupe"];
        $Guadalupe_date = DateTime::createFromFormat('!j-n-Y', '12-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));

        if ( (int)$Guadalupe_date->format('N') !== 7 && !in_array($Guadalupe_date, $this->SOLEMNITIES) && !in_array($Guadalupe_date, $this->FEASTS_MEMORIALS) ) {
            $this->LitCal["LadyGuadalupe"] = new Festivity( $Guadalupe_tag[$this->LITSETTINGS->LOCALE], $Guadalupe_date, 'white', 'fixed', LitGrade::MEMORIAL_OPT, "Blessed Virgin Mary" );
            /**
             * TRANSLATORS:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Current year
             */
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$this->LITSETTINGS->LOCALE),
                LITCAL_MESSAGES::_G( $this->LitCal["LadyGuadalupe"]->name,$this->LITSETTINGS->LOCALE),
                $this->LitCal["LadyGuadalupe"]->name,
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $Guadalupe_date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$Guadalupe_date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $Guadalupe_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $Guadalupe_date->format('U'))))
                    ),
                2002,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $this->LITSETTINGS->YEAR
            );
        } else {
            if(in_array($Guadalupe_date,$this->SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($Guadalupe_date,$this->SOLEMNITIES);
            }
            else if(in_array($Guadalupe_date,$this->FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($Guadalupe_date,$this->FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $this->LitCal[$coincidingFestivityKey];
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                $Guadalupe_tag[$this->LITSETTINGS->LOCALE],
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $Guadalupe_date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$Guadalupe_date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $Guadalupe_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $Guadalupe_date->format('U'))))
                    ),
                2002,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $coincidingFestivity->name,
                $this->LITSETTINGS->YEAR
            );
        }

        $JuanDiego_tag = ["LA" => "Sancti Ioannis Didaci Cuauhtlatoatzin", "EN" => "Saint Juan Diego Cuauhtlatoatzin", "IT" => "San Juan Diego Cuauhtlatouatzin"];
        $JuanDiego_date = DateTime::createFromFormat('!j-n-Y', '9-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if ( (int)$JuanDiego_date->format('N') !== 7 && !in_array($JuanDiego_date, $this->SOLEMNITIES) && !in_array($JuanDiego_date, $this->FEASTS_MEMORIALS) ) {
            $this->LitCal["JuanDiego"] = new Festivity( $JuanDiego_tag[$this->LITSETTINGS->LOCALE], $JuanDiego_date, 'white', 'fixed', LitGrade::MEMORIAL_OPT, "Holy Men and Women:For One Saint" );
            /**
             * TRANSLATORS:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Current year
             */
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$this->LITSETTINGS->LOCALE),
                LITCAL_MESSAGES::_G( $this->LitCal["JuanDiego"]->grade,$this->LITSETTINGS->LOCALE),
                $this->LitCal["JuanDiego"]->name,
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $JuanDiego_date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$JuanDiego_date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $JuanDiego_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $JuanDiego_date->format('U'))))
                    ),
                2002,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $this->LITSETTINGS->YEAR
            );
        } else {
            if(in_array($JuanDiego_date,$this->SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($JuanDiego_date,$this->SOLEMNITIES);
            }
            else if(in_array($JuanDiego_date,$this->FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($JuanDiego_date,$this->FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $this->LitCal[$coincidingFestivityKey];
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                $JuanDiego_tag[$this->LITSETTINGS->LOCALE],
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $JuanDiego_date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$JuanDiego_date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $JuanDiego_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $JuanDiego_date->format('U'))))
                    ),
                2002,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $coincidingFestivity->name,
                $this->LITSETTINGS->YEAR
            );
        }

    }

    //The Conversion of St. Paul falls on a Sunday in the year 2009.
    //However, considering that it is the Year of Saint Paul,
    //with decree of Jan 25 2008 the Congregation for Divine Worship gave faculty to the single churches
    //to celebrate the Conversion of St. Paul anyways. So let's re-insert it as an optional memorial?
    //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20080125_san-paolo_la.html
    private function applyOptionalMemorialDecree2009() : void {

        if(!array_key_exists("ConversionStPaul",$this->LitCal)){
            $result = $this->mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'ConversionStPaul'");
            if ( $result ) {
                $row = mysqli_fetch_assoc($result);
                $this->LitCal["ConversionStPaul"] = new Festivity($row["NAME_". $this->LITSETTINGS->LOCALE], DateTime::createFromFormat('!j-n-Y', '25-1-2009', new DateTimeZone('UTC')), "white", "fixed", LitGrade::MEMORIAL_OPT, "Proper" );
                $this->Messages[] = sprintf(
                    LITCAL_MESSAGES::__( 'The Feast \'%s\' would have been suppressed this year (2009) since it falls on a Sunday, however being the Year of the Apostle Paul, as per the %s it has been reinstated so that local churches can optionally celebrate the memorial.',$this->LITSETTINGS->LOCALE),
                    '<i>' . $row["NAME_" . $this->LITSETTINGS->LOCALE] . '</i>',
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20080125_san-paolo_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>'
                );
            }
        }

    }

    //After the canonization of Pope Saint John XXIII and Pope Saint John Paul II
    //with decree of May 29 2014 the Congregation for Divine Worship
    //inserted the optional memorials for each in the Universal Calendar
    //on October 11 and October 22 respectively
    //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20140529_decreto-calendario-generale-gxxiii-gpii_la.html
    private function applyOptionalMemorialDecree2014() : void {
        $StJohnXXIII_tag = array("LA" => "S. Ioannis XXIII, papæ", "IT" => "San Giovanni XXIII, papa", "EN" => "Saint John XXIII, pope");
        $StJohnXXIII_date = DateTime::createFromFormat('!j-n-Y', '11-10-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if(!in_array($StJohnXXIII_date,$this->SOLEMNITIES) && !in_array($StJohnXXIII_date,$this->FEASTS_MEMORIALS)){
            $this->LitCal["StJohnXXIII"] = new Festivity($StJohnXXIII_tag[$this->LITSETTINGS->LOCALE], $StJohnXXIII_date, "white", "fixed", LitGrade::MEMORIAL_OPT, "Pastors:For a Pope");
            /**
             * TRANSLATORS:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Current year
             */
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$this->LITSETTINGS->LOCALE),
                LITCAL_MESSAGES::_G( $this->LitCal["StJohnXXIII"]->grade,$this->LITSETTINGS->LOCALE),
                $this->LitCal["StJohnXXIII"]->name,
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $this->LitCal["StJohnXXIII"]->date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$this->LitCal["StJohnXXIII"]->date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $this->LitCal["StJohnXXIII"]->date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $this->LitCal["StJohnXXIII"]->date->format('U'))))
                    ),
                2014,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20140529_decreto-calendario-generale-gxxiii-gpii_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $this->LITSETTINGS->YEAR
            );
        }
        else{
            if(in_array($StJohnXXIII_date,$this->SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($StJohnXXIII_date,$this->SOLEMNITIES);
            }
            else if(in_array($StJohnXXIII_date,$this->FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($StJohnXXIII_date,$this->FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $this->LitCal[$coincidingFestivityKey];
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                $StJohnXXIII_tag[$this->LITSETTINGS->LOCALE],
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $StJohnXXIII_date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$StJohnXXIII_date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $StJohnXXIII_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $StJohnXXIII_date->format('U'))))
                    ),
                2014,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20140529_decreto-calendario-generale-gxxiii-gpii_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $coincidingFestivity->name,
                $this->LITSETTINGS->YEAR
            );
        }

        $StJohnPaulII_tag = array("LA" => "S. Ioannis Pauli II, papæ", "IT" => "San Giovanni Paolo II, papa", "EN" => "Saint John Paul II, pope");
        $StJohnPaulII_date = DateTime::createFromFormat('!j-n-Y', '22-10-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if(!in_array($StJohnPaulII_date,$this->SOLEMNITIES) && !in_array($StJohnPaulII_date,$this->FEASTS_MEMORIALS)){
            $this->LitCal["StJohnPaulII"] = new Festivity($StJohnPaulII_tag[$this->LITSETTINGS->LOCALE], $StJohnPaulII_date, "white", "fixed", LitGrade::MEMORIAL_OPT, "Pastors:For a Pope");
            /**
             * TRANSLATORS:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Current year
             */
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$this->LITSETTINGS->LOCALE),
                LITCAL_MESSAGES::_G( $this->LitCal["StJohnPaulII"]->grade,$this->LITSETTINGS->LOCALE),
                $this->LitCal["StJohnPaulII"]->name,
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $this->LitCal["StJohnPaulII"]->date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$this->LitCal["StJohnPaulII"]->date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $this->LitCal["StJohnPaulII"]->date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $this->LitCal["StJohnPaulII"]->date->format('U'))))
                    ),
                2014,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20140529_decreto-calendario-generale-gxxiii-gpii_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $this->LITSETTINGS->YEAR
            );
        }
        else{
            if(in_array($StJohnPaulII_date,$this->SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($StJohnPaulII_date,$this->SOLEMNITIES);
            }
            else if(in_array($StJohnPaulII_date,$this->FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($StJohnPaulII_date,$this->FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $this->LitCal[$coincidingFestivityKey];
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                $StJohnPaulII_tag[$this->LITSETTINGS->LOCALE],
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $StJohnPaulII_date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$StJohnPaulII_date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $StJohnPaulII_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $StJohnPaulII_date->format('U'))))
                    ),
                2014,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20140529_decreto-calendario-generale-gxxiii-gpii_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $coincidingFestivity->name,
                $this->LITSETTINGS->YEAR
            );
        }

    }

    private function applyOptionalMemorialDecree2019() : void {
        //With the Decree of the Congregation of Divine Worship of Oct 7, 2019,
        //the optional memorial of the Blessed Virgin Mary of Loreto was added on Dec 10
        //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20191007_decreto-celebrazione-verginediloreto_la.html
        $LadyLoreto_tag = ["LA" => "Beatæ Mariæ Virginis de Loreto", "IT" => "Beata Maria Vergine di Loreto", "EN" => "Blessed Virgin Mary of Loreto"];
        $LadyLoreto_date = DateTime::createFromFormat('!j-n-Y', '10-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if(!in_array($LadyLoreto_date,$this->SOLEMNITIES) && !in_array($LadyLoreto_date,$this->FEASTS_MEMORIALS) ){
            $this->LitCal["LadyLoreto"] = new Festivity($LadyLoreto_tag[$this->LITSETTINGS->LOCALE], $LadyLoreto_date, "white", "fixed", LitGrade::MEMORIAL_OPT, "Blessed Virgin Mary");
            /**
             * TRANSLATORS:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Current year
             */
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$this->LITSETTINGS->LOCALE),
                LITCAL_MESSAGES::_G( $this->LitCal["LadyLoreto"]->grade,$this->LITSETTINGS->LOCALE),
                $this->LitCal["LadyLoreto"]->name,
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $this->LitCal["LadyLoreto"]->date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$this->LitCal["LadyLoreto"]->date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $this->LitCal["LadyLoreto"]->date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $this->LitCal["LadyLoreto"]->date->format('U'))))
                    ),
                2019,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20191007_decreto-celebrazione-verginediloreto_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $this->LITSETTINGS->YEAR
            );
        }
        else{
            if(in_array($LadyLoreto_date,$this->SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($LadyLoreto_date,$this->SOLEMNITIES);
            }
            else if(in_array($LadyLoreto_date,$this->FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($LadyLoreto_date,$this->FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $this->LitCal[$coincidingFestivityKey];
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                $LadyLoreto_tag[$this->LITSETTINGS->LOCALE],
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $LadyLoreto_date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$LadyLoreto_date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $LadyLoreto_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $LadyLoreto_date->format('U'))))
                    ),
                2019,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20191007_decreto-celebrazione-verginediloreto_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $coincidingFestivity->name,
                $this->LITSETTINGS->YEAR
            );
        }

        //With the Decree of the Congregation of Divine Worship of January 25 2019,
        //the optional memorial of Saint Paul VI, Pope was added on May 29
        //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20190125_decreto-celebrazione-paolovi_la.html
        $PaulVI_tag = ["LA" => "Sancti Pauli VI, Papæ", "IT" => "San Paolo VI, Papa", "EN" => "Saint Paul VI, Pope"];
        $PaulVI_date = DateTime::createFromFormat('!j-n-Y', '29-5-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if(!in_array($PaulVI_date,$this->SOLEMNITIES) && !in_array($PaulVI_date,$this->FEASTS_MEMORIALS) ){
            $this->LitCal["StPaulVI"] = new Festivity($PaulVI_tag[$this->LITSETTINGS->LOCALE], $PaulVI_date, "white", "fixed", LitGrade::MEMORIAL_OPT, "Pastors:For a Pope");
            /**
             * TRANSLATORS:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Current year
             */
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$this->LITSETTINGS->LOCALE),
                LITCAL_MESSAGES::_G( $this->LitCal["StPaulVI"]->grade,$this->LITSETTINGS->LOCALE),
                $this->LitCal["StPaulVI"]->name,
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $this->LitCal["StPaulVI"]->date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$this->LitCal["StPaulVI"]->date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $this->LitCal["StPaulVI"]->date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $this->LitCal["StPaulVI"]->date->format('U'))))
                    ),
                2019,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20190125_decreto-celebrazione-paolovi_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $this->LITSETTINGS->YEAR
            );
        }
        else{
            if(in_array($PaulVI_date,$this->SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($PaulVI_date,$this->SOLEMNITIES);
            }
            else if(in_array($PaulVI_date,$this->FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($PaulVI_date,$this->FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $this->LitCal[$coincidingFestivityKey];
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                $PaulVI_tag[$this->LITSETTINGS->LOCALE],
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $PaulVI_date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$PaulVI_date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $PaulVI_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $PaulVI_date->format('U'))))
                    ),
                2019,
                '<a href="https://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20190125_decreto-celebrazione-paolovi' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $coincidingFestivity->name,
                $this->LITSETTINGS->YEAR
            );
        }

    }

    //With the Decree of the Congregation of Divine Worship of May 20, 2020, the optional memorial of St. Faustina was added on Oct 5
    //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20200518_decreto-celebrazione-santafaustina_la.html
    private function applyOptionalMemorialDecree2020() : void {
        $StFaustina_tag = ["LA" => "Sanctæ Faustinæ Kowalska", "IT" => "Santa Faustina Kowalska", "EN" => "Saint Faustina Kowalska"];
        $StFaustina_date = DateTime::createFromFormat('!j-n-Y', '5-10-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if(!in_array($StFaustina_date,$this->SOLEMNITIES) && !in_array($StFaustina_date,$this->FEASTS_MEMORIALS)){
            $this->LitCal["StFaustinaKowalska"] = new Festivity($StFaustina_tag[$this->LITSETTINGS->LOCALE], $StFaustina_date, "white", "fixed", LitGrade::MEMORIAL_OPT, "Holy Men and Women:For Religious");
            /**
             * TRANSLATORS:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Current year
             */
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$this->LITSETTINGS->LOCALE),
                LITCAL_MESSAGES::_G( $this->LitCal["StFaustinaKowalska"]->grade,$this->LITSETTINGS->LOCALE),
                $this->LitCal["StFaustinaKowalska"]->name,
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $this->LitCal["StFaustinaKowalska"]->date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$this->LitCal["StFaustinaKowalska"]->date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $this->LitCal["StFaustinaKowalska"]->date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $this->LitCal["StFaustinaKowalska"]->date->format('U'))))
                    ),
                2020,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20200518_decreto-celebrazione-santafaustina_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $this->LITSETTINGS->YEAR
            );
        }
        else{
            if(in_array($StFaustina_date,$this->SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($StFaustina_date,$this->SOLEMNITIES);
            }
            else if(in_array($StFaustina_date,$this->FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($StFaustina_date,$this->FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $this->LitCal[$coincidingFestivityKey];
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                $StFaustina_tag[$this->LITSETTINGS->LOCALE],
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $StFaustina_date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$StFaustina_date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $StFaustina_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $StFaustina_date->format('U'))))
                    ),
                2020,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20200518_decreto-celebrazione-santafaustina_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $coincidingFestivity->name,
                $this->LITSETTINGS->YEAR
            );
        }

    }

    private function applyOptionalMemorialDecree2021() {

        //With the Decree of the Congregation for Divine Worship on January 25, 2021,
        //the optional memorials of Gregory of Narek, John of Avila, and Hildegard of Bingen were added to the universal roman calendar
        //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20210125_decreto-dottori_la.html

        $StGregoryNarek_tag     = ["LA" => "Sancti Gregorii Narecensis, abbatis et Ecclesiæ doctoris", "IT" => "San Gregorio di Narek, abate e dottore della Chiesa", "EN" => "Saint Gregory of Narek"];
        $StJohnAvila_tag        = ["LA" => "Sancti Ioannis De Avila, presbyteri et Ecclesiæ doctoris", "IT" => "San Giovanni d'Avila, sacerdote e dottore della Chiesa", "EN" => "Saint John of Avila, priest and doctor of the Church"];
        $StHildegardBingen_tag  = ["LA" => "Sanctæ Hildegardis Bingensis, virginis et Ecclesiæ doctoris", "IT" => "Santa Ildegarda de Bingen, vergine e dottore delle Chiesa", "EN" => "Saint Hildegard of Bingen, virgin and doctor of the Church"];

        $StGregoryNarek_date    = DateTime::createFromFormat('!j-n-Y', '27-2-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        $StJohnAvila_date       = DateTime::createFromFormat('!j-n-Y', '10-5-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        $StHildegardBingen_date = DateTime::createFromFormat('!j-n-Y', '17-9-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));

        if(!in_array($StGregoryNarek_date,$this->SOLEMNITIES) && !in_array($StGregoryNarek_date,$this->FEASTS_MEMORIALS)){
            $this->LitCal["StGregoryNarek"] = new Festivity($StGregoryNarek_tag[$this->LITSETTINGS->LOCALE], $StGregoryNarek_date, "white", "fixed", LitGrade::MEMORIAL_OPT, "Holy Men and Women:For an Abbot,Doctors");
            /**
             * TRANSLATORS:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Current year
             */
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$this->LITSETTINGS->LOCALE),
                LITCAL_MESSAGES::_G( $this->LitCal["StGregoryNarek"]->grade,$this->LITSETTINGS->LOCALE),
                $this->LitCal["StGregoryNarek"]->name,
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $this->LitCal["StGregoryNarek"]->date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$this->LitCal["StGregoryNarek"]->date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $this->LitCal["StGregoryNarek"]->date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $this->LitCal["StGregoryNarek"]->date->format('U'))))
                    ),
                2021,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20210125_decreto-dottori_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $this->LITSETTINGS->YEAR
            );
        }
        else{
            if(in_array($StGregoryNarek_date,$this->SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($StGregoryNarek_date,$this->SOLEMNITIES);
            }
            else if(in_array($StGregoryNarek_date,$this->FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($StGregoryNarek_date,$this->FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $this->LitCal[$coincidingFestivityKey];
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                $StGregoryNarek_tag[$this->LITSETTINGS->LOCALE],
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $StGregoryNarek_date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$StGregoryNarek_date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $StGregoryNarek_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $StGregoryNarek_date->format('U'))))
                    ),
                2021,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20210125_decreto-dottori_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $coincidingFestivity->name,
                $this->LITSETTINGS->YEAR
            );
        }

        if(!in_array($StJohnAvila_date,$this->SOLEMNITIES) && !in_array($StJohnAvila_date,$this->FEASTS_MEMORIALS)){
            $this->LitCal["StJohnAvila"] = new Festivity($StJohnAvila_tag[$this->LITSETTINGS->LOCALE], $StJohnAvila_date, "white", "fixed", LitGrade::MEMORIAL_OPT, "Pastors:For One Pastor,Doctors");
            /**
             * TRANSLATORS:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Current year
             */
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$this->LITSETTINGS->LOCALE),
                LITCAL_MESSAGES::_G( $this->LitCal["StJohnAvila"]->grade, $this->LITSETTINGS->LOCALE ),
                $this->LitCal["StJohnAvila"]->name,
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $this->LitCal["StJohnAvila"]->date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$this->LitCal["StJohnAvila"]->date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $this->LitCal["StJohnAvila"]->date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $this->LitCal["StJohnAvila"]->date->format('U'))))
                    ),
                2021,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20210125_decreto-dottori_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $this->LITSETTINGS->YEAR
            );
        }
        else{
            if(in_array($StJohnAvila_date,$this->SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($StJohnAvila_date,$this->SOLEMNITIES);
            }
            else if(in_array($StJohnAvila_date,$this->FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($StJohnAvila_date,$this->FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $this->LitCal[$coincidingFestivityKey];
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                $StJohnAvila_tag[$this->LITSETTINGS->LOCALE],
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $StJohnAvila_date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$StJohnAvila_date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $StJohnAvila_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $StJohnAvila_date->format('U'))))
                    ),
                2021,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20210125_decreto-dottori_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $coincidingFestivity->name,
                $this->LITSETTINGS->YEAR
            );
        }

        if(!in_array($StHildegardBingen_date,$this->SOLEMNITIES) && !in_array($StHildegardBingen_date,$this->FEASTS_MEMORIALS)){
            $this->LitCal["StHildegardBingen"] = new Festivity($StHildegardBingen_tag[$this->LITSETTINGS->LOCALE], $StHildegardBingen_date, "white", "fixed", LitGrade::MEMORIAL_OPT, "Virgins:For One Virgin,Doctors");
            /**
             * TRANSLATORS:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Current year
             */
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$this->LITSETTINGS->LOCALE),
                LITCAL_MESSAGES::_G( $this->LitCal["StHildegardBingen"]->grade,$this->LITSETTINGS->LOCALE),
                $this->LitCal["StHildegardBingen"]->name,
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $this->LitCal["StHildegardBingen"]->date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$this->LitCal["StHildegardBingen"]->date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $this->LitCal["StHildegardBingen"]->date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $this->LitCal["StHildegardBingen"]->date->format('U'))))
                    ),
                2021,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20210125_decreto-dottori_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $this->LITSETTINGS->YEAR
            );
        }
        else{
            if(in_array($StHildegardBingen_date,$this->SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($StHildegardBingen_date,$this->SOLEMNITIES);
            }
            else if(in_array($StHildegardBingen_date,$this->FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($StHildegardBingen_date,$this->FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $this->LitCal[$coincidingFestivityKey];
            $this->Messages[] = sprintf(
                LITCAL_MESSAGES::__( "The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$this->LITSETTINGS->LOCALE),
                $StHildegardBingen_tag[$this->LITSETTINGS->LOCALE],
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $StHildegardBingen_date->format('j') . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[(int)$StHildegardBingen_date->format('n')] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $StHildegardBingen_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $StHildegardBingen_date->format('U'))))
                    ),
                2021,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20210125_decreto-dottori_' . strtolower( $this->LITSETTINGS->LOCALE) . '.html">' . LITCAL_MESSAGES::__( 'Decree of the Congregation for Divine Worship', $this->LITSETTINGS->LOCALE) . '</a>',
                $coincidingFestivity->name,
                $this->LITSETTINGS->YEAR
            );
        }

    }

    //On Saturdays in Ordinary Time when there is no obligatory memorial, an optional memorial of the Blessed Virgin Mary is allowed.
    //So we have to cycle through all Saturdays of the year checking if there isn't an obligatory memorial
    //First we'll find the first Saturday of the year (to do this we actually have to find the last Saturday of the previous year,
    // so that our cycle using "next Saturday" logic will actually start from the first Saturday of the year),
    // and then continue for every next Saturday until we reach the last Saturday of the year
    private function calculateSaturdayMemorialBVM() : void {
        $currentSaturday = new DateTime("previous Saturday January {$this->LITSETTINGS->YEAR}",new DateTimeZone('UTC'));
        $lastSatDT = new DateTime("last Saturday December {$this->LITSETTINGS->YEAR}",new DateTimeZone('UTC'));
        $SatMemBVM_cnt = 0;
        while($currentSaturday <= $lastSatDT){
            $currentSaturday = DateTime::createFromFormat('!j-n-Y', $currentSaturday->format('j-n-Y'),new DateTimeZone('UTC'))->modify('next Saturday');
            if(!in_array($currentSaturday, $this->SOLEMNITIES) && !in_array( $currentSaturday, $this->FEASTS_MEMORIALS)){
                $memID = "SatMemBVM" . ++$SatMemBVM_cnt;
                $this->LitCal[$memID] = new Festivity(LITCAL_MESSAGES::__( "Saturday Memorial of the Blessed Virgin Mary",$this->LITSETTINGS->LOCALE), $currentSaturday, "white", "mobile", LitGrade::MEMORIAL_OPT, "Blessed Virgin Mary" );
            }
        }
    }

    //13. Weekdays of Advent up until Dec. 16 included (already calculated and defined together with weekdays 17 Dec. - 24 Dec.)
    //    Weekdays of Christmas season from 2 Jan. until the Saturday after Epiphany
    //    Weekdays of the Easter season, from the Monday after the Octave of Easter to the Saturday before Pentecost
    private function calculateWeekdaysMajorSeasons() : void {

        $DoMEaster = $this->LitCal["Easter"]->date->format('j');      //day of the month of Easter
        $MonthEaster = $this->LitCal["Easter"]->date->format('n');    //month of Easter

        //let's start cycling dates one at a time starting from Easter itself
        $weekdayEaster = DateTime::createFromFormat('!j-n-Y', $DoMEaster . '-' . $MonthEaster . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        $weekdayEasterCnt = 1;
        while ($weekdayEaster >= $this->LitCal["Easter"]->date && $weekdayEaster < $this->LitCal["Pentecost"]->date) {
            $weekdayEaster = DateTime::createFromFormat('!j-n-Y', $DoMEaster . '-' . $MonthEaster . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->add(new DateInterval('P' . $weekdayEasterCnt . 'D'));

            if (!in_array($weekdayEaster, $this->SOLEMNITIES) && !in_array($weekdayEaster, $this->FEASTS_MEMORIALS) && (int)$weekdayEaster->format('N') !== 7) {

                $upper = (int)$weekdayEaster->format('z');
                $diff = $upper - (int)$this->LitCal["Easter"]->date->format('z'); //day count between current day and Easter Sunday
                $currentEasterWeek = (($diff - $diff % 7) / 7) + 1;         //week count between current day and Easter Sunday
                $ordinal = ucfirst(LitCalFf::getOrdinal($currentEasterWeek,$this->LITSETTINGS->LOCALE,$this->formatterFem,LITCAL_MESSAGES::LATIN_ORDINAL_FEM_GEN));
                $this->LitCal["EasterWeekday" . $weekdayEasterCnt] = new Festivity(( $this->LITSETTINGS->LOCALE == 'LA' ? LITCAL_MESSAGES::LATIN_DAYOFTHEWEEK[$weekdayEaster->format('w')] : ucfirst(utf8_encode(strftime('%A',$weekdayEaster->format('U'))))) . " " . sprintf(LITCAL_MESSAGES::__( "of the %s Week of Easter",$this->LITSETTINGS->LOCALE),$ordinal), $weekdayEaster, "white", "mobile");
            $this->LitCal["EasterWeekday" . $weekdayEasterCnt]->psalterWeek = LitCalFf::psalterWeek($currentEasterWeek);
            }

            $weekdayEasterCnt++;
        }

    }

    //    Weekdays of Ordinary time
    private function calculateWeekdaysOrdinaryTime() : void {

        //In the first part of the year, weekdays of ordinary time begin the day after the Baptism of the Lord
        $FirstWeekdaysLowerLimit = $this->LitCal["BaptismLord"]->date;
        //and end with Ash Wednesday
        $FirstWeekdaysUpperLimit = $this->LitCal["AshWednesday"]->date;

        $ordWeekday = 1;
        $currentOrdWeek = 1;
        $firstOrdinary = DateTime::createFromFormat('!j-n-Y', $this->BaptismLordFmt, new DateTimeZone('UTC'))->modify($this->BaptismLordMod);
        $firstSunday = DateTime::createFromFormat('!j-n-Y', $this->BaptismLordFmt, new DateTimeZone('UTC'))->modify($this->BaptismLordMod)->modify('next Sunday');
        $dayFirstSunday = (int)$firstSunday->format('z');

        while ($firstOrdinary >= $FirstWeekdaysLowerLimit && $firstOrdinary < $FirstWeekdaysUpperLimit) {
            $firstOrdinary = DateTime::createFromFormat('!j-n-Y', $this->BaptismLordFmt, new DateTimeZone('UTC'))->modify($this->BaptismLordMod)->add(new DateInterval('P' . $ordWeekday . 'D'));
            if (!in_array($firstOrdinary, $this->SOLEMNITIES) && !in_array($firstOrdinary, $this->FEASTS_MEMORIALS)) {
                //The Baptism of the Lord is the First Sunday, so the weekdays following are of the First Week of Ordinary Time
                //After the Second Sunday, let's calculate which week of Ordinary Time we're in
                if ($firstOrdinary > $firstSunday) {
                    $upper = (int) $firstOrdinary->format('z');
                    $diff = $upper - $dayFirstSunday;
                    $currentOrdWeek = (($diff - $diff % 7) / 7) + 2;
                }
                $ordinal = ucfirst(LitCalFf::getOrdinal($currentOrdWeek,$this->LITSETTINGS->LOCALE,$this->formatterFem,LITCAL_MESSAGES::LATIN_ORDINAL_FEM_GEN));
                $this->LitCal["FirstOrdWeekday" . $ordWeekday] = new Festivity(( $this->LITSETTINGS->LOCALE == 'LA' ? LITCAL_MESSAGES::LATIN_DAYOFTHEWEEK[$firstOrdinary->format('w')] : ucfirst(utf8_encode(strftime('%A',$firstOrdinary->format('U')))) ) . " " . sprintf(LITCAL_MESSAGES::__( "of the %s Week of Ordinary Time",$this->LITSETTINGS->LOCALE), $ordinal ), $firstOrdinary, "green", "mobile");
            $this->LitCal["FirstOrdWeekday" . $ordWeekday]->psalterWeek = LitCalFf::psalterWeek($currentOrdWeek);
            }
            $ordWeekday++;
        }


        //In the second part of the year, weekdays of ordinary time begin the day after Pentecost
        $SecondWeekdaysLowerLimit = $this->LitCal["Pentecost"]->date;
        //and end with the Feast of Christ the King
        $SecondWeekdaysUpperLimit = DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday')->sub(new DateInterval('P' . (3 * 7) . 'D'));

        $ordWeekday = 1;
        //$currentOrdWeek = 1;
        $lastOrdinary = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 7) . 'D'));
        $dayLastSunday = (int)DateTime::createFromFormat('!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday')->sub(new DateInterval('P' . (3 * 7) . 'D'))->format('z');

        while ($lastOrdinary >= $SecondWeekdaysLowerLimit && $lastOrdinary < $SecondWeekdaysUpperLimit) {
            $lastOrdinary = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 7 + $ordWeekday) . 'D'));
            if (!in_array($lastOrdinary, $this->SOLEMNITIES) && !in_array($lastOrdinary, $this->FEASTS_MEMORIALS)) {
                $lower = (int) $lastOrdinary->format('z');
                $diff = $dayLastSunday - $lower; //day count between current day and Christ the King Sunday
                $weekDiff = (($diff - $diff % 7) / 7); //week count between current day and Christ the King Sunday;
                $currentOrdWeek = 34 - $weekDiff;

                $ordinal = ucfirst(LitCalFf::getOrdinal($currentOrdWeek,$this->LITSETTINGS->LOCALE,$this->formatterFem,LITCAL_MESSAGES::LATIN_ORDINAL_FEM_GEN));
                $this->LitCal["LastOrdWeekday" . $ordWeekday] = new Festivity(( $this->LITSETTINGS->LOCALE == 'LA' ? LITCAL_MESSAGES::LATIN_DAYOFTHEWEEK[$lastOrdinary->format('w')] : ucfirst(utf8_encode(strftime('%A',$lastOrdinary->format('U')))) ) . " " . sprintf(LITCAL_MESSAGES::__( "of the %s Week of Ordinary Time",$this->LITSETTINGS->LOCALE), $ordinal ), $lastOrdinary, "green", "mobile");
            $this->LitCal["LastOrdWeekday" . $ordWeekday]->psalterWeek = LitCalFf::psalterWeek($currentOrdWeek);
            }
            $ordWeekday++;
        }

    }

    private function applyCalendarItaly() : void {
        $this->applyPatronSaintsEurope();
        $this->applyPatronSaintsItaly();
        if( $this->LITSETTINGS->YEAR >= 1983 && $this->LITSETTINGS->YEAR < 2002){
            //The extra liturgical events found in the 1983 edition of the Roman Missal in Italian,
            //were then incorporated into the Latin edition in 2002 (effectively being incorporated into the General Roman Calendar)
            //so when dealing with Italy, we only need to add them from 1983 until 2002, after which it's taken care of by the General Calendar
            $this->applyMessaleRomano1983();
        }

        //The Sanctorale in the 2020 edition is based on the Latin 2008 Edition,
        // there isn't really anything different from preceding editions or from the 2008 edition
    }


    private function makePatron( string $tag, string $nameSuffix, int $day, int $month, LitColor $color, string $EditionRomanMissal = ROMANMISSAL::EDITIO_TYPICA_1970 ) {
        if( array_key_exists( $tag, $this->LitCal ) ) {
            $this->LitCal[$tag]->grade = LitGrade::FEAST;
            $this->LitCal[$tag]->name .= $nameSuffix;
            $this->LitCal[$tag]->common = "Proper";
        } else{

            //check what's going on, for example, if it's a Sunday or Solemnity
            $currentFeastDate = DateTime::createFromFormat( '!j-n-Y', "{$day}-{$month}-" . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC') );

            //let's also get the name back from the database, so we can give some feedback and maybe even recreate the festivity
            $tableName = ROMANMISSAL::getSanctoraleTableName( $EditionRomanMissal );
            $result = $this->mysqli->query("SELECT * FROM {$tableName} WHERE TAG = '{$tag}'");
            $row = mysqli_fetch_assoc( $result );
            $FestivityName = $row[ "NAME_" . $this->LITSETTINGS->LOCALE ] . $nameSuffix;

            if( in_array( $currentFeastDate, $this->SOLEMNITIES ) || in_array( $currentFeastDate, $this->FEASTS_MEMORIALS ) || (int)$currentFeastDate->format('N') === 7 ) {
                $coincidingFestivity_grade = '';
                if ( (int)$currentFeastDate->format('N') === 7 && $this->LitCal[ array_search( $currentFeastDate, $this->SOLEMNITIES ) ]->grade < LitGrade::SOLEMNITY ){
                    //it's a Sunday
                    $coincidingFestivity = $this->LitCal[ array_search( $currentFeastDate, $this->SOLEMNITIES ) ];
                    $coincidingFestivity_grade = $this->LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst( utf8_encode( strftime( '%A', $currentFeastDate->format('U') ) ) );
                } else if ( in_array( $currentFeastDate, $this->SOLEMNITIES ) ) {
                    //it's a Feast of the Lord or a Solemnity
                    $coincidingFestivity = $this->LitCal[ array_search( $currentFeastDate, $this->SOLEMNITIES ) ];
                    $coincidingFestivity_grade = ( $coincidingFestivity->grade > LitGrade::SOLEMNITY ? '<i>' . LITCAL_MESSAGES::_G(  $coincidingFestivity->grade, $this->LITSETTINGS->LOCALE, false ) . '</i>' : LITCAL_MESSAGES::_G(  $coincidingFestivity->grade, $this->LITSETTINGS->LOCALE, false ) );
                } else if ( in_array( $currentFeastDate, $this->FEASTS_MEMORIALS ) ) {
                    //we should probably be able to create it anyways in this case?
                    $this->LitCal[$tag] = new Festivity( $FestivityName, $currentFeastDate, $color, "fixed", LitGrade::FEAST, "Proper" );
                    $coincidingFestivity = $this->LitCal[ array_search( $currentFeastDate, $this->FEASTS_MEMORIALS ) ];
                    $coincidingFestivity_grade = LITCAL_MESSAGES::_G(  $coincidingFestivity->grade, $this->LITSETTINGS->LOCALE, false );
                }

                $this->Messages[] =  '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                    LITCAL_MESSAGES::__( "The %s '%s', usually celebrated on %s, is suppressed by the %s '%s' in the year %d.", $this->LITSETTINGS->LOCALE ),
                    LITCAL_MESSAGES::_G(  LitGrade::FEAST, $this->LITSETTINGS->LOCALE, false ),
                    $FestivityName,
                    trim( utf8_encode( strftime( '%e %B', $currentFeastDate->format('U') ) ) ),
                    $coincidingFestivity_grade,
                    $coincidingFestivity->name,
                    $this->LITSETTINGS->YEAR
                );

            }
        }
    }


    //Insert or elevate the Patron Saints of Europe
    //TODO: this method should work for all languages of European countries
    private function applyPatronSaintsEurope() : void {

        //Saint Benedict, Saint Bridget, and Saint Cyril and Methodius elevated to Feast, with title "patrono/i d'Europa" added
        //then from 1999, Saint Catherine of Siena and Saint Edith Stein, elevated to Feast with title "compatrona d'Europa" added
        $this->makePatron( "StBenedict", ", patrono d'Europa", 11, 7, LitColor::WHITE );
        $this->makePatron( "StBridget", ", patrona d'Europa", 23, 7, LitColor::WHITE );
        $this->makePatron( "StEdithStein", ", patrona d'Europa", 9, 8, LitColor::WHITE, ROMANMISSAL::USA_EDITION_2011 );
        $this->makePatron( "StsCyrilMethodius", ", patroni d'Europa", 14, 2, LitColor::WHITE );

        //In 1999, Pope John Paul II elevated Catherine of Siena from patron of Italy to patron of Europe
        if( $this->LITSETTINGS->YEAR >= 1999){
            $this->makePatron( "StCatherineSiena", ", patrona d'Italia e d'Europa", 29, 4, LitColor::WHITE );
        }

    }

    //Insert or elevate the Patron Saints of Italy
    private function applyPatronSaintsItaly() : void {

        if ( $this->LITSETTINGS->YEAR < 1999 ) {
            //We only have to deal with years before 1999, because from 1999
            //it will be taken care of by Patron saints of Europe
            $this->makePatron( "StCatherineSiena", ", patrona d'Italia", 29, 4, LitColor::WHITE );
        }

        $this->makePatron( "StFrancisAssisi", ", patrono d'Italia", 4, 10, LitColor::WHITE );

    }

    private function applyMessaleRomano1983() : void {

        $result = $this->mysqli->query("SELECT * FROM LITURGY__ITALY_calendar_propriumdesanctis_1983");
        if ( $result ) {
            while ($row = mysqli_fetch_assoc($result)) {
                $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row['DAY'] . '-' . $row['MONTH'] . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                if(!in_array($currentFeastDate,$this->SOLEMNITIES)){
                    $this->LitCal[$row["TAG"]] = new Festivity("[ITALIA] " . $row["NAME_" . $this->LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"], $row["DISPLAYGRADE"]);
                }
                else{
                    $this->Messages[] = sprintf(
                        "ITALIA: la %s '%s' (%s), aggiunta al calendario nell'edizione del Messale Romano del 1983 pubblicata dalla CEI, è soppressa da una Domenica o una Solennità nell'anno %d",
                        $row["DISPLAYGRADE"] !== "" ? $row["DISPLAYGRADE"] : LITCAL_MESSAGES::_G( $row["GRADE"],$this->LITSETTINGS->LOCALE,false),
                        '<i>' . $row["NAME_" . $this->LITSETTINGS->LOCALE] . '</i>',
                        trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U')))),
                        $this->LITSETTINGS->YEAR
                    );
                }
            }
        }

    }

    private function applyCalendarUSA() : void {

        //The Solemnity of the Immaculate Conception is the Patronal FeastDay of the United States of America
        if(array_key_exists("ImmaculateConception",$this->LitCal)){
            $this->LitCal["ImmaculateConception"]->name .= ", Patronal feastday of the United States of America";
        }

        //move Saint Vincent Deacon from Jan 22 to Jan 23 in order to allow for National Day of Prayer for the Unborn on Jan 22
        //however if Jan 22 is a Sunday, National Day of Prayer for the Unborn is moved to Jan 23 (in place of Saint Vincent Deacon)
        if(array_key_exists("StVincentDeacon",$this->LitCal)){
            //I believe we don't have to worry about suppressing, because if it's on a Sunday it won't exist already
            //so if the National Day of Prayer happens on a Sunday and must be moved to Monday, Saint Vincent will be already gone anyways
            $this->LitCal["StVincentDeacon"]->date->add(new DateInterval('P1D'));
            //let's not worry about translating these messages, just leave them in English
            $this->Messages[] = sprintf(
                "USA: The Memorial '%s' was moved from Jan 22 to Jan 23 to make room for the National Day of Prayer for the Unborn, as per the 2011 Roman Missal issued by the USCCB",
                '<i>' . $this->LitCal["StVincentDeacon"]->name . '</i>'
            );
            $this->LitCal["StVincentDeacon"]->name = "[USA] " . $this->LitCal["StVincentDeacon"]->name;
        }

        if(array_key_exists("StsJeanBrebeuf",$this->LitCal)){
            //if it exists, it means it's not on a Sunday, so we can go ahead and elevate it to Memorial
            $this->LitCal["StsJeanBrebeuf"]->grade = LitGrade::MEMORIAL;
            $this->Messages[] = sprintf(
                "USA: The optional memorial '%s' is elevated to Memorial on Oct 19 as per the 2011 Roman Missal issued by the USCCB, applicable to the year %d",
                '<i>' . $this->LitCal["StsJeanBrebeuf"]->name . '</i>',
                $this->LITSETTINGS->YEAR
            );
            $this->LitCal["StsJeanBrebeuf"]->name = "[USA] " . $this->LitCal["StsJeanBrebeuf"]->name;
            
            if(array_key_exists("StPaulCross",$this->LitCal)){ //of course it will exist if StsJeanBrebeuf exists, they are originally on the same day
                $this->LitCal["StPaulCross"]->date->add(new DateInterval('P1D'));
                if(in_array($this->LitCal["StPaulCross"]->date,$this->SOLEMNITIES) || in_array($this->LitCal["StPaulCross"]->date,$this->FEASTS_MEMORIALS)){
                    $this->Messages[] = sprintf(
                        "USA: The optional memorial '%s' is transferred from Oct 19 to Oct 20 as per the 2011 Roman Missal issued by the USCCB, to make room for '%s' elevated to the rank of Memorial, however in the year %d it is superseded by a higher ranking liturgical event",
                        '<i>' . $this->LitCal["StPaulCross"]->name . '</i>',
                        '<i>' . $this->LitCal["StsJeanBrebeuf"]->name . '</i>',
                        $this->LITSETTINGS->YEAR
                    );
                    unset($this->LitCal["StPaulCross"]);
                }else{
                    $this->Messages[] = sprintf(
                        'USA: The optional memorial \'%1$s\' is transferred from Oct 19 to Oct 20 as per the 2011 Roman Missal issued by the USCCB, to make room for \'%2$s\' elevated to the rank of Memorial: applicable to the year %3$d.',
                        '<i>' . $this->LitCal["StPaulCross"]->name . '</i>',
                        '<i>' . $this->LitCal["StsJeanBrebeuf"]->name . '</i>',
                        $this->LITSETTINGS->YEAR
                    );
                    $this->LitCal["StPaulCross"]->name = "[USA] " . $this->LitCal["StPaulCross"]->name;
                }
            }
        }
        else{
            //if Oct 19 is a Sunday or Solemnity, Saint Paul of the Cross won't exist. But it still needs to be moved to Oct 20 so we must create it again
            $currentFeastDate = DateTime::createFromFormat('!j-n-Y', '20-10-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
            if(!in_array($currentFeastDate,$this->SOLEMNITIES) && !array_key_exists("StPaulCross",$this->LitCal) ){
                $result = $this->mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'StPaulCross'");
                if ( $result ) {
                    $row = mysqli_fetch_assoc($result);
                    $this->LitCal["StPaulCross"] = new Festivity("[USA] " . $row["NAME_" . $this->LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);
                    $this->Messages[] = sprintf(
                        'USA: The optional memorial \'%1$s\' is transferred from Oct 19 to Oct 20 as per the 2011 Roman Missal issued by the USCCB, to make room for \'%2$s\' elevated to the rank of Memorial: applicable to the year %3$d.',
                        $row["NAME_" . $this->LITSETTINGS->LOCALE],
                        '<i>' . $this->LitCal["StsJeanBrebeuf"]->name . '</i>',
                        $this->LITSETTINGS->YEAR
                    );
                }
            }
        }

        //The fourth Thursday of November is Thanksgiving
        $thanksgivingDateTS = strtotime('fourth thursday of november ' . $this->LITSETTINGS->YEAR . ' UTC');
        $thanksgivingDate = new DateTime("@$thanksgivingDateTS", new DateTimeZone('UTC'));
        $this->LitCal["ThanksgivingDay"] = new Festivity("[USA] Thanksgiving", $thanksgivingDate, "white", "mobile", LitGrade::MEMORIAL, '', 'National Holiday');

        $result = $this->mysqli->query("SELECT * FROM LITURGY__USA_calendar_propriumdesanctis_2011");
        if ( $result ) {
            while ($row = mysqli_fetch_assoc($result)) {
                $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row['DAY'] . '-' . $row['MONTH'] . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                if(!in_array($currentFeastDate,$this->SOLEMNITIES)){
                    $this->LitCal[$row["TAG"]] = new Festivity("[USA] " . $row["NAME_" . $this->LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"], $row["DISPLAYGRADE"]);
                }
                else if((int)$currentFeastDate->format('N') === 7 && $row["TAG"] === "PrayerUnborn" ){
                    $this->LitCal[$row["TAG"]] = new Festivity("[USA] " . $row["NAME_" . $this->LITSETTINGS->LOCALE], $currentFeastDate->add(new DateInterval('P1D')), $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"], $row["DISPLAYGRADE"]);
                    $this->Messages[] = sprintf(
                        "USA: The National Day of Prayer for the Unborn is set to Jan 22 as per the 2011 Roman Missal issued by the USCCB, however since it coincides with a Sunday or a Solemnity in the year %d, it has been moved to Jan 23",
                        $this->LITSETTINGS->YEAR
                    );
                }
                else{
                    $this->Messages[] = sprintf(
                        "USA: the %s '%s', added to the calendar as per the 2011 Roman Missal issued by the USCCB, is superseded by a Sunday or a Solemnity in the year %d",
                        $row["DISPLAYGRADE"] !== "" ? $row["DISPLAYGRADE"] : LITCAL_MESSAGES::_G( $row["GRADE"],$this->LITSETTINGS->LOCALE,false),
                        '<i>' . $row["NAME_" . $this->LITSETTINGS->LOCALE] . '</i>',
                        $this->LITSETTINGS->YEAR
                    );
                }
            }
        }

        $currentFeastDate = DateTime::createFromFormat('!j-n-Y', '18-7-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if(!in_array($currentFeastDate,$this->SOLEMNITIES)){
            if(array_key_exists("StCamillusDeLellis",$this->LitCal)){
                //Move Camillus De Lellis from July 14 to July 18, to make room for Kateri Tekakwitha
                $this->LitCal["StCamillusDeLellis"]->date = $currentFeastDate;
            }
            else{
                //if it was suppressed on July 14 because of higher ranking celebration, we should recreate it on July 18 if possible
                $result = $this->mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'StCamillusDeLellis'");
                if ( $result ) {
                    $row = mysqli_fetch_assoc($result);
                    $this->LitCal["StCamillusDeLellis"] = new Festivity($row["NAME_" . $this->LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);
                }
            }
            $this->Messages[] = sprintf(
                'USA: The optional memorial \'%1$s\' is transferred from July 14 to July 18 as per the 2011 Roman Missal issued by the USCCB, to make room for the Memorial \'%2$s\': applicable to the year %3$d.',
                '<i>' . $this->LitCal["StCamillusDeLellis"]->name . '</i>',
                '<i>' . "Blessed Kateri Tekakwitha" . '</i>', //can't use $this->LitCal["KateriTekakwitha"], might not exist!
                $this->LITSETTINGS->YEAR
            );
            $this->LitCal["StCamillusDeLellis"]->name = "[USA] " . $this->LitCal["StCamillusDeLellis"]->name;
        }
        else{
            if(array_key_exists("StCamillusDeLellis",$this->LitCal)){
                //Can't move Camillus De Lellis from July 14 to July 18, so simply suppress to make room for Kateri Tekakwitha
                $this->Messages[] = sprintf(
                    'USA: The optional memorial \'%1$s\' is transferred from July 14 to July 18 as per the 2011 Roman Missal issued by the USCCB, to make room for the Memorial \'%2$s\', however it is superseded by a higher ranking festivity in the year %3$d.',
                    '<i>' . $this->LitCal["StCamillusDeLellis"]->name . '</i>',
                    '<i>' . "Blessed Kateri Tekakwitha" . '</i>', //can't use $this->LitCal["KateriTekakwitha"], might not exist!
                    $this->LITSETTINGS->YEAR
                );
                unset($this->LitCal["StCamillusDeLellis"]);
            }
        }

        $currentFeastDate = DateTime::createFromFormat('!j-n-Y', '5-7-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if(!in_array($currentFeastDate,$this->SOLEMNITIES)){
            if(array_key_exists("StElizabethPortugal",$this->LitCal)){
                //Move Elizabeth of Portugal from July 4 to July 5 to make room for Independence Day
                $this->LitCal["StElizabethPortugal"]->date = $currentFeastDate;
            }
            else{
                //if it was suppressed on July 4 because of higher ranking celebration, we should recreate on July 5 if possible
                $result = $this->mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'StElizabethPortugal'");
                if ( $result ) {
                    $row = mysqli_fetch_assoc($result);
                    $this->LitCal["StElizabethPortugal"] = new Festivity($row["NAME_" . $this->LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);
                }
            }
            $this->Messages[] = sprintf(
                'USA: The optional memorial \'%1$s\' is transferred from July 4 to July 5 as per the 2011 Roman Missal issued by the USCCB, to make room for the Holiday \'%2$s\': applicable to the year %3$d.',
                '<i>' . $this->LitCal["StElizabethPortugal"]->name . '</i>',
                '<i>' . "Independence Day" . '</i>', //can't use $this->LitCal["IndependenceDay"], might not exist!
                $this->LITSETTINGS->YEAR
            );
            $this->LitCal["StElizabethPortugal"]->name = "[USA] " . $this->LitCal["StElizabethPortugal"]->name;
        }
        else{
            if(array_key_exists("StElizabethPortugal",$this->LitCal)){
                //Can't move Elizabeth of Portugal to July 5, so simply suppress to make room for Independence Day
                $this->Messages[] = sprintf(
                    'USA: The optional memorial \'%1$s\' is transferred from July 4 to July 5 as per the 2011 Roman Missal issued by the USCCB, to make room for the holiday \'%2$s\', however it is superseded by a higher ranking festivity in the year %3$d.',
                    '<i>' . $this->LitCal["StElizabethPortugal"]->name . '</i>',
                    '<i>' . "Independence Day" . '</i>', //can't use $this->LitCal["IndependenceDay"], might not exist!
                    $this->LITSETTINGS->YEAR
                );
                unset($this->LitCal["StElizabethPortugal"]);
            }
        }

    }

    //CYCLE THROUGH ALL EVENTS CREATED AND CALCULATE THE YEARLY LITURGICAL CYCLE, WHETHER FESTIVE (A,B,C) OR WEEKDAY (I,II)
    //This property will only be set if we're dealing with a Sunday, a Solemnity, a Feast of the Lord, or a weekday
    //In all other cases it is not needed because there aren't choices of liturgical texts
    private function setCyclesAndVigils() : void {
        $SUNDAY_CYCLE = ["A", "B", "C"];
        $WEEKDAY_CYCLE = ["I", "II"];
        foreach($this->LitCal as $key => $festivity){
            //first let's deal with weekdays we calculate the weekday cycle
            if ((int)$festivity->grade === LitGrade::WEEKDAY && (int)$festivity->date->format('N') !== 7) {
                if ($festivity->date < $this->LitCal["Advent1"]->date) {
                    $this->LitCal[$key]->liturgicalyear = LITCAL_MESSAGES::__( "YEAR", $this->LITSETTINGS->LOCALE) . " " . ($WEEKDAY_CYCLE[ ( $this->LITSETTINGS->YEAR - 1) % 2] );
                } else if ($festivity->date >= $this->LitCal["Advent1"]->date) {
                    $this->LitCal[$key]->liturgicalyear = LITCAL_MESSAGES::__( "YEAR", $this->LITSETTINGS->LOCALE) . " " . ($WEEKDAY_CYCLE[ $this->LITSETTINGS->YEAR % 2 ]);
                }
            }
            //if we're dealing with a Sunday or a Solemnity or a Feast of the Lord, then we calculate the Sunday/Festive Cycle
            else if((int)$festivity->date->format('N') === 7 || (int)$festivity->grade > LitGrade::FEAST) {
                if ($festivity->date < $this->LitCal["Advent1"]->date) {
                    $this->LitCal[$key]->liturgicalyear = LITCAL_MESSAGES::__( "YEAR", $this->LITSETTINGS->LOCALE) . " " . ($SUNDAY_CYCLE[ ( $this->LITSETTINGS->YEAR - 1) % 3 ]);
                } else if ($festivity->date >= $this->LitCal["Advent1"]->date) {
                    $this->LitCal[$key]->liturgicalyear = LITCAL_MESSAGES::__( "YEAR", $this->LITSETTINGS->LOCALE) . " " . ($SUNDAY_CYCLE[ $this->LITSETTINGS->YEAR % 3 ]);
                }

                //Let's calculate Vigil Masses while we're at it
                //TODO: For now we are creating new events, but perhaps we should be adding metadata to the festivities themselves? hasVigilMass = true/false?
                //perhaps we can even do both for the time being...
                $VigilDate = clone( $festivity->date );
                $VigilDate->sub( new DateInterval('P1D') );

                $festivityGrade = '';
                if((int)$festivity->date->format('N') === 7 && $festivity->grade < LitGrade::SOLEMNITY ){
                    $festivityGrade = $this->LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$festivity->date->format('U'))));
                } else {
                    $festivityGrade = ($festivity->grade > LitGrade::SOLEMNITY ? '<i>' . LITCAL_MESSAGES::_G( $festivity->grade,$this->LITSETTINGS->LOCALE,false) . '</i>' : LITCAL_MESSAGES::_G( $festivity->grade,$this->LITSETTINGS->LOCALE,false));
                }

                //conditions for which the festivity SHOULD have a vigil
                if(true === ($festivity->grade >= LitGrade::SOLEMNITY) || true ===  ((int)$festivity->date->format('N') === 7) ){
                    //filter out cases in which the festivity should NOT have a vigil
                    if(
                        false === ($key === 'AllSouls')
                        && false === ($key === 'AshWednesday')
                        && false === ($festivity->date > $this->LitCal["PalmSun"]->date && $festivity->date < $this->LitCal["Easter"]->date)
                        && false === ($festivity->date > $this->LitCal["Easter"]->date && $festivity->date < $this->LitCal["Easter2"]->date)
                    ){
                        $this->LitCal[$key . "_vigil"] = new Festivity($festivity->name . " " . LITCAL_MESSAGES::__( "Vigil Mass",$this->LITSETTINGS->LOCALE), $VigilDate, $festivity->color, $festivity->type, $festivity->grade, $festivity->common );
                        $this->LitCal[$key]->hasVigilMass = true;
                        $this->LitCal[$key]->hasVesperI = true;
                        $this->LitCal[$key]->hasVesperII = true;
                        $this->LitCal[$key . "_vigil"]->liturgicalyear = $this->LitCal[$key]->liturgicalyear;
                        $this->LitCal[$key . "_vigil"]->isVigilMass = true;
                        //if however the Vigil coincides with another Solemnity let's make a note of it!
                        if(in_array($VigilDate,$this->SOLEMNITIES)){
                            $coincidingFestivity_grade = '';
                            $coincidingFestivityKey = array_search($VigilDate,$this->SOLEMNITIES);
                            $coincidingFestivity = $this->LitCal[$coincidingFestivityKey];
                            if((int)$VigilDate->format('N') === 7 && $coincidingFestivity->grade < LitGrade::SOLEMNITY ){
                                //it's a Sunday
                                $coincidingFestivity_grade = $this->LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$VigilDate->format('U'))));
                            } else{
                                //it's a Feast of the Lord or a Solemnity
                                $coincidingFestivity_grade = ($coincidingFestivity->grade > LitGrade::SOLEMNITY ? '<i>' . LITCAL_MESSAGES::_G( $coincidingFestivity->grade,$this->LITSETTINGS->LOCALE,false) . '</i>' : LITCAL_MESSAGES::_G( $coincidingFestivity->grade,$this->LITSETTINGS->LOCALE,false));
                            }

                            //suppress warning messages for known situations, like the Octave of Easter
                            if($festivity->grade !== LitGrade::HIGHER_SOLEMNITY ){
                                if( $festivity->grade < $coincidingFestivity->grade ){
                                    $festivity->hasVigilMass = false;
                                    $festivity->hasVesperI = false;
                                    $coincidingFestivity->hasVesperII = true;
                                    $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                        LITCAL_MESSAGES::__( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. This last Solemnity takes precedence, therefore it will maintain Vespers II and an evening Mass, while  the first Solemnity will not have a Vigil Mass or Vespers I.", $this->LITSETTINGS->LOCALE),
                                        $festivityGrade,
                                        $festivity->name,
                                        $coincidingFestivity_grade,
                                        $coincidingFestivity->name,
                                        $this->LITSETTINGS->YEAR
                                    );
                                }
                                else if( $festivity->grade > $coincidingFestivity->grade ){
                                    $festivity->hasVigilMass = true;
                                    $festivity->hasVesperI = true;
                                    $coincidingFestivity->hasVesperII = false;
                                    $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                        LITCAL_MESSAGES::__( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. Since the first Solemnity has precedence, it will have Vespers I and a vigil Mass, whereas the last Solemnity will not have either Vespers II or an evening Mass.", $this->LITSETTINGS->LOCALE),
                                        $festivityGrade,
                                        $festivity->name,
                                        $coincidingFestivity_grade,
                                        $coincidingFestivity->name,
                                        $this->LITSETTINGS->YEAR
                                    );
                                }
                                else if(in_array($key,$this->SOLEMNITIES_LORD_BVM) && !in_array($coincidingFestivityKey,$this->SOLEMNITIES_LORD_BVM) ){
                                    $festivity->hasVigilMass = true;
                                    $festivity->hasVesperI = true;
                                    $coincidingFestivity->hasVesperII = false;
                                    $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                        LITCAL_MESSAGES::__( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. Since the first Solemnity has precedence, it will have Vespers I and a vigil Mass, whereas the last Solemnity will not have either Vespers II or an evening Mass.", $this->LITSETTINGS->LOCALE),
                                        $festivityGrade,
                                        $festivity->name,
                                        $coincidingFestivity_grade,
                                        $coincidingFestivity->name,
                                        $this->LITSETTINGS->YEAR
                                    );
                                }
                                else if(in_array($coincidingFestivityKey,$this->SOLEMNITIES_LORD_BVM) && !in_array($key,$this->SOLEMNITIES_LORD_BVM) ){
                                    $coincidingFestivity->hasVesperII = true;
                                    $festivity->hasVesperI = false;
                                    $festivity->hasVigilMass = false;
                                    unset($this->LitCal[$key . "_vigil"]);
                                    $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                        LITCAL_MESSAGES::__( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. This last Solemnity takes precedence, therefore it will maintain Vespers II and an evening Mass, while  the first Solemnity will not have a Vigil Mass or Vespers I.", $this->LITSETTINGS->LOCALE),
                                        $festivityGrade,
                                        $festivity->name,
                                        $coincidingFestivity_grade,
                                        $coincidingFestivity->name,
                                        $this->LITSETTINGS->YEAR
                                    );
                                } else {
                                    if( $this->LITSETTINGS->YEAR === 2022){
                                        if($key === 'SacredHeart' || $key === 'Lent3' || $key === 'Assumption'){
                                            $coincidingFestivity->hasVesperII = false;
                                            $festivity->hasVesperI = true;
                                            $festivity->hasVigilMass = true;
                                            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                                LITCAL_MESSAGES::__( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. As per %s, the first has precedence, therefore the Vigil Mass is confirmed as are I Vespers.", $this->LITSETTINGS->LOCALE),
                                                $festivityGrade,
                                                $festivity->name,
                                                $coincidingFestivity_grade,
                                                $coincidingFestivity->name,
                                                $this->LITSETTINGS->YEAR,
                                                '<a href="http://www.cultodivino.va/content/cultodivino/it/documenti/responsa-ad-dubia/2020/de-calendario-liturgico-2022.html">' . LITCAL_MESSAGES::__( "Decree of the Congregation for Divine Worship",$this->LITSETTINGS->LOCALE) . '</a>'
                                            );
                                        }
                                    }
                                    else {
                                        $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                            LITCAL_MESSAGES::__( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. We should ask the Congregation for Divine Worship what to do about this!", $this->LITSETTINGS->LOCALE),
                                            $festivityGrade,
                                            $festivity->name,
                                            $coincidingFestivity_grade,
                                            $coincidingFestivity->name,
                                            $this->LITSETTINGS->YEAR
                                        );
                                    }

                                }
                            } else {
                                if(
                                    //false === ($key === 'AllSouls')
                                    //&& false === ($key === 'AshWednesday')
                                    false === ($coincidingFestivity->date > $this->LitCal["PalmSun"]->date && $coincidingFestivity->date < $this->LitCal["Easter"]->date)
                                    && false === ($coincidingFestivity->date > $this->LitCal["Easter"]->date && $coincidingFestivity->date < $this->LitCal["Easter2"]->date)
                                ){

                                    $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                        LITCAL_MESSAGES::__( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. Since the first Solemnity has precedence, it will have Vespers I and a vigil Mass, whereas the last Solemnity will not have either Vespers II or an evening Mass.", $this->LITSETTINGS->LOCALE),
                                        $festivityGrade,
                                        $festivity->name,
                                        $coincidingFestivity_grade,
                                        $coincidingFestivity->name,
                                        $this->LITSETTINGS->YEAR
                                    );
                                }
                            }

                        }
                    } else {
                        $this->LitCal[$key]->hasVigilMass = false;
                        $this->LitCal[$key]->hasVesperI = false;
                    }
                }

            }
        }

    }

    private function generateResponse() {

        $SerializeableLitCal                          = new stdClass();
        $SerializeableLitCal->Settings                = new stdClass();
        $SerializeableLitCal->Metadata                = new stdClass();

        //$this->LitCal variable is an associative array, who's keys are a string that identifies the event created (ex. ImmaculateConception)
        //So in order to sort by date we have to be sure to maintain the association with the proper key, uasort allows us to do this
        uasort($this->LitCal, array("Festivity", "comp_date"));
        $SerializeableLitCal->LitCal                  = $this->LitCal;
        $SerializeableLitCal->Messages                = $this->Messages;
        $SerializeableLitCal->Settings->YEAR          = $this->LITSETTINGS->YEAR;
        $SerializeableLitCal->Settings->EPIPHANY      = $this->LITSETTINGS->EPIPHANY;
        $SerializeableLitCal->Settings->ASCENSION     = $this->LITSETTINGS->ASCENSION;
        $SerializeableLitCal->Settings->CORPUSCHRISTI = $this->LITSETTINGS->CORPUSCHRISTI;
        $SerializeableLitCal->Settings->LOCALE        = $this->LITSETTINGS->LOCALE;
        $SerializeableLitCal->Settings->RETURNTYPE    = $this->LITSETTINGS->RETURNTYPE;
        if( $this->LITSETTINGS->NATIONAL !== null ){
            $SerializeableLitCal->Settings->NATIONALPRESET = $this->LITSETTINGS->NATIONAL;
        } else {
            //die( 'value of $SerializeableLitCal->Settings->NATIONALPRESET = <' . $this->LITSETTINGS->NATIONAL . '>' );
        }
        if( $this->LITSETTINGS->DIOCESAN !== null ){
            $SerializeableLitCal->Settings->DIOCESANPRESET = $this->LITSETTINGS->DIOCESAN;
        }

        $SerializeableLitCal->Metadata->SOLEMNITIES       = $this->SOLEMNITIES;
        $SerializeableLitCal->Metadata->FEASTS_MEMORIALS  = $this->FEASTS_MEMORIALS;
        $SerializeableLitCal->Metadata->VERSION           = self::API_VERSION;
        $SerializeableLitCal->Metadata->REQUEST_HEADERS   = $this->jsonEncodedRequestHeaders;

        //make sure we have an engineCache folder for the current Version
        if(realpath("engineCache/v" . str_replace(".","_",self::API_VERSION)) === false){
            mkdir("engineCache/v" . str_replace(".","_",self::API_VERSION),0755,true);
        }

        switch ( $this->LITSETTINGS->RETURNTYPE) {
            case RETURN_TYPE::JSON:
                $SerializeableLitCal->Metadata->REQUEST_HEADERS   = $this->REQUEST_HEADERS;
                file_put_contents( $this->CACHEFILE, json_encode($SerializeableLitCal) );
                echo json_encode( $SerializeableLitCal );
                break;
            case RETURN_TYPE::XML:
                $jsonStr = json_encode( $SerializeableLitCal );
                $jsonObj = json_decode( $jsonStr, true );
                $xml = new SimpleXMLElement ( "<?xml version=\"1.0\" encoding=\"UTF-8\"?" . "><LiturgicalCalendar xmlns=\"https://www.bibleget.io/catholicliturgy\"/>" );
                LitCalFf::convertArray2XML( $jsonObj, $xml );
                file_put_contents( $this->CACHEFILE, $xml->asXML() );
                print $xml->asXML();
                break;
            case RETURN_TYPE::ICS:
                $GithubReleasesAPI = "https://api.github.com/repos/JohnRDOrazio/LiturgicalCalendar/releases/latest";
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $GithubReleasesAPI);
                curl_setopt($ch, CURLOPT_USERAGENT, 'LiturgicalCalendar');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $currentVersionForDownload = curl_exec($ch);
                if (curl_errno($ch)) {
                  $error_msg = curl_error($ch);
                  curl_close($ch);
                  echo 'Could not get info about latest release from github: '.$error_msg;
                  exit(0);
                }
                else{
                  curl_close($ch);
                }
                $GitHubReleasesObj = json_decode($currentVersionForDownload);
                if(json_last_error() === JSON_ERROR_NONE){
    
                    $publishDate = $GitHubReleasesObj->published_at;
                    $ical = "BEGIN:VCALENDAR\r\n";
                    $ical .= "PRODID:-//John Romano D'Orazio//Liturgical Calendar V1.0//EN\r\n";
                    $ical .= "VERSION:2.0\r\n";
                    $ical .= "CALSCALE:GREGORIAN\r\n";
                    $ical .= "METHOD:PUBLISH\r\n";
                    $ical .= "X-MS-OLK-FORCEINSPECTOROPEN:FALSE\r\n";
                    $ical .= "X-WR-CALNAME:Roman Catholic Universal Liturgical Calendar " . strtoupper( $this->LITSETTINGS->LOCALE) . "\r\n";
                    $ical .= "X-WR-TIMEZONE:Europe/Vatican\r\n"; //perhaps allow this to be set through a GET or POST?
                    $ical .= "X-PUBLISHED-TTL:PT1D\r\n";
                    foreach($SerializeableLitCal->LitCal as $FestivityKey => $CalEvent){
                        $displayGrade = "";
                        $displayGradeHTML = "";
                        if($FestivityKey === 'AllSouls'){
                            $displayGrade = strip_tags(LITCAL_MESSAGES::__( "COMMEMORATION",$this->LITSETTINGS->LOCALE));
                            $displayGradeHTML = LITCAL_MESSAGES::__( "COMMEMORATION",$this->LITSETTINGS->LOCALE);
                        }
                        else if((int)$CalEvent->date->format('N') !==7 ){
                            if(property_exists($CalEvent,'displayGrade') && $CalEvent->displayGrade !== ""){
                                $displayGrade = $CalEvent->displayGrade;
                                $displayGradeHTML = '<B>' . $CalEvent->displayGrade . '</B>';
                            } else {
                                $displayGrade = LITCAL_MESSAGES::_G( $CalEvent->grade,$this->LITSETTINGS->LOCALE,false);
                                $displayGradeHTML = LITCAL_MESSAGES::_G( $CalEvent->grade,$this->LITSETTINGS->LOCALE,true);
                            }
                        }
                        else if((int)$CalEvent->grade > LitGrade::MEMORIAL ){
                            if(property_exists($CalEvent,'displayGrade') && $CalEvent->displayGrade !== ""){
                                $displayGrade = $CalEvent->displayGrade;
                                $displayGradeHTML = '<B>' . $CalEvent->displayGrade . '</B>';
                            } else {
                                $displayGrade = LITCAL_MESSAGES::_G( $CalEvent->grade,$this->LITSETTINGS->LOCALE,false);
                                $displayGradeHTML = LITCAL_MESSAGES::_G( $CalEvent->grade,$this->LITSETTINGS->LOCALE,true);
                            }
                        }
    
                        $description = LITCAL_MESSAGES::_C( $CalEvent->common,$this->LITSETTINGS->LOCALE);
                        $description .=  '\n' . $displayGrade;
                        $description .= $CalEvent->color != "" ? '\n' . ParseColorString($CalEvent->color,$this->LITSETTINGS->LOCALE,false) : "";
                        $description .= property_exists($CalEvent,'liturgicalyear') && $CalEvent->liturgicalyear !== null && $CalEvent->liturgicalyear != "" ? '\n' . $CalEvent->liturgicalyear : "";
                        $htmlDescription = "<P DIR=LTR>" . LITCAL_MESSAGES::_C( $CalEvent->common,$this->LITSETTINGS->LOCALE);
                        $htmlDescription .=  '<BR>' . $displayGradeHTML;
                        $htmlDescription .= $CalEvent->color != "" ? "<BR>" . ParseColorString($CalEvent->color,$this->LITSETTINGS->LOCALE,true) : "";
                        $htmlDescription .= property_exists($CalEvent,'liturgicalyear') && $CalEvent->liturgicalyear !== null && $CalEvent->liturgicalyear != "" ? '<BR>' . $CalEvent->liturgicalyear . "</P>" : "</P>";
                        $ical .= "BEGIN:VEVENT\r\n";
                        $ical .= "CLASS:PUBLIC\r\n";
                        $ical .= "DTSTART;VALUE=DATE:" . $CalEvent->date->format('Ymd') . "\r\n";// . "T" . $CalEvent->date->format('His') . "Z\r\n";
                        //$CalEvent->date->add(new DateInterval('P1D'));
                        //$ical .= "DTEND:" . $CalEvent->date->format('Ymd') . "T" . $CalEvent->date->format('His') . "Z\r\n";
                        $ical .= "DTSTAMP:" . date('Ymd') . "T" . date('His') . "Z\r\n";
                        /** The event created in the calendar is specific to this year, next year it may be different.
                         *  So UID must take into account the year
                         *  Next year's event should not cancel this year's event, they are different events
                         **/
                        $ical .= "UID:" . md5("LITCAL-" . $FestivityKey . '-' . $CalEvent->date->format('Y')) . "\r\n";
                        $ical .= "CREATED:" . str_replace(':' , '', str_replace('-', '', $publishDate)) . "\r\n";
                        $desc = "DESCRIPTION:" . str_replace(',','\,',$description);
                        $ical .= strlen($desc) > 75 ? rtrim(utf8_encode(chunk_split(utf8_decode($desc),71,"\r\n\t"))) . "\r\n" : "$desc\r\n";
                        $ical .= "LAST-MODIFIED:" . str_replace(':' , '', str_replace('-', '', $publishDate)) . "\r\n";
                        $summaryLang = ";LANGUAGE=" . strtolower( $this->LITSETTINGS->LOCALE); //strtolower( $this->LITSETTINGS->LOCALE) === "la" ? "" :
                        $summary = "SUMMARY".$summaryLang.":" . str_replace(',','\,',str_replace("\r\n"," ",$CalEvent->name));
                        $ical .= strlen($summary) > 75 ? rtrim(utf8_encode(chunk_split(utf8_decode($summary),75,"\r\n\t"))) . "\r\n" : $summary . "\r\n";
                        $ical .= "TRANSP:TRANSPARENT\r\n";
                        $ical .= "X-MICROSOFT-CDO-ALLDAYEVENT:TRUE\r\n";
                        $ical .= "X-MICROSOFT-DISALLOW-COUNTER:TRUE\r\n";
                        $xAltDesc = 'X-ALT-DESC;FMTTYPE=text/html:<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 3.2//EN">\n<HTML>\n<BODY>\n\n';
                        $xAltDesc .= str_replace(',','\,',$htmlDescription);
                        $xAltDesc .= '\n\n</BODY>\n</HTML>';
                        $ical .= strlen($xAltDesc) > 75 ? rtrim(utf8_encode(chunk_split(utf8_decode($xAltDesc),71,"\r\n\t"))) . "\r\n" : "$xAltDesc\r\n";
                        $ical .= "END:VEVENT\r\n";
                    }
                    $ical .= "END:VCALENDAR";
                    file_put_contents( $this->CACHEFILE, $ical );
    
                    echo $ical;
                }
                else{
                    echo 'Could not parse info received from github about latest release: '.json_last_error_msg();
                    exit(0);
                }
                break;
            default:
                file_put_contents( $this->CACHEFILE, json_encode($SerializeableLitCal) );
                echo json_encode($SerializeableLitCal);
                break;
        }
        die();
    }


    public function setCacheDuration( string $duration ) : void {
        switch( $duration ) {
            case CACHEDURATION::DAY:
                $this->CACHEDURATION = "_" . $duration . date("z"); //The day of the year (starting from 0 through 365)
                break;
            case CACHEDURATION::WEEK:
                $this->CACHEDURATION = "_" . $duration . date("W"); //ISO-8601 week number of year, weeks starting on Monday
                break;
            case CACHEDURATION::MONTH:
                $this->CACHEDURATION = "_" . $duration . date("m"); //Numeric representation of a month, with leading zeros
                break;
            case CACHEDURATION::YEAR:
                $this->CACHEDURATION = "_" . $duration . date("Y"); //A full numeric representation of a year, 4 digits
                break;
        }
    }

    public function setAllowedOrigins( array $origins ) : void {
        $this->ALLOWED_ORIGINS = $origins;
    }

    public function setAllowedAcceptHeaders( array $acceptHeaders ) : void {
        $this->ALLOWED_ACCEPT_HEADERS = array_values( array_intersect( ACCEPT_HEADER::$values, $acceptHeaders ) );
    }

    public function setAllowedParameterReturnTypes( array $returnTypes ) : void {
        $this->ALLOWED_RETURN_TYPES = array_values( array_intersect( RETURN_TYPE::$values, $returnTypes ) );
    }

    public function setAllowedRequestMethods( array $requestMethods ) : void {
        $this->ALLOWED_REQUEST_METHODS = array_values( array_intersect( REQUEST_METHOD::$values, $requestMethods ) );
    }

    public function setAllowedRequestContentTypes( array $requestContentTypes ) : void {
        $this->ALLOWED_REQUEST_CONTENT_TYPES = array_values( array_intersect( REQUEST_CONTENT_TYPE::$values, $requestContentTypes ) );
    }

    /**
     * The LitCalEngine will only work once you call the public Init() method
     * Do not change the order of the methods that follow,
     * each one can depend on the one before it in order to function correctly!
     */
    public function Init(){
        $this->setAllowedOriginHeader();
        $this->setAccessControlAllowMethods();
        $this->validateRequestContentType();
        $this->initParameterData();
        $this->setReponseContentTypeHeader();
        $this->loadLocalCalendarData();
        if( $this->cacheFileIsAvailable() ){
            //If we already have done the calculation
            //and stored the results in a cache file
            //then we're done, just output this and die
            echo file_get_contents( $this->CACHEFILE );
            die();
        } else {
            $this->initiateDbConnection();
            $this->createNumberFormatters();
            $this->dieIfBeforeMinYear();
            $this->retrieveHigherSolemnityTranslations();
            /**
             *  CALCULATE LITURGICAL EVENTS BASED ON THE ORDER OF PRECEDENCE OF LITURGICAL DAYS (LY 59)
             *  General Norms for the Liturgical Year and the Calendar (issued on Feb. 14 1969)
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
            //a) feast of the principal patron of the Diocese - for pastoral reasons can be celebrated as a solemnity (PC 8, 9)
            //b) feast of the anniversary of the Dedication of the cathedral church
            //c) feast of the principal Patron of the region or province, of a nation or a wider territory - for pastoral reasons can be celebrated as a solemnity (PC 8, 9)
            //d) feast of the titular, of the founder, of the principal patron of an Order or Congregation and of the religious province, without prejudice to the prescriptions of n. 4 d
            //e) other feasts proper to an individual church
            //f) other feasts inscribed in the calendar of a diocese or of a religious order or congregation
            //these will be dealt with later when loading Local Calendar Data

            //9. WEEKDAYS of ADVENT FROM 17 DECEMBER TO 24 DECEMBER INCLUSIVE
            $this->calculateWeekdaysAdvent();
            //WEEKDAYS of the Octave of Christmas
            $this->calculateWeekdaysChristmasOctave();
            //WEEKDAYS of LENT
            $this->calculateWeekdaysLent();
            //III.
            //10. Obligatory memorials in the General Calendar
            $this->calculateMemorials();

            if ( $this->LITSETTINGS->YEAR >= 1998 ) {
                //St Therese of the Child Jesus was proclaimed a Doctor of the Church in 1998
                $this->applyDoctorDecree1998();
            }

            if ( $this->LITSETTINGS->YEAR >= 2002 ) {
                $this->applyMemorialsTertiaEditioTypica2002();
            }

            if( $this->LITSETTINGS->YEAR >= 2008 ) {
                $this->applyMemorialsTertiaEditioTypicaEmendata2008();
            }

            if ( $this->LITSETTINGS->YEAR >= 2016 ) {
                //Memorial of Saint Mary Magdalen elevated to a Feast
                $this->applyFeastDecree2016();
            }

            if( $this->LITSETTINGS->YEAR >= 2018 ) {
                //Memorial of the Blessed Virgin Mary, Mother of the Church added on the Monday after Pentecost
                $this->applyMemorialDecree2018();
            }

            if( $this->LITSETTINGS->YEAR >= 2021 ) {
                //Memorial of St Martha becomes Martha, Mary and Lazarus
                $this->applyMemorialDecree2021();
            }

            //11. Proper obligatory memorials, and that is:
            //a) obligatory memorial of the seconday Patron of a place, of a diocese, of a region or religious province
            //b) other obligatory memorials in the calendar of a single diocese, order or congregation
            //these will be dealt with later when loading Local Calendar Data

            //12. Optional memorials (a proper memorial is to be preferred to a general optional memorial (PC, 23 c) )
            //  which however can be celebrated even in those days listed at n. 9,
            //  in the special manner described by the General Instructions of the Roman Missal and of the Liturgy of the Hours (cf pp. 26-27, n. 10)

            $this->calculateOptionalMemorials();

            if ( $this->LITSETTINGS->YEAR >= 2002 ) {
                $this->applyOptionalMemorialsTertiaEditioTypica2002();
            }

            if ( $this->LITSETTINGS->YEAR >= 2008) {
                $this->applyOptionalMemorialsTertiaEditioTypicaEmendata2008();
            }

            if( $this->LITSETTINGS->YEAR === 2009 ) {
                //Conversion of St. Paul falls on a Sunday in the year 2009
                //Faculty to celebrate as optional memorial
                $this->applyOptionalMemorialDecree2009();
            }

            if( $this->LITSETTINGS->YEAR >= 2014 ) {
                //canonization of Pope Saint John XXIII and Pope Saint John Paul II
                $this->applyOptionalMemorialDecree2014();
            }

            if( $this->LITSETTINGS->YEAR >= 2019 ) {
                //optional memorial of the Blessed Virgin Mary of Loreto
                //and optional memorial Saint Paul VI, Pope
                $this->applyOptionalMemorialDecree2019();
            }

            if( $this->LITSETTINGS->YEAR >= 2020 ){
                //optional memorial Saint Faustina
                $this->applyOptionalMemorialDecree2020();
            }

            if( $this->LITSETTINGS->YEAR >= 2021 ){
                //optional memorials of Gregory of Narek, John of Avila and Hildegard of Bingen
                $this->applyOptionalMemorialDecree2021();
            }

            //13. Weekdays of Advent up until Dec. 16 included (already calculated and defined together with weekdays 17 Dec. - 24 Dec.)
            //    Weekdays of Christmas season from 2 Jan. until the Saturday after Epiphany
            //    Weekdays of the Easter season, from the Monday after the Octave of Easter to the Saturday before Pentecost
            //    Weekdays of Ordinary time
            $this->calculateWeekdaysMajorSeasons();
            $this->calculateWeekdaysOrdinaryTime();

            //15. On Saturdays in Ordinary Time when there is no obligatory memorial, an optional memorial of the Blessed Virgin Mary is allowed.
            $this->calculateSaturdayMemorialBVM();

            //APPLY NATIONAL CALENDARS IF REQUESTED
            if( $this->LITSETTINGS->NATIONAL !== null ) {
                switch($this->LITSETTINGS->NATIONAL){
                    case 'ITALY':
                        $this->applyCalendarItaly();
                        break;
                    case 'USA':
                        //I don't have any data before 2011. Are there any official printed Missals before that?
                        if( $this->LITSETTINGS->YEAR >= 2011 ) {
                            $this->applyCalendarUSA();
                        }
                        break;
                }
            }

            if( $this->LITSETTINGS->DIOCESAN !== null && $this->DiocesanData !== null ) {

                foreach( $this->DiocesanData->LitCal as $key => $obj ) {
                    if( is_array( $obj->color ) ) {
                        $obj->color = implode( ',', $obj->color );
                    }
                    //if sinceYear is undefined or null or empty, let's go ahead and create the event in any case
                    //creation will be restricted only if explicitly defined by the sinceYear property
                    if( $this->LITSETTINGS->YEAR >= $obj->sinceYear || $obj->sinceYear === null || $obj->sinceYear == '' ) {
                        $currentFeastDate = DateTime::createFromFormat( '!j-n-Y', $obj->day . '-' . $obj->month . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone('UTC') );
                        if( $obj->grade > LitGrade::FEAST ) {
                            $this->LitCal[ $this->LITSETTINGS->DIOCESAN . "_" . $key ] = new Festivity( "[" . $this->GeneralIndex->{$this->LITSETTINGS->DIOCESAN}->diocese . "] " . $obj->name, $currentFeastDate, strtolower( $obj->color ), "fixed", $obj->grade, $obj->common );
                            if( in_array ($currentFeastDate, $this->SOLEMNITIES ) && $key != array_search( $currentFeastDate, $this->SOLEMNITIES ) ) {
                                //there seems to be a coincidence with a different Solemnity on the same day!
                                //should we attempt to move to the next open slot?
                                $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                    $this->LITSETTINGS->DIOCESAN . ": the Solemnity '%s', proper to the calendar of the " . $this->GeneralIndex->{$this->LITSETTINGS->DIOCESAN}->diocese . " and usually celebrated on %s, coincides with the Sunday or Solemnity '%s' in the year %d! Does something need to be done about this?",
                                    '<i>' . $obj->name . '</i>',
                                    '<b>' . trim( utf8_encode( strftime( '%e %B', $currentFeastDate->format('U') ) ) ) . '</b>',
                                    '<i>' . $this->LitCal[ array_search( $currentFeastDate, $this->SOLEMNITIES ) ]->name . '</i>',
                                    $this->LITSETTINGS->YEAR
                                );
                            }
                        } else if ( $obj->grade <= LitGrade::FEAST && !in_array( $currentFeastDate, $this->SOLEMNITIES ) ){
                            $this->LitCal[ $this->LITSETTINGS->DIOCESAN . "_" . $key ] = new Festivity( "[" . $this->GeneralIndex->{$this->LITSETTINGS->DIOCESAN}->diocese . "] " . $obj->name, $currentFeastDate, strtolower( $obj->color ), "fixed", $obj->grade, $obj->common );
                        } else {
                            $this->Messages[] = sprintf(
                                $this->LITSETTINGS->DIOCESAN . ": the %s '%s', proper to the calendar of the " . $this->GeneralIndex->{$this->LITSETTINGS->DIOCESAN}->diocese . " and usually celebrated on %s, is suppressed by the Sunday or Solemnity %s in the year %d",
                                LITCAL_MESSAGES::_G(  $obj->grade,$this->LITSETTINGS->LOCALE, false ),
                                '<i>' . $obj->name . '</i>',
                                '<b>' . trim( utf8_encode( strftime( '%e %B', $currentFeastDate->format('U') ) ) ) . '</b>',
                                '<i>' . $this->LitCal[ array_search( $currentFeastDate, $this->SOLEMNITIES ) ]->name . '</i>',
                                $this->LITSETTINGS->YEAR
                            );
                        }
                    }
                }

            }

            //Set Weekly (YEAR A, B, C) and Daily (YEAR I, II) cycles for each event created
            //Set Vigil Masses if applicable
            $this->setCyclesAndVigils();
            $this->generateResponse();
        }
    }

}
