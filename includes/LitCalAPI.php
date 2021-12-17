<?php

include_once( 'includes/enums/AcceptHeader.php' );
include_once( 'includes/enums/CacheDuration.php' );
include_once( 'includes/enums/LitColor.php' );
include_once( 'includes/enums/LitCommon.php' );
include_once( 'includes/enums/LitFeastType.php' );
include_once( 'includes/enums/LitGrade.php' );
include_once( 'includes/enums/RequestMethod.php' );
include_once( 'includes/enums/RequestContentType.php' );
include_once( 'includes/enums/ReturnType.php' );
include_once( 'includes/enums/RomanMissal.php' );

include_once( 'includes/APICore.php' );
include_once( "includes/Festivity.php" );
include_once( "includes/FestivityCollection.php" );
include_once( "includes/LitSettings.php" );
include_once( "includes/LitCalFunctions.php" );
include_once( "includes/LitCalMessages.php" );

class LitCalAPI {

    const API_VERSION                               = '3.0';
    public APICore $APICore;

    private string $CACHEDURATION                   = "";
    private string $CACHEFILE                       = "";
    private array $ALLOWED_RETURN_TYPES;
    private LITSETTINGS $LITSETTINGS;
    private LitCommon $LitCommon;

    private ?object $DiocesanData                   = null;
    private ?object $GeneralIndex                   = null;
    private NumberFormatter $formatter;
    private NumberFormatter $formatterFem;
    private IntlDateFormatter $dayAndMonth;
    private IntlDateFormatter $dayOfTheWeek;

    private array $PROPRIUM_DE_TEMPORE              = [];
    private array $Messages                         = [];
    private FestivityCollection $Cal;
    private array $tempCal                          = [];
    private string $BaptismLordFmt;
    private string $BaptismLordMod;

    public function __construct(){
        $this->APICore                              = new APICore();
        $this->CACHEDURATION                        = "_" . CACHEDURATION::MONTH . date( "m" );
    }

    private function initParameterData() {
        if ( $this->APICore->getRequestContentType() === REQUEST_CONTENT_TYPE::JSON ) {
            $json = file_get_contents( 'php://input' );
            $data = json_decode( $json, true );
            if( NULL === $json || "" === $json ){
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad Request", true, 400 );
                die( '{"error":"No JSON data received in the request: <' . $json . '>"' );
            } else if ( json_last_error() !== JSON_ERROR_NONE ) {
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad Request", true, 400 );
                die( '{"error":"Malformed JSON data received in the request: <' . $json . '>, ' . json_last_error_msg() . '"}' );
            } else {
                $this->LITSETTINGS = new LITSETTINGS( $data );
            }
        } else {
            switch( $this->APICore->getRequestMethod() ) {
                case 'POST':
                    $this->LITSETTINGS = new LITSETTINGS( $_POST );
                    break;
                case 'GET':
                    $this->LITSETTINGS = new LITSETTINGS( $_GET );
                    break;
                default:
                    header( $_SERVER[ "SERVER_PROTOCOL" ]." 405 Method Not Allowed", true, 405 );
                    $errorMessage = '{"error":"You seem to be forming a strange kind of request? Allowed Request Methods are ';
                    $errorMessage .= implode( ' and ', $this->APICore->getAllowedRequestMethods() );
                    $errorMessage .= ', but your Request Method was ' . $this->APICore->getRequestMethod() . '"}';
                    die( $errorMessage );
            }
        }
        if( $this->LITSETTINGS->RETURNTYPE !== null ) {
            if( in_array( $this->LITSETTINGS->RETURNTYPE, $this->ALLOWED_RETURN_TYPES ) ) {
                $this->APICore->setResponseContentType( $this->APICore->getAllowedAcceptHeaders()[ array_search( $this->LITSETTINGS->RETURNTYPE, $this->ALLOWED_RETURN_TYPES ) ] );
            } else {
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 406 Not Acceptable", true, 406 );
                $errorMessage = '{"error":"You are requesting a content type which this API cannot produce. Allowed content types are ';
                $errorMessage .= implode( ' and ', $this->ALLOWED_RETURN_TYPES );
                $errorMessage .= ', but you have issued a parameter requesting a Content Type of ' . strtoupper( $this->LITSETTINGS->RETURNTYPE ) . '"}';
                die( $errorMessage );
            }
        } else {
            if( $this->APICore->hasAcceptHeader() ) {
                if( $this->APICore->isAllowedAcceptHeader() ) {
                    $this->LITSETTINGS->RETURNTYPE = $this->ALLOWED_RETURN_TYPES[ $this->APICore->getIdxAcceptHeaderInAllowed() ];
                    $this->APICore->setResponseContentType( $this->APICore->getAcceptHeader() );
                } else {
                    //Requests from browser windows using the address bar will probably have an Accept header of text/html
                    //In order to not be too drastic, let's treat text/html as though it were application/json
                    $acceptHeaders = explode( ",", $this->APICore->getAcceptHeader() );
                    if( in_array( 'text/html', $acceptHeaders ) || in_array( 'text/plain', $acceptHeaders ) || in_array( '*/*', $acceptHeaders ) ) {
                        $this->LITSETTINGS->RETURNTYPE = RETURN_TYPE::JSON;
                        $this->APICore->setResponseContentType( ACCEPT_HEADER::JSON );
                    } else {
                        header( $_SERVER[ "SERVER_PROTOCOL" ]." 406 Not Acceptable", true, 406 );
                        $errorMessage = '{"error":"You are requesting a content type which this API cannot produce. Allowed Accept headers are ';
                        $errorMessage .= implode( ' and ', $this->APICore->getAllowedAcceptHeaders() );
                        $errorMessage .= ', but you have issued an request with an Accept header of ' . $this->APICore->getAcceptHeader() . '"}';
                        die( $errorMessage );
                    }

                }
            } else {
                $this->LITSETTINGS->RETURNTYPE = $this->ALLOWED_RETURN_TYPES[ 0 ];
                $this->APICore->setResponseContentType( $this->APICore->getAllowedAcceptHeaders()[ 0 ] );
            }
        }
    }


    private function loadLocalCalendarData() : void {
        if( $this->LITSETTINGS->DIOCESAN !== null ){
            //since a Diocesan calendar is being requested, we need to retrieve the JSON data
            //first we need to discover the path, so let's retrieve our index file
            if( file_exists( "nations/index.json" ) ){
                $this->GeneralIndex = json_decode( file_get_contents( "nations/index.json" ) );
                if( property_exists( $this->GeneralIndex, $this->LITSETTINGS->DIOCESAN ) ){
                    $diocesanDataFile = $this->GeneralIndex->{$this->LITSETTINGS->DIOCESAN}->path;
                    $this->LITSETTINGS->NATIONAL = $this->GeneralIndex->{$this->LITSETTINGS->DIOCESAN}->nation;
                    if( file_exists( $diocesanDataFile ) ){
                        $this->DiocesanData = json_decode( file_get_contents( $diocesanDataFile ) );
                    }
                }
            }
        }

        if( $this->LITSETTINGS->NATIONAL !== null ){
            switch( $this->LITSETTINGS->NATIONAL ){
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

    private function cacheFileIsAvailable() : bool {
        $cacheFilePath = "engineCache/v" . str_replace( ".", "_", self::API_VERSION ) . "/";
        $cacheFileName = md5( serialize( $this->LITSETTINGS) ) . $this->CACHEDURATION . "." . strtolower( $this->LITSETTINGS->RETURNTYPE );
        $this->CACHEFILE = $cacheFilePath . $cacheFileName;
        return file_exists( $this->CACHEFILE );
    }

    private function createFormatters() : void {
        //ini_set( 'intl.default_locale', strtolower( $this->LITSETTINGS->LOCALE ) . '_' . $this->LITSETTINGS->LOCALE );
        $this->dayAndMonth = IntlDateFormatter::create( strtolower( $this->LITSETTINGS->LOCALE ), IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, "d MMMM" );
        $this->dayOfTheWeek  = IntlDateFormatter::create( strtolower( $this->LITSETTINGS->LOCALE ), IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, "EEEE" );
        $this->formatter = new NumberFormatter( strtolower( $this->LITSETTINGS->LOCALE ), NumberFormatter::SPELLOUT );
        switch( $this->LITSETTINGS->LOCALE ){
            case 'EN':
                $this->formatter->setTextAttribute( NumberFormatter::DEFAULT_RULESET, "%spellout-ordinal" );
                $this->formatterFem = $this->formatter;
            break;
            default:
                $this->formatter->setTextAttribute( NumberFormatter::DEFAULT_RULESET, "%spellout-ordinal-masculine" );
                $this->formatterFem = new NumberFormatter( strtolower( $this->LITSETTINGS->LOCALE ), NumberFormatter::SPELLOUT );
                $this->formatterFem->setTextAttribute( NumberFormatter::DEFAULT_RULESET, "%spellout-ordinal-feminine" );
        }
    }

    private function dieIfBeforeMinYear() : void {
        //for the time being, we cannot accept a year any earlier than 1970, since this engine is based on the liturgical reform from Vatican II
        //with the Prima Editio Typica of the Roman Missal and the General Norms promulgated with the Motu Proprio "Mysterii Paschali" in 1969
        if ( $this->LITSETTINGS->YEAR < 1970 ) {
            $this->Messages[] = sprintf( _( "Only years from 1970 and after are supported. You tried requesting the year %d." ), $this->LITSETTINGS->YEAR );
            $this->GenerateResponseToRequest();
        }
    }

    /**
     * Retrieve Higher Ranking Solemnities from Proprium de Tempore
     */
    private function populatePropriumDeTempore() : void {
        $propriumdetemporeFile = strtolower( "data/propriumdetempore/{$this->LITSETTINGS->LOCALE}.json" );
        if( file_exists( $propriumdetemporeFile ) ) {
            $PropriumDeTempore = json_decode( file_get_contents( $propriumdetemporeFile ), true );
            if( json_last_error() === JSON_ERROR_NONE ){
                foreach( $PropriumDeTempore as $key => $event ) {
                    $this->PROPRIUM_DE_TEMPORE[ $key ] = [ "NAME_" . $this->LITSETTINGS->LOCALE => $event ];
                }
            } else {
                die( '{"ERROR": "There was an error trying to retrieve and decode JSON data for the Proprium de Tempore. ' . json_last_error_msg() . '"}' );
            }
        }
    }

    private function readPropriumDeSanctisJSONData( string $missal ) {
        $propriumdesanctisFile = ROMANMISSAL::getSanctoraleFileName( $missal );
        $propriumdesanctisI18nPath = ROMANMISSAL::getSanctoraleI18nFilePath( $missal );

        if( $propriumdesanctisI18nPath !== false ) {
            $propriumdesanctisI18nFile = $propriumdesanctisI18nPath . strtolower( $this->LITSETTINGS->LOCALE ) . ".json";
            if( file_exists( $propriumdesanctisI18nFile ) ) {
                $NAME = json_decode( file_get_contents( $propriumdesanctisI18nFile ), true );
                if( json_last_error() !== JSON_ERROR_NONE ) {
                    die( '{"ERROR": "There was an error trying to retrieve and decode JSON i18n data for the Proprium de Sanctis for the Missal ' . ROMANMISSAL::getName( $missal ) . ': ' . json_last_error_msg() . '"}' );
                }
            }
        }

        if( file_exists( $propriumdesanctisFile ) ) {
            $PropriumDeSanctis = json_decode( file_get_contents( $propriumdesanctisFile ) );
            if( json_last_error() === JSON_ERROR_NONE ){
                $this->tempCal[ $missal ] = [];
                foreach( $PropriumDeSanctis as $row ) {
                    if( $propriumdesanctisI18nPath !== false && $NAME !== null ) {
                        $row->NAME = $NAME[ $row->TAG ];
                    }
                    $this->tempCal[ $missal ][ $row->TAG ] = $row;
                }
            } else {
                die( '{"ERROR": "There was an error trying to retrieve and decode JSON data for the Proprium de Sanctis for the Missal ' . ROMANMISSAL::getName( $missal ) . ': ' . json_last_error_msg() . '"}' );
            }
        }
    }

    private function calculateEasterTriduum() : void {
        $HolyThurs        = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "HolyThurs" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],    LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->sub( new DateInterval( 'P3D' ) ), LitColor::WHITE, LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $GoodFri          = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "GoodFri" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->sub( new DateInterval( 'P2D' ) ), LitColor::RED,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $EasterVigil      = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "EasterVigil" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],  LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->sub( new DateInterval( 'P1D' ) ), LitColor::WHITE, LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $Easter           = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Easter" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],       LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR ),                               LitColor::WHITE, LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );

        $this->Cal->addFestivity( "HolyThurs",      $HolyThurs );
        $this->Cal->addFestivity( "GoodFri",        $GoodFri );
        $this->Cal->addFestivity( "EasterVigil",    $EasterVigil );
        $this->Cal->addFestivity( "Easter",         $Easter );
    }

    private function calculateChristmasEpiphany() : void {
        $Christmas = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Christmas" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],    DateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) ), LitColor::WHITE, LitFeastType::FIXED,  LitGrade::HIGHER_SOLEMNITY );
        $this->Cal->addFestivity( "Christmas", $Christmas );

        if ( $this->LITSETTINGS->EPIPHANY === EPIPHANY::JAN6 ) {

            $Epiphany     = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Epiphany" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],     DateTime::createFromFormat( '!j-n-Y', '6-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) ),  LitColor::WHITE, LitFeastType::FIXED,  LitGrade::HIGHER_SOLEMNITY );
            $this->Cal->addFestivity( "Epiphany",   $Epiphany );
            //If a Sunday occurs on a day from Jan. 2 through Jan. 5, it is called the "Second Sunday of Christmas"
            //Weekdays from Jan. 2 through Jan. 5 are called "*day before Epiphany"
            $nth = 0;
            for ( $i = 2; $i <= 5; $i++ ) {
                $dateTime = DateTime::createFromFormat( '!j-n-Y', $i . '-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
                if ( self::DateIsSunday( $dateTime ) ) {
                    $Christmas2 = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Christmas2" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], $dateTime, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::FEAST_LORD );
                    $this->Cal->addFestivity( "Christmas2", $Christmas2 );
                } else {
                    $nth++;
                    $nthStr = $this->LITSETTINGS->LOCALE === 'LA' ? LITCAL_MESSAGES::LATIN_ORDINAL[ $nth ] : $this->formatter->format( $nth );
                    $name = $this->LITSETTINGS->LOCALE === 'LA' ? sprintf( "Dies %s ante Epiphaniam", $nthStr ) : sprintf( _( "%s day before Epiphany" ), ucfirst( $nthStr ) );
                    $festivity = new Festivity( $name, $dateTime, LitColor::WHITE, LitFeastType::MOBILE );
                    $this->Cal->addFestivity( "DayBeforeEpiphany" . $nth, $festivity );
                }
            }

            //Weekdays from Jan. 7 until the following Sunday are called "*day after Epiphany"
            $SundayAfterEpiphany = (int)DateTime::createFromFormat( '!j-n-Y', '6-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) )->modify( 'next Sunday' )->format( 'j' );
            if ( $SundayAfterEpiphany !== 7 ) { //this means January 7th, it does not refer to the day of the week which is obviously Sunday in this case
                $nth = 0;
                for ( $i = 7; $i < $SundayAfterEpiphany; $i++ ) {
                    $nth++;
                    $nthStr = $this->LITSETTINGS->LOCALE === 'LA' ? LITCAL_MESSAGES::LATIN_ORDINAL[ $nth ] : $this->formatter->format( $nth );
                    $dateTime = DateTime::createFromFormat( '!j-n-Y', $i . '-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
                    $name = $this->LITSETTINGS->LOCALE === 'LA' ? sprintf( "Dies %s post Epiphaniam", $nthStr ) : sprintf( _( "%s day after Epiphany" ), ucfirst( $nthStr ) );
                    $festivity = new Festivity( $name, $dateTime, LitColor::WHITE, LitFeastType::MOBILE );
                    $this->Cal->addFestivity( "DayAfterEpiphany" . $nth, $festivity );
                }
            }
        } else if ( $this->LITSETTINGS->EPIPHANY === EPIPHANY::SUNDAY_JAN2_JAN8 ) {
            //If January 2nd is a Sunday, then go with Jan 2nd
            $dateTime = DateTime::createFromFormat( '!j-n-Y', '2-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
            if ( self::DateIsSunday( $dateTime ) ) {
                $Epiphany = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Epiphany" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], $dateTime, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
                $this->Cal->addFestivity( "Epiphany",   $Epiphany );
            }
            //otherwise find the Sunday following Jan 2nd
            else {
                $SundayOfEpiphany = $dateTime->modify( 'next Sunday' );
                $Epiphany = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Epiphany" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], $SundayOfEpiphany, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
                $this->Cal->addFestivity( "Epiphany",   $Epiphany );
                //Weekdays from Jan. 2 until the following Sunday are called "*day before Epiphany"
                $DayOfEpiphany = (int)$SundayOfEpiphany->format( 'j' );
                $nth = 0;
                for ( $i = 2; $i < $DayOfEpiphany; $i++ ) {
                    $nth++;
                    $nthStr = $this->LITSETTINGS->LOCALE === 'LA' ? LITCAL_MESSAGES::LATIN_ORDINAL[ $nth ] : $this->formatter->format( $nth );
                    $name = $this->LITSETTINGS->LOCALE === 'LA' ? sprintf( "Dies %s ante Epiphaniam", $nthStr ) : sprintf( _( "%s day before Epiphany" ), ucfirst( $nthStr ) );
                    $dateTime = DateTime::createFromFormat( '!j-n-Y', $i . '-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
                    $festivity = new Festivity( $name, $dateTime, LitColor::WHITE, LitFeastType::MOBILE );
                    $this->Cal->addFestivity( "DayBeforeEpiphany" . $nth, $festivity );
                }

                //If Epiphany occurs on or before Jan. 6, then the days of the week following Epiphany are called "*day after Epiphany" and the Sunday following Epiphany is the Baptism of the Lord.
                if ( $DayOfEpiphany < 7 ) {
                    $SundayAfterEpiphany =  (int)DateTime::createFromFormat( '!j-n-Y', '2-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) )->modify( 'next Sunday' )->modify( 'next Sunday' )->format( 'j' );
                    $nth = 0;
                    for ( $i = $DayOfEpiphany + 1; $i < $SundayAfterEpiphany; $i++ ) {
                        $nth++;
                        $nthStr = $this->LITSETTINGS->LOCALE === 'LA' ? LITCAL_MESSAGES::LATIN_ORDINAL[ $nth ] : $this->formatter->format( $nth );
                        $name = $this->LITSETTINGS->LOCALE === 'LA' ? sprintf( "Dies %s post Epiphaniam", $nthStr ) : sprintf( _( "%s day after Epiphany" ), ucfirst( $nthStr ) );
                        $dateTime = DateTime::createFromFormat( '!j-n-Y', $i . '-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
                        $festivity = new Festivity( $name, $dateTime, LitColor::WHITE, LitFeastType::MOBILE );
                        $this->Cal->addFestivity( "DayAfterEpiphany" . $nth, $festivity );
                    }
                }
            }
        }

    }

    private function calculateAscensionPentecost() : void {

        if ( $this->LITSETTINGS->ASCENSION === ASCENSION::THURSDAY ) {
            $Ascension = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Ascension" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],  LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P39D' ) ),                LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
            $Easter7 = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Easter7" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P' . ( 7 * 6 ) . 'D' ) ), LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
            $this->Cal->addFestivity( "Easter7", $Easter7 );
        } else if ( $this->LITSETTINGS->ASCENSION === "SUNDAY" ) {
            $Ascension = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Ascension" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],  LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P' . ( 7 * 6 ) . 'D' ) ), LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        }
        $this->Cal->addFestivity( "Ascension", $Ascension );

        $Pentecost = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Pentecost" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P' . ( 7 * 7 ) . 'D' ) ), LitColor::RED,      LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $this->Cal->addFestivity( "Pentecost", $Pentecost );

    }

    private function calculateSundaysMajorSeasons() : void {
        $this->Cal->addFestivity( "Advent1",    new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Advent1" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],      DateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' )->sub( new DateInterval( 'P' . ( 3 * 7 ) . 'D' ) ), LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Advent2",    new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Advent2" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],      DateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' )->sub( new DateInterval( 'P' . ( 2 * 7 ) . 'D' ) ), LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Advent3",    new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Advent3" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],      DateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' )->sub( new DateInterval( 'P7D' ) ),                 LitColor::PINK,     LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Advent4",    new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Advent4" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],      DateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' ),                                                   LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Lent1",      new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Lent1" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],        LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->sub( new DateInterval( 'P' . ( 6 * 7 ) . 'D' ) ),    LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Lent2",      new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Lent2" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],        LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->sub( new DateInterval( 'P' . ( 5 * 7 ) . 'D' ) ),    LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Lent3",      new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Lent3" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],        LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->sub( new DateInterval( 'P' . ( 4 * 7 ) . 'D' ) ),    LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Lent4",      new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Lent4" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],        LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->sub( new DateInterval( 'P' . ( 3 * 7 ) . 'D' ) ),    LitColor::PINK,     LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Lent5",      new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Lent5" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],        LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->sub( new DateInterval( 'P' . ( 2 * 7 ) . 'D' ) ),    LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "PalmSun",    new Festivity( $this->PROPRIUM_DE_TEMPORE[ "PalmSun" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->sub( new DateInterval( 'P7D' ) ),                    LitColor::RED,      LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Easter2",    new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Easter2" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P7D' ) ),                    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Easter3",    new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Easter3" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P' . ( 7 * 2 ) . 'D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Easter4",    new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Easter4" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P' . ( 7 * 3 ) . 'D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Easter5",    new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Easter5" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P' . ( 7 * 4 ) . 'D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Easter6",    new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Easter6" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P' . ( 7 * 5 ) . 'D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Trinity",    new Festivity( $this->PROPRIUM_DE_TEMPORE[ "Trinity" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],      LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P' . ( 7 * 8 ) . 'D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        if ( $this->LITSETTINGS->CORPUSCHRISTI === CORPUSCHRISTI::THURSDAY ) {
            $CorpusChristi = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "CorpusChristi" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P' . ( 7 * 8 + 4 ) . 'D' ) ),  LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
            //Seeing the Sunday is not taken by Corpus Christi, it should be later taken by a Sunday of Ordinary Time (they are calculate back to Pentecost)
        } else if ( $this->LITSETTINGS->CORPUSCHRISTI === CORPUSCHRISTI::SUNDAY ) {
            $CorpusChristi = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "CorpusChristi" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P' . ( 7 * 9 ) . 'D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        }
        $this->Cal->addFestivity( "CorpusChristi", $CorpusChristi );

    }

    private function calculateAshWednesday() : void {
        $AshWednesday = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "AshWednesday" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->sub( new DateInterval( 'P46D' ) ),           LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $this->Cal->addFestivity( "AshWednesday", $AshWednesday );
    }

    private function calculateWeekdaysHolyWeek() : void {
        //Weekdays of Holy Week from Monday to Thursday inclusive ( that is, thursday morning chrism mass... the In Coena Domini mass begins the Easter Triduum )
        $MonHolyWeek = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "MonHolyWeek" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->sub( new DateInterval( 'P6D' ) ),            LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $TueHolyWeek = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "TueHolyWeek" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->sub( new DateInterval( 'P5D' ) ),            LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $WedHolyWeek = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "WedHolyWeek" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->sub( new DateInterval( 'P4D' ) ),            LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $this->Cal->addFestivity( "MonHolyWeek", $MonHolyWeek );
        $this->Cal->addFestivity( "TueHolyWeek", $TueHolyWeek );
        $this->Cal->addFestivity( "WedHolyWeek", $WedHolyWeek );
    }

    private function calculateEasterOctave() : void {
        //Days within the octave of Easter
        $MonOctaveEaster = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "MonOctaveEaster" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P1D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $TueOctaveEaster = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "TueOctaveEaster" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P2D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $WedOctaveEaster = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "WedOctaveEaster" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P3D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $ThuOctaveEaster = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "ThuOctaveEaster" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P4D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $FriOctaveEaster = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "FriOctaveEaster" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P5D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $SatOctaveEaster = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "SatOctaveEaster" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P6D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );

        $this->Cal->addFestivity( "MonOctaveEaster", $MonOctaveEaster );
        $this->Cal->addFestivity( "TueOctaveEaster", $TueOctaveEaster );
        $this->Cal->addFestivity( "WedOctaveEaster", $WedOctaveEaster );
        $this->Cal->addFestivity( "ThuOctaveEaster", $ThuOctaveEaster );
        $this->Cal->addFestivity( "FriOctaveEaster", $FriOctaveEaster );
        $this->Cal->addFestivity( "SatOctaveEaster", $SatOctaveEaster );
    }

    private function calculateMobileSolemnitiesOfTheLord() : void {
        $SacredHeart = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "SacredHeart" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],    LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P' . ( 7 * 9 + 5 ) . 'D' ) ),  LitColor::RED,      LitFeastType::MOBILE, LitGrade::SOLEMNITY );
        $this->Cal->addFestivity( "SacredHeart", $SacredHeart );

        //Christ the King is calculated backwards from the first sunday of advent
        $ChristKing = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "ChristKing" ][ "NAME_" . $this->LITSETTINGS->LOCALE ],     DateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' )->sub( new DateInterval( 'P' . ( 4 * 7 ) . 'D' ) ),    LitColor::RED,  LitFeastType::MOBILE, LitGrade::SOLEMNITY );
        $this->Cal->addFestivity( "ChristKing", $ChristKing );
    }

    private function calculateFixedSolemnities() : void {
        //even though Mary Mother of God is a fixed date solemnity, however it is found in the Proprium de Tempore and not in the Proprium de Sanctis
        $MotherGod = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "MotherGod" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], DateTime::createFromFormat( '!j-n-Y', '1-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) ),      LitColor::WHITE,    LitFeastType::FIXED, LitGrade::SOLEMNITY );
        $this->Cal->addFestivity( "MotherGod", $MotherGod );

        $tempCalSolemnities = array_filter( $this->tempCal[ ROMANMISSAL::EDITIO_TYPICA_1970 ], function( $el ){ return $el->GRADE === LitGrade::SOLEMNITY; } );
        foreach( $tempCalSolemnities as $row ) {
            $currentFeastDate = DateTime::createFromFormat( '!j-n-Y', $row->DAY . '-' . $row->MONTH . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
            $tempFestivity = new Festivity( $row->NAME, $currentFeastDate, $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON );

            //A Solemnity impeded in any given year is transferred to the nearest day following designated in nn. 1-8 of the Tables given above ( LY 60 )
            //However if a solemnity is impeded by a Sunday of Advent, Lent or Easter Time, the solemnity is transferred to the Monday following,
            //or to the nearest free day, as laid down by the General Norms.
            //This affects Joseph, Husband of Mary ( Mar 19 ), Annunciation ( Mar 25 ), and Immaculate Conception ( Dec 8 ).
            //It is not possible for a fixed date Solemnity to fall on a Sunday of Easter.

            //However, if a solemnity is impeded by Palm Sunday or by Easter Sunday, it is transferred to the first free day ( Monday? )
            //after the Second Sunday of Easter ( decision of the Congregation of Divine Worship, dated 22 April 1990, in Notitiæ vol. 26 [ 1990 ] num. 3/4, p. 160, Prot. CD 500/89 ).
            //Any other celebrations that are impeded are omitted for that year.

            /**
             * <<
             *   Quando vero sollemnitates in his dominicis ( i.e. Adventus, Quadragesimae et Paschae ), iuxta n.5 "Normarum universalium de anno liturgico et de calendario"
             * sabbato anticipari debent. Experientia autem pastoralis ostendit quod solutio huiusmodi nonnullas praebet difficultates praesertim quoad occurrentiam
             * celebrationis Missae vespertinae et II Vesperarum Liturgiae Horarum cuiusdam sollemnitatis cum celebratione Missae vespertinae et I Vesperarum diei dominicae.
             * [ ... Perciò facciamo la seguente modifica al n. 5 delle norme universali: ]
             * Sollemnitates autem in his dominicis occurrentes ad feriam secundam sequentem transferuntur, nisi agatur de occurrentia in Dominica in Palmis
             * aut in Dominica Resurrectionis Domini.
             *  >>
             *
             * http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/1990/284-285.html
             */

            if( $this->Cal->inSolemnities( $currentFeastDate ) ) {
                    //if Joseph, Husband of Mary ( Mar 19 ) falls on Palm Sunday or during Holy Week, it is moved to the Saturday preceding Palm Sunday
                    //this is correct and the reason for this is that, in this case, Annunciation will also fall during Holy Week,
                    //and the Annunciation will be transferred to the Monday following the Second Sunday of Easter
                    //Notitiæ vol. 42 [ 2006 ] num. 3/4, 475-476, p. 96
                    //http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/2006/475-476.html
                    if( $row->TAG === "StJoseph" && $currentFeastDate >= $this->Cal->getFestivity("PalmSun")->date && $currentFeastDate <= $this->Cal->getFestivity("Easter")->date ){
                        $tempFestivity->date = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->sub( new DateInterval( 'P8D' ) );
                        $this->Messages[] = sprintf(
                            _( "The Solemnity '%s' falls on %s in the year %d, the celebration has been transferred to %s (%s) as per the %s." ),
                            $tempFestivity->name,
                            $this->Cal->solemnityFromDate( $currentFeastDate )->name,
                            $this->LITSETTINGS->YEAR,
                            _( "the Saturday preceding Palm Sunday" ),
                            $this->LITSETTINGS->LOCALE === 'LA' ? ( $tempFestivity->date->format( 'j' ) . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[ (int)$tempFestivity->date->format( 'n' ) ] ) :
                                ( $this->LITSETTINGS->LOCALE === 'EN' ? $tempFestivity->date->format( 'F jS' ) :
                                    $this->dayAndMonth->format( $tempFestivity->date->format( 'U' ) )
                                ),
                            '<a href="http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/2006/475-476.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>'
                        );
                    }
                    else if( $row->TAG === "Annunciation" && $currentFeastDate >= $this->Cal->getFestivity( "PalmSun" )->date && $currentFeastDate <= $this->Cal->getFestivity( "Easter2" )->date ){
                        //if the Annunciation falls during Holy Week or within the Octave of Easter, it is transferred to the Monday after the Second Sunday of Easter.
                        $tempFestivity->date = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P8D' ) );
                        $this->Messages[] = sprintf(
                            _( "The Solemnity '%s' falls on %s in the year %d, the celebration has been transferred to %s (%s) as per the %s." ),
                            $tempFestivity->name,
                            $this->Cal->solemnityFromDate( $currentFeastDate )->name,
                            $this->LITSETTINGS->YEAR,
                            _( 'the Monday following the Second Sunday of Easter' ),
                            $this->LITSETTINGS->LOCALE === 'LA' ? ( $tempFestivity->date->format( 'j' ) . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[ (int)$tempFestivity->date->format( 'n' ) ] ) :
                                ( $this->LITSETTINGS->LOCALE === 'EN' ? $tempFestivity->date->format( 'F jS' ) :
                                    $this->dayAndMonth->format( $tempFestivity->date->format( 'U' ) )
                                ),
                            '<a href="http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/2006/475-476.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>'
                        );

                        //In some German churches it was the custom to keep the office of the Annunciation on the Saturday before Palm Sunday if the 25th of March fell in Holy Week.
                        //source: http://www.newadvent.org/cathen/01542a.htm
                        /*
                            else if( $tempFestivity->date == $this->Cal->getFestivity( "PalmSun" )->date ){
                            $tempFestivity->date->add( new DateInterval( 'P15D' ) );
                            //$tempFestivity->date->sub( new DateInterval( 'P1D' ) );
                            }
                        */

                    }
                    else if( in_array( $row->TAG, [ "Annunciation", "StJoseph", "ImmaculateConception" ] ) && $this->Cal->isSundayAdventLentEaster( $currentFeastDate ) ){
                        $tempFestivity->date = clone( $currentFeastDate );
                        $tempFestivity->date->add( new DateInterval( 'P1D' ) );
                        $this->Messages[] = sprintf(
                            _( "The Solemnity '%s' falls on %s in the year %d, the celebration has been transferred to %s (%s) as per the %s." ),
                            $tempFestivity->name,
                            $this->Cal->solemnityFromDate( $currentFeastDate )->name,
                            $this->LITSETTINGS->YEAR,
                            _( "the following Monday" ),
                            $this->LITSETTINGS->LOCALE === 'LA' ? ( $tempFestivity->date->format( 'j' ) . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[ (int)$tempFestivity->date->format( 'n' ) ] ) :
                                ( $this->LITSETTINGS->LOCALE === 'EN' ? $tempFestivity->date->format( 'F jS' ) :
                                    $this->dayAndMonth->format( $tempFestivity->date->format( 'U' ) )
                                ),
                            '<a href="http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/1990/284-285.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>'
                        );
                    }
                    else{
                        //In all other cases, let's make a note of what's happening and ask the Congegation for Divine Worship
                        $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                            _( "The Solemnity '%s' coincides with the Solemnity '%s' in the year %d. We should ask the Congregation for Divine Worship what to do about this!" ),
                            $row->NAME,
                            $this->Cal->solemnityFromDate( $currentFeastDate )->name,
                            $this->LITSETTINGS->YEAR
                        );
                    }

                    //In the year 2022, the Solemnity Nativity of John the Baptist coincides with the Solemnity of the Sacred Heart
                    //Nativity of John the Baptist anticipated by one day to June 23
                    //( except in cases where John the Baptist is patron of a nation, diocese, city or religious community, then the Sacred Heart can be anticipated by one day to June 23 )
                    //http://www.cultodivino.va/content/cultodivino/it/documenti/responsa-ad-dubia/2020/de-calendario-liturgico-2022.html
                    //This will happen again in 2033 and 2044
                    if( $row->TAG === "NativityJohnBaptist" && $this->Cal->solemnityKeyFromDate( $currentFeastDate ) === "SacredHeart" ){
                        $NativityJohnBaptistNewDate = clone( $this->Cal->getFestivity( "SacredHeart" )->date );
                        $SacredHeart = $this->Cal->solemnityFromDate( $currentFeastDate );
                        if( !$this->Cal->inSolemnities( $NativityJohnBaptistNewDate->sub( new DateInterval( 'P1D' ) ) ) ) {
                            $tempFestivity->date->sub( new DateInterval( 'P1D' ) );
                            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                _( "Seeing that the Solemnity '%s' coincides with the Solemnity '%s' in the year %d, it has been anticipated by one day as per %s." ),
                                $tempFestivity->name,
                                $SacredHeart->name,
                                $this->LITSETTINGS->YEAR,
                                '<a href="http://www.cultodivino.va/content/cultodivino/it/documenti/responsa-ad-dubia/2020/de-calendario-liturgico-2022.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>'
                            );
                        }
                    }
            }
            $this->Cal->addFestivity( $row->TAG, $tempFestivity );
        }

        //let's add a displayGrade property for AllSouls so applications don't have to worry about fixing it
        $this->Cal->setProperty( "AllSouls", "displayGrade", LitGrade::i18n( LitGrade::COMMEMORATION, $this->LITSETTINGS->LOCALE, false ) );

        $this->Cal->addSolemnitiesLordBVM( [
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
        ] );

    }

    private function calculateFeastsOfTheLord() : void {
        //Baptism of the Lord is celebrated the Sunday after Epiphany, for exceptions see immediately below...
        $this->BaptismLordFmt = '6-1-' . $this->LITSETTINGS->YEAR;
        $this->BaptismLordMod = 'next Sunday';
        //If Epiphany is celebrated on Sunday between Jan. 2 - Jan 8, and Jan. 7 or Jan. 8 is Sunday, then Baptism of the Lord is celebrated on the Monday immediately following that Sunday
        if ( $this->LITSETTINGS->EPIPHANY === EPIPHANY::SUNDAY_JAN2_JAN8 ) {
            $dateJan7 = DateTime::createFromFormat( '!j-n-Y', '7-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
            $dateJan8 = DateTime::createFromFormat( '!j-n-Y', '8-1-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
            if ( self::DateIsSunday( $dateJan7 ) ) {
                $this->BaptismLordFmt = '7-1-' . $this->LITSETTINGS->YEAR;
                $this->BaptismLordMod = 'next Monday';
            } else if ( self::DateIsSunday( $dateJan8 ) ) {
                $this->BaptismLordFmt = '8-1-' . $this->LITSETTINGS->YEAR;
                $this->BaptismLordMod = 'next Monday';
            }
        }
        $BaptismLord      = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "BaptismLord" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], DateTime::createFromFormat( '!j-n-Y', $this->BaptismLordFmt, new DateTimeZone( 'UTC' ) )->modify( $this->BaptismLordMod ), LitColor::WHITE, LitFeastType::MOBILE, LitGrade::FEAST_LORD );
        $this->Cal->addFestivity( "BaptismLord", $BaptismLord );

        //the other feasts of the Lord ( Presentation, Transfiguration and Triumph of the Holy Cross) are fixed date feasts
        //and are found in the Proprium de Sanctis
        $tempCal = array_filter( $this->tempCal[ ROMANMISSAL::EDITIO_TYPICA_1970 ], function( $el ){ return $el->GRADE === LitGrade::FEAST_LORD; } );

        foreach ( $tempCal as $row ) {
            $currentFeastDate = DateTime::createFromFormat( '!j-n-Y', $row->DAY . '-' . $row->MONTH . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
            $festivity = new Festivity( $row->NAME, $currentFeastDate, $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON );
            $this->Cal->addFestivity( $row->TAG, $festivity );
        }

        //Holy Family is celebrated the Sunday after Christmas, unless Christmas falls on a Sunday, in which case it is celebrated Dec. 30
        if ( self::DateIsSunday( $this->Cal->getFestivity( "Christmas" )->date ) ) {
            $holyFamilyDate = DateTime::createFromFormat( '!j-n-Y', '30-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
            $HolyFamily = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "HolyFamily" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], $holyFamilyDate, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::FEAST_LORD );
            $this->Messages[] = sprintf(
                _( "'%s' falls on a Sunday in the year %d, therefore the Feast '%s' is celebrated on %s rather than on the Sunday after Christmas." ),
                $this->Cal->getFestivity( "Christmas" )->name,
                $this->LITSETTINGS->YEAR,
                $HolyFamily->name,
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $HolyFamily->date->format( 'j' ) . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[ (int)$HolyFamily->date->format( 'n' ) ] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $HolyFamily->date->format( 'F jS' ) :
                        $this->dayAndMonth->format( $HolyFamily->date->format( 'U' ) )
                    )
            );
        } else {
            $holyFamilyDate = DateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) )->modify( 'next Sunday' );
            $HolyFamily = new Festivity( $this->PROPRIUM_DE_TEMPORE[ "HolyFamily" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], $holyFamilyDate, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::FEAST_LORD );
        }
        $this->Cal->addFestivity( "HolyFamily", $HolyFamily );

    }

    private function calculateSundaysChristmasOrdinaryTime() : void {
        //If a fixed date Solemnity occurs on a Sunday of Ordinary Time or on a Sunday of Christmas, the Solemnity is celebrated in place of the Sunday. ( e.g., Birth of John the Baptist, 1990 )
        //If a fixed date Feast of the Lord occurs on a Sunday in Ordinary Time, the feast is celebrated in place of the Sunday

        //Sundays of Ordinary Time in the First part of the year are numbered from after the Baptism of the Lord ( which begins the 1st week of Ordinary Time ) until Ash Wednesday
        $firstOrdinary = DateTime::createFromFormat( '!j-n-Y', $this->BaptismLordFmt, new DateTimeZone( 'UTC' ) )->modify( $this->BaptismLordMod );
        //Basically we take Ash Wednesday as the limit...
        //Here is ( Ash Wednesday - 7 ) since one more cycle will complete...
        $firstOrdinaryLimit = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->sub( new DateInterval( 'P53D' ) );
        $ordSun = 1;
        while ( $firstOrdinary >= $this->Cal->getFestivity( "BaptismLord" )->date && $firstOrdinary < $firstOrdinaryLimit ) {
            $firstOrdinary = DateTime::createFromFormat( '!j-n-Y', $this->BaptismLordFmt, new DateTimeZone( 'UTC' ) )->modify( $this->BaptismLordMod )->modify( 'next Sunday' )->add( new DateInterval( 'P' . ( ( $ordSun - 1 ) * 7 ) . 'D' ) );
            $ordSun++;
            if ( !$this->Cal->inSolemnities( $firstOrdinary ) ) {
                $this->Cal->addFestivity( "OrdSunday" . $ordSun, new Festivity( $this->PROPRIUM_DE_TEMPORE[ "OrdSunday" . $ordSun ][ "NAME_" . $this->LITSETTINGS->LOCALE ], $firstOrdinary, LitColor::GREEN, LitFeastType::MOBILE, LitGrade::FEAST_LORD ) );
            } else {
                $this->Messages[] = sprintf(
                    _( "'%s' is superseded by the %s '%s' in the year %d." ),
                    $this->PROPRIUM_DE_TEMPORE[ "OrdSunday" . $ordSun ][ "NAME_" . $this->LITSETTINGS->LOCALE ],
                    $this->Cal->solemnityFromDate( $firstOrdinary )->grade > LitGrade::SOLEMNITY ? '<i>' . LitGrade::i18n( $this->Cal->solemnityFromDate( $firstOrdinary )->grade, $this->LITSETTINGS->LOCALE, false ) . '</i>' : LitGrade::i18n( $this->Cal->solemnityFromDate( $firstOrdinary )->grade, $this->LITSETTINGS->LOCALE, false ),
                    $this->Cal->solemnityFromDate( $firstOrdinary )->name,
                    $this->LITSETTINGS->YEAR
                );
            }
        }

        //Sundays of Ordinary Time in the Latter part of the year are numbered backwards from Christ the King ( 34th ) to Pentecost
        $lastOrdinary = DateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' )->sub( new DateInterval( 'P' . ( 4 * 7 ) . 'D' ) );
        //We take Trinity Sunday as the limit...
        //Here is ( Trinity Sunday + 7 ) since one more cycle will complete...
        $lastOrdinaryLowerLimit = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P' . ( 7 * 9 ) . 'D' ) );
        $ordSun = 34;
        $ordSunCycle = 4;

        while ( $lastOrdinary <= $this->Cal->getFestivity( "ChristKing" )->date && $lastOrdinary > $lastOrdinaryLowerLimit ) {
            $lastOrdinary = DateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' )->sub( new DateInterval( 'P' . ( ++$ordSunCycle * 7 ) . 'D' ) );
            $ordSun--;
            if ( !$this->Cal->inSolemnities( $lastOrdinary ) ) {
                $this->Cal->addFestivity( "OrdSunday" . $ordSun, new Festivity( $this->PROPRIUM_DE_TEMPORE[ "OrdSunday" . $ordSun ][ "NAME_" . $this->LITSETTINGS->LOCALE ], $lastOrdinary, LitColor::GREEN, LitFeastType::MOBILE, LitGrade::FEAST_LORD ) );
            } else {
                $this->Messages[] = sprintf(
                    _( "'%s' is superseded by the %s '%s' in the year %d." ),
                    $this->PROPRIUM_DE_TEMPORE[ "OrdSunday" . $ordSun ][ "NAME_" . $this->LITSETTINGS->LOCALE ],
                    $this->Cal->solemnityFromDate( $lastOrdinary )->grade > LitGrade::SOLEMNITY ? '<i>' . LitGrade::i18n( $this->Cal->solemnityFromDate( $lastOrdinary )->grade, $this->LITSETTINGS->LOCALE, false ) . '</i>' : LitGrade::i18n( $this->Cal->solemnityFromDate( $lastOrdinary )->grade, $this->LITSETTINGS->LOCALE, false ),
                    $this->Cal->solemnityFromDate( $lastOrdinary )->name,
                    $this->LITSETTINGS->YEAR
                );
            }
        }

    }

    private function calculateFeastsMarySaints() : void {
        $tempCal = array_filter( $this->tempCal[ ROMANMISSAL::EDITIO_TYPICA_1970 ], function( $el ){ return $el->GRADE === LitGrade::FEAST; } );

        foreach ( $tempCal as $row ) {
            $row->DATE = DateTime::createFromFormat( '!j-n-Y', $row->DAY . '-' . $row->MONTH . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
            //If a Feast ( not of the Lord ) occurs on a Sunday in Ordinary Time, the Sunday is celebrated.  ( e.g., St. Luke, 1992 )
            //obviously solemnities also have precedence
            if ( self::DateIsNotSunday( $row->DATE ) && !$this->Cal->inSolemnities( $row->DATE ) ) {
                $festivity = new Festivity( $row->NAME, $row->DATE, $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON );
                $this->Cal->addFestivity( $row->TAG, $festivity );
            } else {
                $this->handleCoincidence( $row, RomanMissal::EDITIO_TYPICA_1970 );
            }
        }

        //With the decree Apostolorum Apostola ( June 3rd 2016 ), the Congregation for Divine Worship
        //with the approval of Pope Francis elevated the memorial of Saint Mary Magdalen to a Feast
        //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/articolo-roche-maddalena_it.pdf
        //This is taken care of ahead when the memorials are created, see comment tag MARYMAGDALEN:

    }

    private function calculateWeekdaysAdvent() : void {
        //  Here we are calculating all weekdays of Advent, but we are giving a certain importance to the weekdays of Advent from 17 Dec. to 24 Dec.
        //  ( the same will be true of the Octave of Christmas and weekdays of Lent )
        //  on which days obligatory memorials can only be celebrated in partial form

        $DoMAdvent1     = $this->Cal->getFestivity("Advent1")->date->format( 'j' ); //DoM == Day of Month
        $MonthAdvent1   = $this->Cal->getFestivity("Advent1")->date->format( 'n' );
        $weekdayAdvent  = DateTime::createFromFormat( '!j-n-Y', $DoMAdvent1 . '-' . $MonthAdvent1 . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        $weekdayAdventCnt = 1;
        while ( $weekdayAdvent >= $this->Cal->getFestivity("Advent1")->date && $weekdayAdvent < $this->Cal->getFestivity("Christmas")->date ) {
            $weekdayAdvent = DateTime::createFromFormat( '!j-n-Y', $DoMAdvent1 . '-' . $MonthAdvent1 . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) )->add( new DateInterval( 'P' . $weekdayAdventCnt . 'D' ) );

            //if we're not dealing with a sunday or a solemnity, then create the weekday
            if ( $this->Cal->notInSolemnitiesFeastsOrMemorials( $weekdayAdvent ) && self::DateIsNotSunday( $weekdayAdvent ) ) {
                $upper = (int)$weekdayAdvent->format( 'z' );
                $diff = $upper - (int)$this->Cal->getFestivity("Advent1")->date->format( 'z' ); //day count between current day and First Sunday of Advent
                $currentAdvWeek = ( ( $diff - $diff % 7 ) / 7 ) + 1; //week count between current day and First Sunday of Advent

                $dayOfTheWeek = $this->LITSETTINGS->LOCALE === 'LA' ? LITCAL_MESSAGES::LATIN_DAYOFTHEWEEK[ $weekdayAdvent->format( 'w' ) ] : ucfirst( $this->dayOfTheWeek->format( $weekdayAdvent->format( 'U' ) ) );
                $ordinal = ucfirst( LITCAL_MESSAGES::getOrdinal( $currentAdvWeek, $this->LITSETTINGS->LOCALE, $this->formatterFem, LITCAL_MESSAGES::LATIN_ORDINAL_FEM_GEN ) );
                $nthStr = $this->LITSETTINGS->LOCALE === 'LA' ? sprintf( "Hebdomadæ %s Adventus", $ordinal ) : sprintf( _( "of the %s Week of Advent" ), $ordinal );
                $name = $dayOfTheWeek . " " . $nthStr;
                $festivity = new Festivity( $name, $weekdayAdvent, LitColor::PURPLE, LitFeastType::MOBILE );
                $this->Cal->addFestivity( "AdventWeekday" . $weekdayAdventCnt, $festivity );
            }

            $weekdayAdventCnt++;
        }
    }

    private function calculateWeekdaysChristmasOctave() : void {
        $weekdayChristmas = DateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        $weekdayChristmasCnt = 1;
        while ( $weekdayChristmas >= $this->Cal->getFestivity( "Christmas" )->date && $weekdayChristmas < DateTime::createFromFormat( '!j-n-Y', '31-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) ) ) {
            $weekdayChristmas = DateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) )->add( new DateInterval( 'P' . $weekdayChristmasCnt . 'D' ) );
            if ( $this->Cal->notInSolemnitiesFeastsOrMemorials( $weekdayChristmas ) && self::DateIsNotSunday( $weekdayChristmas ) ) {
                $ordinal = ucfirst( LITCAL_MESSAGES::getOrdinal( ( $weekdayChristmasCnt + 1 ), $this->LITSETTINGS->LOCALE, $this->formatter, LITCAL_MESSAGES::LATIN_ORDINAL ) );
                $name = $this->LITSETTINGS->LOCALE === 'LA' ? sprintf( "Dies %s Octavæ Nativitatis", $ordinal ) : sprintf( _( "%s Day of the Octave of Christmas" ), $ordinal );
                $festivity = new Festivity( $name, $weekdayChristmas, LitColor::WHITE, LitFeastType::MOBILE );
                $this->Cal->addFestivity( "ChristmasWeekday" . $weekdayChristmasCnt, $festivity );
            }
            $weekdayChristmasCnt++;
        }
    }

    private function calculateWeekdaysLent() : void {

        //Day of the Month of Ash Wednesday
        $DoMAshWednesday = $this->Cal->getFestivity( "AshWednesday" )->date->format( 'j' );
        $MonthAshWednesday = $this->Cal->getFestivity( "AshWednesday" )->date->format( 'n' );
        $weekdayLent = DateTime::createFromFormat( '!j-n-Y', $DoMAshWednesday . '-' . $MonthAshWednesday . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        $weekdayLentCnt = 1;
        while ( $weekdayLent >= $this->Cal->getFestivity( "AshWednesday" )->date && $weekdayLent < $this->Cal->getFestivity( "PalmSun" )->date ) {
            $weekdayLent = DateTime::createFromFormat( '!j-n-Y', $DoMAshWednesday . '-' . $MonthAshWednesday . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) )->add( new DateInterval( 'P' . $weekdayLentCnt . 'D' ) );
            if ( !$this->Cal->inSolemnities( $weekdayLent ) && self::DateIsNotSunday( $weekdayLent ) ) {
                if ( $weekdayLent > $this->Cal->getFestivity("Lent1")->date ) {
                    $upper =  (int)$weekdayLent->format( 'z' );
                    $diff = $upper -  (int)$this->Cal->getFestivity( "Lent1" )->date->format( 'z' ); //day count between current day and First Sunday of Lent
                    $currentLentWeek = ( ( $diff - $diff % 7 ) / 7 ) + 1; //week count between current day and First Sunday of Lent
                    $ordinal = ucfirst( LITCAL_MESSAGES::getOrdinal( $currentLentWeek, $this->LITSETTINGS->LOCALE, $this->formatterFem, LITCAL_MESSAGES::LATIN_ORDINAL_FEM_GEN ) );
                    $dayOfTheWeek = $this->LITSETTINGS->LOCALE == 'LA' ? LITCAL_MESSAGES::LATIN_DAYOFTHEWEEK[ $weekdayLent->format( 'w' ) ] : ucfirst( $this->dayOfTheWeek->format( $weekdayLent->format( 'U' ) ) );
                    $nthStr = $this->LITSETTINGS->LOCALE === 'LA' ? sprintf( "Hebdomadæ %s Quadragesimæ", $ordinal ) : sprintf( _( "of the %s Week of Lent" ), $ordinal );
                    $name = $dayOfTheWeek . " ".  $nthStr;
                    $festivity = new Festivity( $name, $weekdayLent, LitColor::PURPLE, LitFeastType::MOBILE );
                } else {
                    $dayOfTheWeek = $this->LITSETTINGS->LOCALE == 'LA' ? LITCAL_MESSAGES::LATIN_DAYOFTHEWEEK[ $weekdayLent->format( 'w' ) ] : ucfirst( $this->dayOfTheWeek->format( $weekdayLent->format( 'U' ) ) );
                    $postStr = $this->LITSETTINGS->LOCALE === 'LA' ? "post Feria IV Cinerum" : _( "after Ash Wednesday" );
                    $name = $dayOfTheWeek . " ". $postStr;
                    $festivity = new Festivity( $name, $weekdayLent, LitColor::PURPLE, LitFeastType::MOBILE );
                }
                $this->Cal->addFestivity( "LentWeekday" . $weekdayLentCnt, $festivity );
            }
            $weekdayLentCnt++;
        }

    }

    private function calculateMemorials( int $grade = LitGrade::MEMORIAL, string $missal = RomanMissal::EDITIO_TYPICA_1970 ) : void {

        if( $missal === RomanMissal::EDITIO_TYPICA_1970 && $grade === LitGrade::MEMORIAL ) {
            $this->createImmaculateHeart();
        }
        $tempCal = array_filter( $this->tempCal[ $missal ], function( $el ) use ( $grade ){ return $el->GRADE === $grade; } );

        foreach ( $tempCal as $row ) {

            //If it doesn't occur on a Sunday or a Solemnity or a Feast of the Lord or a Feast or an obligatory memorial, then go ahead and create the optional memorial
            $row->DATE = DateTime::createFromFormat( '!j-n-Y', $row->DAY . '-' . $row->MONTH . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
            if ( self::DateIsNotSunday( $row->DATE ) && $this->Cal->notInSolemnitiesFeastsOrMemorials( $row->DATE ) ) {
                $newFestivity = new Festivity( $row->NAME, $row->DATE, $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON );
                $this->Cal->addFestivity( $row->TAG, $newFestivity );

                $this->reduceMemorialsInAdventLentToCommemoration( $row->DATE, $row );

                if( $missal === RomanMissal::EDITIO_TYPICA_TERTIA_2002 ) {
                    $row->yearSince = 2002;
                    $row->DECREE = '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>';
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
                        _( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d." ),
                        LitGrade::i18n( $row->GRADE, $this->LITSETTINGS->LOCALE, false ),
                        $row->NAME,
                        $this->LITSETTINGS->LOCALE === 'LA' ? ( $row->DATE->format( 'j' ) . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[ (int)$row->DATE->format( 'n' ) ] ) :
                            ( $this->LITSETTINGS->LOCALE === 'EN' ? $row->DATE->format( 'F jS' ) :
                                $this->dayAndMonth->format( $row->DATE->format( 'U' ) )
                            ),
                        $row->yearSince,
                        $row->DECREE,
                        $this->LITSETTINGS->YEAR
                    );
                }

                if ( $grade === LitGrade::MEMORIAL && $this->Cal->getFestivity( $row->TAG )->grade > LitGrade::MEMORIAL_OPT ) {
                    $this->removeWeekdaysEpiphanyOverridenByMemorials( $row->TAG );
                }

            } else {
                if( false === $this->checkImmaculateHeartCoincidence( $row->DATE, $row ) ) {
                    $this->handleCoincidence( $row, RomanMissal::EDITIO_TYPICA_1970 );
                }
            }
        }

        if( $missal === RomanMissal::EDITIO_TYPICA_TERTIA_2002 && $grade === LitGrade::MEMORIAL_OPT ) {
            $this->handleSaintJaneFrancesDeChantal();
        }

    }

    private function reduceMemorialsInAdventLentToCommemoration( DateTime $currentFeastDate, stdClass $row ) {

        //If a fixed date optional memorial falls between 17 Dec. to 24 Dec., the Octave of Christmas or weekdays of the Lenten season,
        //it is reduced in rank to a Commemoration ( only the collect can be used
        if ( $this->Cal->inWeekdaysAdventChristmasLent( $currentFeastDate ) ) {
            $this->Cal->setProperty( $row->TAG, "grade", LitGrade::COMMEMORATION );
            $this->Messages[] = sprintf(
                _( "The %s '%s' either falls between 17 Dec. and 24 Dec., or during the Octave of Christmas, or on the weekdays of the Lenten season in the year %d, rank reduced to Commemoration." ),
                LitGrade::i18n( $row->GRADE, $this->LITSETTINGS->LOCALE, false ),
                $row->NAME,
                $this->LITSETTINGS->YEAR
            );
        }

    }

    private function removeWeekdaysEpiphanyOverridenByMemorials( string $tag ) {
        $festivity = $this->Cal->getFestivity( $tag );
        if( $this->Cal->inWeekdaysEpiphany( $festivity->date ) ){
            $key = $this->Cal->weekdayEpiphanyKeyFromDate( $festivity->date );
            if ( false !== $key ) {
                $this->Messages[] = sprintf(
                    _( "'%s' is superseded by the %s '%s' in the year %d." ),
                    $this->Cal->getFestivity( $key )->name,
                    LitGrade::i18n( $festivity->grade, $this->LITSETTINGS->LOCALE, false ),
                    $festivity->name,
                    $this->LITSETTINGS->YEAR
                );
                $this->Cal->removeFestivity( $key );
            }
        }
    }

    private function handleCoincidence( stdClass $row, string $missal = RomanMissal::EDITIO_TYPICA_1970 ) {

        $coincidingFestivity = $this->Cal->determineSundaySolemnityOrFeast( $row->DATE, $this->LITSETTINGS );
        switch( $missal ){
            case RomanMissal::EDITIO_TYPICA_1970:
                $this->Messages[] = sprintf(
                    _( "The %s '%s', added in the %s of the Roman Missal since the year %d (%s) and usually celebrated on %s, is suppressed by the %s '%s' in the year %d." ),
                    LitGrade::i18n( $row->GRADE, $this->LITSETTINGS->LOCALE, false ),
                    $row->NAME,
                    RomanMissal::getName( $missal ),
                    1970,
                    '',
                    $this->LITSETTINGS->LOCALE === 'LA' ? ( $row->DATE->format( 'j' ) . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[  (int)$row->DATE->format( 'n' ) ] ) :
                        ( $this->LITSETTINGS->LOCALE === 'EN' ? $row->DATE->format( 'F jS' ) :
                            $this->dayAndMonth->format( $row->DATE->format( 'U' ) )
                        ),
                    $coincidingFestivity->grade,
                    $coincidingFestivity->event->name,
                    $this->LITSETTINGS->YEAR
                );
                break;
            case RomanMissal::EDITIO_TYPICA_TERTIA_2002:
                $this->Messages[] = sprintf(
                    _( "The %s '%s', added in the %s of the Roman Missal since the year %d (%s) and usually celebrated on %s, is suppressed by the %s '%s' in the year %d." ),
                    LitGrade::i18n( $row->GRADE, $this->LITSETTINGS->LOCALE, false ),
                    $row->NAME,
                    RomanMissal::getName( $missal ),
                    2002,
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>',
                    $this->LITSETTINGS->LOCALE === 'LA' ? ( $row->DATE->format( 'j' ) . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[ (int)$row->DATE->format( 'n' ) ] ) :
                        ( $this->LITSETTINGS->LOCALE === 'EN' ? $row->DATE->format( 'F jS' ) :
                            $this->dayAndMonth->format( $row->DATE->format( 'U' ) )
                        ),
                    $coincidingFestivity->grade,
                    $coincidingFestivity->event->name,
                    $this->LITSETTINGS->YEAR
                );
                break;
            case RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008:
                $this->Messages[] = sprintf(
                    _( "The %s '%s', added in the %s of the Roman Missal since the year %d (%s) and usually celebrated on %s, is suppressed by the %s '%s' in the year %d." ),
                    LitGrade::i18n( $row->GRADE, $this->LITSETTINGS->LOCALE, false ),
                    $row->NAME,
                    RomanMissal::getName( $missal ),
                    2008,
                    'Missale Romanum, ed. Typica Tertia Emendata 2008',
                    $this->LITSETTINGS->LOCALE === 'LA' ? ( $row->DATE->format( 'j' ) . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[ (int)$row->DATE->format( 'n' ) ] ) :
                        ( $this->LITSETTINGS->LOCALE === 'EN' ? $row->DATE->format( 'F jS' ) :
                            $this->dayAndMonth->format( $row->DATE->format( 'U' ) )
                        ),
                    $coincidingFestivity->grade,
                    $coincidingFestivity->event->name,
                    $this->LITSETTINGS->YEAR
                );
                break;
        }

    }

    private function handleCoincidenceDecree( stdClass $row ) : void {

        $coincidingFestivity = $this->Cal->determineSundaySolemnityOrFeast( $row->DATE, $this->LITSETTINGS );
        $this->Messages[] = sprintf(
            _( "The %s '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d." ),
            $coincidingFestivity->grade,
            $row->NAME,
            $this->LITSETTINGS->LOCALE === 'LA' ? ( $row->DATE->format( 'j' ) . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[ (int)$row->DATE->format( 'n' ) ] ) :
                ( $this->LITSETTINGS->LOCALE === 'EN' ? $row->DATE->format( 'F jS' ) :
                    $this->dayAndMonth->format( $row->DATE->format( 'U' ) )
                ),
            $row->yearSince,
            $row->DECREE,
            $coincidingFestivity->event->name,
            $this->LITSETTINGS->YEAR
        );

    }

    private function checkImmaculateHeartCoincidence( DateTime $currentFeastDate, stdClass $row ) : bool {

        $coincidence = false;
        //IMMACULATEHEART: in years when the memorial of the Immaculate Heart of Mary coincides with another obligatory memorial,
        //as happened in 2014 [ 28 June, Saint Irenaeus ] and 2015 [ 13 June, Saint Anthony of Padua ], both must be considered optional for that year
        //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
        $ImmaculateHeart = $this->Cal->getFestivity( "ImmaculateHeart" );
        if ( $ImmaculateHeart !== null ) {
            if( (int)$row->GRADE === LitGrade::MEMORIAL ) {
                if( $currentFeastDate->format( 'U' ) === $ImmaculateHeart->date->format( 'U' ) ) {
                    $this->Cal->setProperty( "ImmaculateHeart", "grade", LitGrade::MEMORIAL_OPT );
                    $festivity = $this->Cal->getFestivity( $row->TAG );
                    if( $festivity === null ) {
                        $festivity = new Festivity( $row->NAME, $currentFeastDate, $row->COLOR, LitFeastType::FIXED, LitGrade::MEMORIAL_OPT, $row->COMMON );
                        $this->Cal->addFestivity( $row->TAG, $festivity );
                    } else {
                        $this->Cal->setProperty( $row->TAG, "grade", LitGrade::MEMORIAL_OPT );
                    }

                    $this->Messages[] = sprintf(
                        _( "The Memorial '%s' coincides with another Memorial '%s' in the year %d. They are both reduced in rank to optional memorials (%s)." ),
                        $ImmaculateHeart->name,
                        $festivity->name,
                        $this->LITSETTINGS->YEAR,
                        '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>'
                    );
                    $coincidence = true;
                }
            }
        }
        return $coincidence;

    }

    private function applyDoctorDecree1998() : void {
        $festivity = $this->Cal->getFestivity( "StThereseChildJesus" );
        if( $festivity !== null ) {
            $etDoctor = '';
            switch( $this->LITSETTINGS->LOCALE ){
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
            $this->Cal->setProperty( 'StThereseChildJesus', 'name', $festivity->name . $etDoctor );
        }
    }

    private function applyFeastDecree2016() : void {

        //MARYMAGDALEN: With the decree Apostolorum Apostola ( June 3rd 2016 ), the Congregation for Divine Worship
        //with the approval of Pope Francis elevated the memorial of Saint Mary Magdalen to a Feast
        //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/articolo-roche-maddalena_it.pdf
        $festivity = $this->Cal->getFestivity( "StMaryMagdalene" );
        if ( $festivity !== null ) {
            if ( $festivity->grade === LitGrade::MEMORIAL ) {
                $this->Messages[] = sprintf(
                    _( "The %s '%s' has been raised to the rank of %s since the year %d, applicable to the year %d (%s)." ),
                    LitGrade::i18n( $festivity->grade, $this->LITSETTINGS->LOCALE, false ),
                    $festivity->name,
                    LitGrade::i18n( LitGrade::FEAST, $this->LITSETTINGS->LOCALE, false ),
                    2016,
                    $this->LITSETTINGS->YEAR,
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/articolo-roche-maddalena_it.pdf">' . _( "Decree of the Congregation for Divine Worship" ) . '</a>'
                );
                $this->Cal->setProperty( "StMaryMagdalene", "grade", LitGrade::FEAST );
            }
        }

    }


    private function applyMemorialsTertiaEditioTypicaEmendata2008() : void {

        //Saint Pio of Pietrelcina "Padre Pio" was canonized on June 16 2002, so did not make it for the Calendar of the 2002 editio typica III
        //The memorial was added in the 2008 editio typica III emendata as an obligatory memorial
        $row = new stdClass();
        $names = [
            "EN" => "Saint Pius of Pietrelcina, Priest",
            "IT" => "San Pio da Pietrelcina, presbitero",
            "LA" => "S. Pii de Pietrelcina, presbyteri"
        ];
        $row->NAME = $names[ $this->LITSETTINGS->LOCALE ];
        $row->GRADE = LitGrade::MEMORIAL;
        $row->DATE = DateTime::createFromFormat( '!j-n-Y', '23-9-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        if( $this->Cal->notInSolemnitiesFeastsOrMemorials( $row->DATE ) ) {
            $newFestivity = new Festivity( $row->NAME, $row->DATE, LitColor::WHITE, LitFeastType::FIXED, LitGrade::MEMORIAL, "Pastors:For One Pastor,Holy Men and Women:For Religious" );
            $this->Cal->addFestivity( "StPioPietrelcina", $newFestivity );

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
                _( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d." ),
                LitGrade::i18n( $newFestivity->grade, $this->LITSETTINGS->LOCALE, false ),
                $newFestivity->name,
                $this->LITSETTINGS->LOCALE === 'LA' ? ( $newFestivity->date->format( 'j' ) . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[ (int)$newFestivity->date->format( 'n' ) ] ) :
                    ( $this->LITSETTINGS->LOCALE === 'EN' ? $newFestivity->date->format( 'F jS' ) :
                        $this->dayAndMonth->format( $newFestivity->date->format( 'U' ) )
                    ),
                2008,
                'Missale Romanum, ed. Typica Tertia Emendata 2008',
                $this->LITSETTINGS->YEAR
            );
        }
        else{
            $this->handleCoincidence( $row, RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008 );
        }

    }

    private function createMaryMotherChurch( stdClass $MaryMotherChurch ) {
        $festivity = new Festivity( $MaryMotherChurch->tag[ $this->LITSETTINGS->LOCALE ], $MaryMotherChurch->date, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::MEMORIAL, "Proper" );
        $this->Cal->addFestivity( "MaryMotherChurch", $festivity );
        $this->Messages[] = sprintf(
            _( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d." ),
            LitGrade::i18n( $festivity->grade, $this->LITSETTINGS->LOCALE, false ),
            $festivity->name,
            _( 'the Monday after Pentecost' ),
            2018,
            '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20180211_decreto-mater-ecclesiae_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>',
            $this->LITSETTINGS->YEAR
        );
    }

    private function applyMemorialDecree2018() : void {
        //With the Decree of the Congregation of Divine Worship on March 24, 2018,
        //the Obligatory Memorial of the Blessed Virgin Mary, Mother of the Church was added on the Monday after Pentecost
        //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20180211_decreto-mater-ecclesiae_la.html
        $MaryMotherChurch = new stdClass();
        $MaryMotherChurch->tag = [ "LA" => "Beatæ Mariæ Virginis, Ecclesiæ Matris", "IT" => "Beata Vergine Maria, Madre della Chiesa", "EN" => "Blessed Virgin Mary, Mother of the Church" ];
        $MaryMotherChurch->date = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P' . ( 7 * 7 + 1 ) . 'D' ) );
        //The Memorial is superseded by Solemnities and Feasts, but not by Memorials of Saints
        if( $this->Cal->inSolemnities( $MaryMotherChurch->date ) || $this->Cal->inFeasts( $MaryMotherChurch->date ) ){
            if( $this->Cal->inSolemnities( $MaryMotherChurch->date ) ) {
                $coincidingFestivity = $this->Cal->solemnityFromDate( $MaryMotherChurch->date );
            } else {
                $coincidingFestivity = $this->Cal->feastOrMemorialFromDate( $MaryMotherChurch->date );
            }

            $this->Messages[] = sprintf(
                _( "The Memorial '%s', added on %s since the year %d (%s), is however superseded by a Solemnity or a Feast '%s' in the year %d." ),
                $MaryMotherChurch->tag[ $this->LITSETTINGS->LOCALE ],
                _( 'the Monday after Pentecost' ),
                2018,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20180211_decreto-mater-ecclesiae_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>',
                $coincidingFestivity->name,
                $this->LITSETTINGS->YEAR
            );
        }
        else {
            if( $this->Cal->inCalendar( $MaryMotherChurch->date ) ) {
                $coincidingFestivities = $this->Cal->getCalEventsFromDate( $MaryMotherChurch->date );
                if( count( $coincidingFestivities ) > 0 ){
                    foreach( $coincidingFestivities as $coincidingFestivityKey => $coincidingFestivity ) {
                        $this->Messages[] = sprintf(
                            _( "The %s '%s' has been suppressed by the Memorial '%s', added on %s since the year %d (%s)." ),
                            LitGrade::i18n( $coincidingFestivity->grade, $this->LITSETTINGS->LOCALE, false ),
                            '<i>' . $coincidingFestivity->name . '</i>',
                            $MaryMotherChurch->tag[ $this->LITSETTINGS->LOCALE ],
                            _( 'the Monday after Pentecost' ),
                            2018,
                            '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20180211_decreto-mater-ecclesiae_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>'
                        );
                        $this->Cal->removeFestivity( $coincidingFestivityKey );
                    }
                }
            }

            $this->createMaryMotherChurch( $MaryMotherChurch );

        }

    }

    //With the Decree of the Congregation for Divine Worship on January 26, 2021,
    //the Memorial of Saint Martha on July 29th will now be of Mary, Martha and Lazarus
    //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20210126_decreto-santi_la.html
    private function applyMemorialDecree2021() : void {
        $festivity = $this->Cal->getFestivity( "StMartha" );
        if( $festivity !== null ) {
            $StMartha_tag = [ "LA" => "Sanctorum Marthæ, Mariæ et Lazari", "IT" => "Santi Marta, Maria e Lazzaro", "EN" => "Saints Martha, Mary and Lazarus" ];
            $this->Cal->setProperty( "StMartha", "name", $StMartha_tag[ $this->LITSETTINGS->LOCALE ] );
        }

    }

    private function createImmaculateHeart() {
        $row = new stdClass();
        $row->DATE = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P' . ( 7 * 9 + 6 ) . 'D' ) );
        if ( $this->Cal->notInSolemnitiesFeastsOrMemorials( $row->DATE ) ) {
            //Immaculate Heart of Mary fixed on the Saturday following the second Sunday after Pentecost
            //( see Calendarium Romanum Generale in Missale Romanum Editio Typica 1970 )
            //Pentecost = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P'.( 7*7 ).'D' ) )
            //Second Sunday after Pentecost = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P'.( 7*9 ).'D' ) )
            //Following Saturday = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P'.( 7*9+6 ).'D' ) )
            $this->Cal->addFestivity( "ImmaculateHeart", new Festivity( $this->PROPRIUM_DE_TEMPORE[ "ImmaculateHeart" ][ "NAME_" . $this->LITSETTINGS->LOCALE ], $row->DATE, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::MEMORIAL ) );

            //In years when this memorial coincides with another obligatory memorial, as happened in 2014 [ 28 June, Saint Irenaeus ] and 2015 [ 13 June, Saint Anthony of Padua ], both must be considered optional for that year
            //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
            //This is taken care of in the next code cycle, see tag IMMACULATEHEART: in the code comments ahead
        } else {
            $row = (object)$this->PROPRIUM_DE_TEMPORE[ "ImmaculateHeart" ];
            $row->GRADE = LitGrade::MEMORIAL;
            $row->DATE = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P' . ( 7 * 9 + 6 ) . 'D' ) );
            $this->handleCoincidence( $row, RomanMissal::EDITIO_TYPICA_1970 );
        }

    }

    //In the Tertia Editio Typica (2002),
    //Saint Jane Frances de Chantal was moved from December 12 to August 12,
    //probably to allow local bishop's conferences to insert Our Lady of Guadalupe as an optional memorial on December 12
    //seeing that with the decree of March 25th 1999 of the Congregation of Divine Worship
    //Our Lady of Guadalupe was granted as a Feast day for all dioceses and territories of the Americas
    //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_lt.html
    private function handleSaintJaneFrancesDeChantal() {

        $StJaneFrancesNewDate = DateTime::createFromFormat( '!j-n-Y', '12-8-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        if ( self::DateIsNotSunday( $StJaneFrancesNewDate ) && $this->Cal->notInSolemnitiesFeastsOrMemorials( $StJaneFrancesNewDate ) ) {
            $festivity = $this->Cal->getFestivity( "StJaneFrancesDeChantal" );
            if( $festivity !== null ) {
                $this->Cal->moveFestivityDate( "StJaneFrancesDeChantal", $StJaneFrancesNewDate );
                $this->Messages[] = sprintf(
                    _( "The optional memorial '%s' has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%s), applicable to the year %d." ),
                    $festivity->name,
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>',
                    $this->LITSETTINGS->YEAR
                );
            } else {
                //perhaps it wasn't created on December 12th because it was superseded by a Sunday, Solemnity or Feast
                //but seeing that there is no problem for August 12th, let's go ahead and try creating it again
                $row = $this->tempCal[ ROMANMISSAL::EDITIO_TYPICA_1970 ][ 'StJaneFrancesDeChantal' ];
                $festivity = new Festivity( $row->NAME, $StJaneFrancesNewDate, $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON );
                $this->Cal->addFestivity( "StJaneFrancesDeChantal", $festivity );
                $this->Messages[] = sprintf(
                    _( "The optional memorial '%s', which would have been superseded this year by a Sunday or Solemnity were it on Dec. 12, has however been transferred to Aug. 12 since the year 2002 (%s), applicable to the year %d." ),
                    $festivity->name,
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>',
                    $this->LITSETTINGS->YEAR
                );
            }
        } else {
            $coincidingFestivity = $this->Cal->determineSundaySolemnityOrFeast( $StJaneFrancesNewDate );
            $festivity = $this->Cal->getFestivity( "StJaneFrancesDeChantal" );
            //we can't move it, but we still need to remove it from Dec 12th if it's there!!!
            if( $festivity !== null ) {
                $this->Cal->removeFestivity( "StJaneFrancesDeChantal" );
            }
            $row = $this->tempCal[ ROMANMISSAL::EDITIO_TYPICA_1970 ][ 'StJaneFrancesDeChantal' ];
            $this->Messages[] = sprintf(
                _( 'The optional memorial \'%1$s\' has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d. However, it is superseded by a Sunday, a Solemnity, or a Feast \'%4$s\' in the year %3$d.' ),
                $row->NAME,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>',
                $this->LITSETTINGS->YEAR,
                $coincidingFestivity->event->name
            );
        }

    }

    private function applyOptionalMemorialsTertiaEditioTypicaEmendata2008() : void {

        //Saint Juan Diego was canonized in 2002, so did not make it to the Tertia Editio Typica 2002
        //The optional memorial was added in the Tertia Editio Typica emendata in 2008,
        //together with the optional memorial of Our Lady of Guadalupe
        $rows = [];
        $rows[0] = new stdClass();
        $rows[0]->TAG = "LadyGuadalupe";
        $rows[0]->GRADE = LitGrade::MEMORIAL_OPT;
        $names = [
            "EN" => "Our Lady of Guadalupe",
            "IT" => "Beata Vergine Maria di Guadalupe",
            "LA" => "Beatæ Mariæ Virginis Guadalupensis"
        ];
        $rows[0]->NAME = $names[ $this->LITSETTINGS->LOCALE ];
        $rows[0]->DATE = DateTime::createFromFormat( '!j-n-Y', '12-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        $rows[0]->COMMON = "Blessed Virgin Mary";
        $rows[0]->yearSince = 2002;
        $rows[0]->DECREE = '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>';

        $rows[1] = new stdClass();
        $rows[1]->TAG = "JuanDiego";
        $rows[1]->GRADE = LitGrade::MEMORIAL_OPT;
        $names = [
            "EN" => "Saint Juan Diego Cuauhtlatoatzin",
            "IT" => "San Juan Diego Cuauhtlatouatzin",
            "LA" => "Sancti Ioannis Didaci Cuauhtlatoatzin"
        ];
        $rows[1]->NAME = $names[ $this->LITSETTINGS->LOCALE ];
        $rows[1]->DATE = DateTime::createFromFormat( '!j-n-Y', '9-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        $rows[1]->COMMON = "Holy Men and Women:For One Saint";
        $rows[1]->yearSince = 2002;
        $rows[1]->DECREE = '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>';

        foreach( $rows as $row ) {
            if ( self::DateIsNotSunday( $row->DATE ) && $this->Cal->notInSolemnitiesFeastsOrMemorials( $row->DATE ) ) {
                $festivity = new Festivity( $row->NAME, $row->DATE, LitColor::WHITE, LitFeastType::FIXED, $row->GRADE, $row->COMMON );
                $this->Cal->addFestivity( $row->TAG, $festivity );
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
                    _( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d." ),
                    LitGrade::i18n( $festivity->grade, $this->LITSETTINGS->LOCALE, false ),
                    $festivity->name,
                    $this->LITSETTINGS->LOCALE === 'LA' ? ( $row->DATE->format( 'j' ) . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[ (int)$row->DATE->format( 'n' ) ] ) :
                        ( $this->LITSETTINGS->LOCALE === 'EN' ? $row->DATE->format( 'F jS' ) :
                            $this->dayAndMonth->format( $row->DATE->format( 'U' ) )
                        ),
                    $row->yearSince,
                    $row->DECREE,
                    $this->LITSETTINGS->YEAR
                );
            } else {
                $this->handleCoincidence( $row, RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008 );
            }
        }

    }

    //The Conversion of St. Paul falls on a Sunday in the year 2009.
    //However, considering that it is the Year of Saint Paul,
    //with decree of Jan 25 2008 the Congregation for Divine Worship gave faculty to the single churches
    //to celebrate the Conversion of St. Paul anyways. So let's re-insert it as an optional memorial?
    //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20080125_san-paolo_la.html
    private function applyOptionalMemorialDecree2009() : void {
        $festivity = $this->Cal->getFestivity( "ConversionStPaul" );
        if( $festivity === null ) {
            $row = $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ][ "ConversionStPaul" ];
            $festivity = new Festivity( $row->NAME, DateTime::createFromFormat( '!j-n-Y', '25-1-2009', new DateTimeZone( 'UTC' ) ), LitColor::WHITE, LitFeastType::FIXED, LitGrade::MEMORIAL_OPT, "Proper" );
            $this->Cal->addFestivity( "ConversionStPaul", $festivity );
            $this->Messages[] = sprintf(
                _( 'The Feast \'%s\' would have been suppressed this year ( 2009 ) since it falls on a Sunday, however being the Year of the Apostle Paul, as per the %s it has been reinstated so that local churches can optionally celebrate the memorial.' ),
                '<i>' . $row->NAME . '</i>',
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20080125_san-paolo_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>'
            );
        }
    }

    private function addPreparedRows( array $rows ) : void {
        foreach( $rows as $row ) {
            if( $this->Cal->notInSolemnitiesFeastsOrMemorials( $row->DATE ) ) {
                $festivity = new Festivity( $row->NAME, $row->DATE, LitColor::WHITE, LitFeastType::FIXED, $row->GRADE, $row->COMMON );
                $this->Cal->addFestivity( $row->TAG, $festivity );
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
                    _( "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d." ),
                    LitGrade::i18n( $festivity->grade, $this->LITSETTINGS->LOCALE, false ),
                    $festivity->name,
                    $this->LITSETTINGS->LOCALE === 'LA' ? ( $festivity->date->format( 'j' ) . ' ' . LITCAL_MESSAGES::LATIN_MONTHS[ (int)$festivity->date->format( 'n' ) ] ) :
                        ( $this->LITSETTINGS->LOCALE === 'EN' ? $festivity->date->format( 'F jS' ) :
                            $this->dayAndMonth->format( $festivity->date->format( 'U' ) )
                        ),
                    $row->yearSince,
                    $row->DECREE,
                    $this->LITSETTINGS->YEAR
                );
            }
            else{
                $this->handleCoincidenceDecree( $row );
            }
        }
    }

    //After the canonization of Pope Saint John XXIII and Pope Saint John Paul II
    //with decree of May 29 2014 the Congregation for Divine Worship
    //inserted the optional memorials for each in the Universal Calendar
    //on October 11 and October 22 respectively
    //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20140529_decreto-calendario-generale-gxxiii-gpii_la.html
    private function applyOptionalMemorialDecree2014() : void {
        $rows = [];
        $rows[0] = new stdClass();
        $rows[0]->TAG = "StJohnXXIII";
        $names = [
            "LA"   => "S. Ioannis XXIII, papæ",
            "IT"   => "San Giovanni XXIII, papa",
            "EN"   => "Saint John XXIII, pope"
        ];
        $rows[0]->NAME = $names[ $this->LITSETTINGS->LOCALE ];
        $rows[0]->GRADE = LitGrade::MEMORIAL_OPT;
        $rows[0]->DATE = DateTime::createFromFormat( '!j-n-Y', '11-10-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        $rows[0]->DECREE = '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20140529_decreto-calendario-generale-gxxiii-gpii_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>';
        $rows[0]->COMMON = "Pastors:For a Pope";
        $rows[0]->yearSince = 2014;

        $rows[1] = new stdClass();
        $rows[1]->TAG = "StJohnPaulII";
        $names = [
            "LA"   => "S. Ioannis Pauli II, papæ",
            "IT"   => "San Giovanni Paolo II, papa",
            "EN"   => "Saint John Paul II, pope"
        ];
        $rows[1]->NAME = $names[ $this->LITSETTINGS->LOCALE ];
        $rows[1]->GRADE = LitGrade::MEMORIAL_OPT;
        $rows[1]->DATE = DateTime::createFromFormat( '!j-n-Y', '22-10-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        $rows[1]->DECREE = '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20140529_decreto-calendario-generale-gxxiii-gpii_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>';
        $rows[1]->COMMON = "Pastors:For a Pope";
        $rows[1]->yearSince = 2014;

        $this->addPreparedRows( $rows );
    }

    private function applyOptionalMemorialDecree2019() : void {
        $rows = [];

        //With the Decree of the Congregation of Divine Worship of Oct 7, 2019,
        //the optional memorial of the Blessed Virgin Mary of Loreto was added on Dec 10
        //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20191007_decreto-celebrazione-verginediloreto_la.html
        $rows[0] = new stdClass();
        $rows[0]->TAG = "LadyLoreto";
        $names = [
            "LA"   => "Beatæ Mariæ Virginis de Loreto",
            "IT"   => "Beata Maria Vergine di Loreto",
            "EN"   => "Blessed Virgin Mary of Loreto"
        ];
        $rows[0]->NAME = $names[ $this->LITSETTINGS->LOCALE ];
        $rows[0]->GRADE = LitGrade::MEMORIAL_OPT;
        $rows[0]->COMMON = "Blessed Virgin Mary";
        $rows[0]->DATE = DateTime::createFromFormat( '!j-n-Y', '10-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        $rows[0]->DECREE = '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20191007_decreto-celebrazione-verginediloreto_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>';
        $rows[0]->yearSince = 2019;

        //With the Decree of the Congregation of Divine Worship of January 25 2019,
        //the optional memorial of Saint Paul VI, Pope was added on May 29
        //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20190125_decreto-celebrazione-paolovi_la.html
        $rows[1] = new stdClass();
        $rows[1]->TAG = "StPaulVI";
        $names = [
            "LA"   => "Sancti Pauli VI, Papæ",
            "IT"   => "San Paolo VI, Papa",
            "EN"   => "Saint Paul VI, Pope"
        ];
        $rows[1]->NAME = $names[ $this->LITSETTINGS->LOCALE ];
        $rows[1]->GRADE = LitGrade::MEMORIAL_OPT;
        $rows[1]->COMMON = "Pastors:For a Pope";
        $rows[1]->DATE = DateTime::createFromFormat( '!j-n-Y', '29-5-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        $rows[1]->DECREE = '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20190125_decreto-celebrazione-paolovi_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>';
        $rows[1]->yearSince = 2019;

        $this->addPreparedRows( $rows );
    }

    //With the Decree of the Congregation of Divine Worship of May 20, 2020, the optional memorial of St. Faustina was added on Oct 5
    //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20200518_decreto-celebrazione-santafaustina_la.html
    private function applyOptionalMemorialDecree2020() : void {
        $row = new stdClass();
        $row->TAG = "StFaustinaKowalska";
        $names = [
            "LA"   => "Sanctæ Faustinæ Kowalska",
            "IT"   => "Santa Faustina Kowalska",
            "EN"   => "Saint Faustina Kowalska"
        ];
        $row->NAME = $names[ $this->LITSETTINGS->LOCALE ];
        $row->GRADE = LitGrade::MEMORIAL_OPT;
        $row->COMMON = "Holy Men and Women:For Religious";
        $row->DATE = DateTime::createFromFormat( '!j-n-Y', '5-10-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        $row->DECREE = '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20200518_decreto-celebrazione-santafaustina_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>';
        $row->yearSince = 2020;

        $this->addPreparedRows( [ $row ] );
    }

    private function applyOptionalMemorialDecree2021() {

        //With the Decree of the Congregation for Divine Worship on January 25, 2021,
        //the optional memorials of Gregory of Narek, John of Avila, and Hildegard of Bingen were added to the universal roman calendar
        //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20210125_decreto-dottori_la.html
        $rows = [];
        $rows[0] = new stdClass();
        $rows[0]->TAG = "StGregoryNarek";
        $names = [
            "LA"       => "Sancti Gregorii Narecensis, abbatis et Ecclesiæ doctoris",
            "IT"       => "San Gregorio di Narek, abate e dottore della Chiesa",
            "EN"       => "Saint Gregory of Narek"
        ];
        $rows[0]->NAME = $names[ $this->LITSETTINGS->LOCALE ];
        $rows[0]->GRADE = LitGrade::MEMORIAL_OPT;
        $rows[0]->DATE = DateTime::createFromFormat( '!j-n-Y', '27-2-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        $rows[0]->DECREE = '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20210125_decreto-dottori_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>';
        $rows[0]->COMMON = "Holy Men and Women:For an Abbot,Doctors";
        $rows[0]->yearSince = 2021;

        $rows[1] = new stdClass();
        $rows[1]->TAG = "StJohnAvila";
        $names = [
            "LA"       => "Sancti Ioannis De Avila, presbyteri et Ecclesiæ doctoris",
            "IT"       => "San Giovanni d'Avila, sacerdote e dottore della Chiesa",
            "EN"       => "Saint John of Avila, priest and doctor of the Church",
        ];
        $rows[1]->NAME = $names[ $this->LITSETTINGS->LOCALE ];
        $rows[1]->GRADE = LitGrade::MEMORIAL_OPT;
        $rows[1]->DATE = DateTime::createFromFormat( '!j-n-Y', '10-5-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        $rows[1]->DECREE = '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20210125_decreto-dottori_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>';
        $rows[1]->COMMON = "Pastors:For One Pastor,Doctors";
        $rows[1]->yearSince = 2021;

        $rows[2] = new stdClass();
        $rows[2]->TAG = "StHildegardBingen";
        $names = [
            "LA"       => "Sanctæ Hildegardis Bingensis, virginis et Ecclesiæ doctoris",
            "IT"       => "Santa Ildegarda de Bingen, vergine e dottore delle Chiesa",
            "EN"       => "Saint Hildegard of Bingen, virgin and doctor of the Church",
        ];
        $rows[2]->NAME = $names[ $this->LITSETTINGS->LOCALE ];
        $rows[2]->GRADE = LitGrade::MEMORIAL_OPT;
        $rows[2]->DATE = DateTime::createFromFormat( '!j-n-Y', '17-9-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        $rows[2]->DECREE = '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20210125_decreto-dottori_' . strtolower( $this->LITSETTINGS->LOCALE ) . '.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>';
        $rows[2]->COMMON = "Virgins:For One Virgin,Doctors";
        $rows[2]->yearSince = 2021;

        $this->addPreparedRows( $rows );
    }

    //13. Weekdays of Advent up until Dec. 16 included ( already calculated and defined together with weekdays 17 Dec. - 24 Dec. )
    //    Weekdays of Christmas season from 2 Jan. until the Saturday after Epiphany
    //    Weekdays of the Easter season, from the Monday after the Octave of Easter to the Saturday before Pentecost
    private function calculateWeekdaysMajorSeasons() : void {
        $DoMEaster = $this->Cal->getFestivity( "Easter" )->date->format( 'j' );      //day of the month of Easter
        $MonthEaster = $this->Cal->getFestivity( "Easter" )->date->format( 'n' );    //month of Easter
        //let's start cycling dates one at a time starting from Easter itself
        $weekdayEaster = DateTime::createFromFormat( '!j-n-Y', $DoMEaster . '-' . $MonthEaster . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        $weekdayEasterCnt = 1;
        while ( $weekdayEaster >= $this->Cal->getFestivity( "Easter" )->date && $weekdayEaster < $this->Cal->getFestivity( "Pentecost" )->date ) {
            $weekdayEaster = DateTime::createFromFormat( '!j-n-Y', $DoMEaster . '-' . $MonthEaster . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) )->add( new DateInterval( 'P' . $weekdayEasterCnt . 'D' ) );
            if ( $this->Cal->notInSolemnitiesFeastsOrMemorials( $weekdayEaster ) && self::DateIsNotSunday( $weekdayEaster ) ) {
                $upper =  (int)$weekdayEaster->format( 'z' );
                $diff = $upper - (int)$this->Cal->getFestivity( "Easter" )->date->format( 'z' ); //day count between current day and Easter Sunday
                $currentEasterWeek = ( ( $diff - $diff % 7 ) / 7 ) + 1;         //week count between current day and Easter Sunday
                $ordinal = ucfirst( LITCAL_MESSAGES::getOrdinal( $currentEasterWeek, $this->LITSETTINGS->LOCALE, $this->formatterFem, LITCAL_MESSAGES::LATIN_ORDINAL_FEM_GEN ) );
                $dayOfTheWeek = $this->LITSETTINGS->LOCALE === 'LA' ? LITCAL_MESSAGES::LATIN_DAYOFTHEWEEK[ $weekdayEaster->format( 'w' ) ] : ucfirst( $this->dayOfTheWeek->format( $weekdayEaster->format( 'U' ) ) );
                $t = $this->LITSETTINGS->LOCALE === 'LA' ? sprintf( "Hebdomadæ %s Temporis Paschali", $ordinal ) : sprintf( _( "of the %s Week of Easter" ), $ordinal );
                $name = $dayOfTheWeek . " " . $t;
                $festivity = new Festivity( $name, $weekdayEaster, LitColor::WHITE, LitFeastType::MOBILE );
                $festivity->psalterWeek = $this->Cal::psalterWeek( $currentEasterWeek );
                $this->Cal->addFestivity( "EasterWeekday" . $weekdayEasterCnt, $festivity );
            }
            $weekdayEasterCnt++;
        }
    }

    //    Weekdays of Ordinary time
    private function calculateWeekdaysOrdinaryTime() : void {

        //In the first part of the year, weekdays of ordinary time begin the day after the Baptism of the Lord
        $FirstWeekdaysLowerLimit = $this->Cal->getFestivity( "BaptismLord" )->date;
        //and end with Ash Wednesday
        $FirstWeekdaysUpperLimit = $this->Cal->getFestivity( "AshWednesday" )->date;

        $ordWeekday = 1;
        $currentOrdWeek = 1;
        $firstOrdinary = DateTime::createFromFormat( '!j-n-Y', $this->BaptismLordFmt, new DateTimeZone( 'UTC' ) )->modify( $this->BaptismLordMod );
        $firstSunday = DateTime::createFromFormat( '!j-n-Y', $this->BaptismLordFmt, new DateTimeZone( 'UTC' ) )->modify( $this->BaptismLordMod )->modify( 'next Sunday' );
        $dayFirstSunday =  (int)$firstSunday->format( 'z' );

        while ( $firstOrdinary >= $FirstWeekdaysLowerLimit && $firstOrdinary < $FirstWeekdaysUpperLimit ) {
            $firstOrdinary = DateTime::createFromFormat( '!j-n-Y', $this->BaptismLordFmt, new DateTimeZone( 'UTC' ) )->modify( $this->BaptismLordMod )->add( new DateInterval( 'P' . $ordWeekday . 'D' ) );
            if ( $this->Cal->notInSolemnitiesFeastsOrMemorials( $firstOrdinary ) ) {
                //The Baptism of the Lord is the First Sunday, so the weekdays following are of the First Week of Ordinary Time
                //After the Second Sunday, let's calculate which week of Ordinary Time we're in
                if ( $firstOrdinary > $firstSunday ) {
                    $upper          = (int)$firstOrdinary->format( 'z' );
                    $diff           = $upper - $dayFirstSunday;
                    $currentOrdWeek = ( ( $diff - $diff % 7 ) / 7 ) + 2;
                }
                $ordinal = ucfirst( LITCAL_MESSAGES::getOrdinal( $currentOrdWeek, $this->LITSETTINGS->LOCALE, $this->formatterFem,LITCAL_MESSAGES::LATIN_ORDINAL_FEM_GEN ) );
                $dayOfTheWeek = $this->LITSETTINGS->LOCALE === 'LA' ? LITCAL_MESSAGES::LATIN_DAYOFTHEWEEK[ $firstOrdinary->format( 'w' ) ] : ucfirst( $this->dayOfTheWeek->format( $firstOrdinary->format( 'U' ) ) );
                $nthStr = $this->LITSETTINGS->LOCALE === 'LA' ? sprintf( "Hebdomadæ %s Temporis Ordinarii", $ordinal ) : sprintf( _( "of the %s Week of Ordinary Time" ), $ordinal );
                $name = $dayOfTheWeek . " " . $nthStr;
                $festivity = new Festivity( $name, $firstOrdinary, LitColor::GREEN, LitFeastType::MOBILE );
                $festivity->psalterWeek = $this->Cal::psalterWeek( $currentOrdWeek );
                $this->Cal->addFestivity( "FirstOrdWeekday" . $ordWeekday, $festivity );
            }
            $ordWeekday++;
        }


        //In the second part of the year, weekdays of ordinary time begin the day after Pentecost
        $SecondWeekdaysLowerLimit = $this->Cal->getFestivity( "Pentecost" )->date;
        //and end with the Feast of Christ the King
        $SecondWeekdaysUpperLimit = DateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' )->sub( new DateInterval( 'P' . ( 3 * 7 ) . 'D' ) );

        $ordWeekday = 1;
        //$currentOrdWeek = 1;
        $lastOrdinary = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P' . ( 7 * 7 ) . 'D' ) );
        $dayLastSunday =  (int)DateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' )->sub( new DateInterval( 'P' . ( 3 * 7 ) . 'D' ) )->format( 'z' );

        while ( $lastOrdinary >= $SecondWeekdaysLowerLimit && $lastOrdinary < $SecondWeekdaysUpperLimit ) {
            $lastOrdinary = LitCalFf::calcGregEaster( $this->LITSETTINGS->YEAR )->add( new DateInterval( 'P' . ( 7 * 7 + $ordWeekday ) . 'D' ) );
            if ( $this->Cal->notInSolemnitiesFeastsOrMemorials( $lastOrdinary ) ) {
                $lower          = (int)$lastOrdinary->format( 'z' );
                $diff           = $dayLastSunday - $lower; //day count between current day and Christ the King Sunday
                $weekDiff       = ( ( $diff - $diff % 7 ) / 7 ); //week count between current day and Christ the King Sunday;
                $currentOrdWeek = 34 - $weekDiff;

                $ordinal = ucfirst( LITCAL_MESSAGES::getOrdinal( $currentOrdWeek, $this->LITSETTINGS->LOCALE, $this->formatterFem,LITCAL_MESSAGES::LATIN_ORDINAL_FEM_GEN ) );
                $dayOfTheWeek = $this->LITSETTINGS->LOCALE === 'LA' ? LITCAL_MESSAGES::LATIN_DAYOFTHEWEEK[ $lastOrdinary->format( 'w' ) ] : ucfirst( $this->dayOfTheWeek->format( $lastOrdinary->format( 'U' ) ) );
                $nthStr = $this->LITSETTINGS->LOCALE === 'LA' ? sprintf( "Hebdomadæ %s Temporis Ordinarii", $ordinal ) : sprintf( _( "of the %s Week of Ordinary Time" ), $ordinal );
                $name = $dayOfTheWeek . " " . $nthStr;
                $festivity = new Festivity( $name, $lastOrdinary, LitColor::GREEN, LitFeastType::MOBILE );
                $festivity->psalterWeek = $this->Cal::psalterWeek( $currentOrdWeek );
                $this->Cal->addFestivity( "LastOrdWeekday" . $ordWeekday, $festivity );
            }
            $ordWeekday++;
        }

    }

    //On Saturdays in Ordinary Time when there is no obligatory memorial, an optional memorial of the Blessed Virgin Mary is allowed.
    //So we have to cycle through all Saturdays of the year checking if there isn't an obligatory memorial
    //First we'll find the first Saturday of the year ( to do this we actually have to find the last Saturday of the previous year,
    // so that our cycle using "next Saturday" logic will actually start from the first Saturday of the year ),
    // and then continue for every next Saturday until we reach the last Saturday of the year
    private function calculateSaturdayMemorialBVM() : void {
        $currentSaturday = new DateTime( "previous Saturday January {$this->LITSETTINGS->YEAR}", new DateTimeZone( 'UTC' ) );
        $lastSatDT = new DateTime( "last Saturday December {$this->LITSETTINGS->YEAR}", new DateTimeZone( 'UTC' ) );
        $SatMemBVM_cnt = 0;
        while( $currentSaturday <= $lastSatDT ){
            $currentSaturday = DateTime::createFromFormat( '!j-n-Y', $currentSaturday->format( 'j-n-Y' ),new DateTimeZone( 'UTC' ) )->modify( 'next Saturday' );
            if( $this->Cal->notInSolemnitiesFeastsOrMemorials( $currentSaturday ) ) {
                $memID = "SatMemBVM" . ++$SatMemBVM_cnt;
                $name = $this->LITSETTINGS->LOCALE === 'LA' ? "Memoria Sanctæ Mariæ in Sabbato" : _( "Saturday Memorial of the Blessed Virgin Mary" );
                $festivity = new Festivity( $name, $currentSaturday, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::MEMORIAL_OPT, "Blessed Virgin Mary" );
                $this->Cal->addFestivity( $memID, $festivity );
            }
        }
    }

    private function applyCalendarItaly() : void {
        $this->applyPatronSaintsEurope();
        $this->applyPatronSaintsItaly();
        if( $this->LITSETTINGS->YEAR >= 1983 && $this->LITSETTINGS->YEAR < 2002 ) {
            $this->readPropriumDeSanctisJSONData( RomanMissal::ITALY_EDITION_1983 );
            //The extra liturgical events found in the 1983 edition of the Roman Missal in Italian,
            //were then incorporated into the Latin edition in 2002 ( effectively being incorporated into the General Roman Calendar )
            //so when dealing with Italy, we only need to add them from 1983 until 2002, after which it's taken care of by the General Calendar
            $this->applyMessaleRomano1983();
        }

        //The Sanctorale in the 2020 edition Messale Romano is based on the Latin 2008 Edition,
        // there isn't really anything different from preceding editions or from the 2008 edition
    }

    private function makePatron( string $tag, string $nameSuffix, int $day, int $month, string $color, string $EditionRomanMissal = ROMANMISSAL::EDITIO_TYPICA_1970 ) {
        $festivity = $this->Cal->getFestivity( $tag );
        if( $festivity !== null ) {
            $this->Cal->setProperty( $tag, "grade", LitGrade::FEAST );
            $this->Cal->setProperty( $tag, "name", $festivity->name . $nameSuffix );
            $this->Cal->setProperty( $tag, "common", "Proper" );
        } else{

            //check what's going on, for example, if it's a Sunday or Solemnity
            $currentFeastDate = DateTime::createFromFormat( '!j-n-Y', "{$day}-{$month}-" . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );

            $row = $this->tempCal[ $EditionRomanMissal ][ $tag ];
            //let's also get the name back from the database, so we can give some feedback and maybe even recreate the festivity
            $FestivityName = $row->NAME . $nameSuffix;

            if( $this->Cal->inSolemnitiesFeastsOrMemorials( $currentFeastDate ) || self::DateIsSunday( $currentFeastDate ) ) {
                $coincidingFestivity = new stdClass();
                $coincidingFestivity->event = $this->Cal->solemnityFromDate( $currentFeastDate );
                if ( self::DateIsSunday( $currentFeastDate ) && $coincidingFestivity->event->grade < LitGrade::SOLEMNITY ){
                    //it's a Sunday
                    $coincidingFestivity->grade = $this->LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst( $this->dayOfTheWeek->format( $currentFeastDate->format( 'U' ) ) );
                } else if ( $this->Cal->inSolemnities( $currentFeastDate ) ) {
                    //it's a Feast of the Lord or a Solemnity
                    $coincidingFestivity->grade = ( $coincidingFestivity->event->grade > LitGrade::SOLEMNITY ? '<i>' . LitGrade::i18n( $coincidingFestivity->event->grade, $this->LITSETTINGS->LOCALE, false ) . '</i>' : LitGrade::i18n( $coincidingFestivity->grade, $this->LITSETTINGS->LOCALE, false ) );
                } else if ( $this->Cal->inFeastsOrMemorials( $currentFeastDate ) ) {
                    //we should probably be able to create it anyways in this case?
                    $this->Cal->addFestivity( $tag, new Festivity( $FestivityName, $currentFeastDate, $color, LitFeastType::FIXED, LitGrade::FEAST, "Proper" ) );
                    $coincidingFestivity->grade = LitGrade::i18n( $coincidingFestivity->event->grade, $this->LITSETTINGS->LOCALE, false );
                }

                $this->Messages[] =  '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                    _( "The %s '%s', usually celebrated on %s, is suppressed by the %s '%s' in the year %d." ),
                    LitGrade::i18n( LitGrade::FEAST, $this->LITSETTINGS->LOCALE, false ),
                    $FestivityName,
                    $this->dayAndMonth->format( $currentFeastDate->format( 'U' ) ),
                    $coincidingFestivity->grade,
                    $coincidingFestivity->event->name,
                    $this->LITSETTINGS->YEAR
                );

            }
        }
    }


    //Insert or elevate the Patron Saints of Europe
    private function applyPatronSaintsEurope() : void {

        //Saint Benedict, Saint Bridget, and Saint Cyril and Methodius elevated to Feast, with title "patrono/i d'Europa" added
        //then from 1999, Saint Catherine of Siena and Saint Edith Stein, elevated to Feast with title "compatrona d'Europa" added
        $this->makePatron( "StBenedict", ", patrono d'Europa", 11, 7, LitColor::WHITE );
        $this->makePatron( "StBridget", ", patrona d'Europa", 23, 7, LitColor::WHITE );
        $this->makePatron( "StsCyrilMethodius", ", patroni d'Europa", 14, 2, LitColor::WHITE );

        //In 1999, Pope John Paul II elevated Catherine of Siena from patron of Italy to patron of Europe
        if( $this->LITSETTINGS->YEAR >= 1999 ){
            $this->makePatron( "StCatherineSiena", ", patrona d'Italia e d'Europa", 29, 4, LitColor::WHITE );
            if( $this->LITSETTINGS->YEAR >= 2002 ){
                $this->makePatron( "StEdithStein", ", patrona d'Europa", 9, 8, LitColor::WHITE, ROMANMISSAL::EDITIO_TYPICA_TERTIA_2002 );
            } else {
                //between 1999 and 2002 we have to manually create StEdithStein
                //since the makePatron method expects to find data from the Missals,
                //we are going to have to fake this one as belonging to a Missal...
                //let's add it to the future Missal that doesn't exist yet
                $EdithStein = new stdClass();
                $EdithStein->NAME = "Santa Teresa Benedetta della Croce (Edith Stein), vergine e martire";
                $EdithStein->MONTH = 8;
                $EdithStein->DAY    = 9;
                $EdithStein->TAG    = "StEdithStein";
                $EdithStein->GRADE  = 2;
                $EdithStein->COMMON = "Martyrs:For a Virgin Martyr,Virgins:For One Virgin";
                $EdithStein->CALENDAR   = "GENERAL ROMAN";
                $EdithStein->COLOR  = "white,red";
                $this->tempCal[ RomanMissal::EDITIO_TYPICA_TERTIA_2002 ][ "StEdithStein" ] = $EdithStein;
                $EdithStein->DATE = DateTime::createFromFormat( '!j-n-Y', $EdithStein->DAY . '-' . $EdithStein->MONTH . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
                if( !$this->Cal->inSolemnitiesFeastsOrMemorials( $EdithStein->DATE ) ) {
                    $this->Cal->addFestivity( $EdithStein->TAG, new Festivity( $EdithStein->NAME, $EdithStein->DATE, $EdithStein->COLOR, LitFeastType::FIXED, $EdithStein->GRADE, $EdithStein->COMMON ) );
                    $this->makePatron( "StEdithStein", ", patrona d'Europa", $EdithStein->DAY, $EdithStein->MONTH, $EdithStein->COLOR, ROMANMISSAL::EDITIO_TYPICA_TERTIA_2002 );
                }
            }
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
        //we have no solemnities or feasts in this data, at the most memorials
        foreach ( $this->tempCal[ RomanMissal::ITALY_EDITION_1983 ] as $row ) {
            $currentFeastDate = DateTime::createFromFormat( '!j-n-Y', $row->DAY . '-' . $row->MONTH . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
            if( !$this->Cal->inSolemnitiesOrFeasts( $currentFeastDate ) ) {
                $festivity = new Festivity( "[ ITALIA ] " . $row->NAME, $currentFeastDate, $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON, $row->DISPLAYGRADE );
                $this->Cal->addFestivity( $row->TAG, $festivity );
            }
            else{
                $coincidingFestivity = $this->Cal->determineSundaySolemnityOrFeast( $currentFeastDate, $this->LITSETTINGS );
                $this->Messages[] = sprintf(
                    "ITALIA: la %s '%s' (%s), aggiunta al calendario nell'edizione del Messale Romano del 1983 pubblicata dalla CEI, è soppressa dalla %s '%s' nell'anno %d",
                    $row->DISPLAYGRADE !== "" ? $row->DISPLAYGRADE : LitGrade::i18n( $row->GRADE, $this->LITSETTINGS->LOCALE, false ),
                    '<i>' . $row->NAME . '</i>',
                    $this->dayAndMonth->format( $currentFeastDate->format( 'U' ) ),
                    $coincidingFestivity->grade,
                    $coincidingFestivity->event->name,
                    $this->LITSETTINGS->YEAR
                );
            }
        }
    }

    private function applyCalendarUSA() : void {

        //The Solemnity of the Immaculate Conception is the Patronal FeastDay of the United States of America
        $festivity = $this->Cal->getFestivity( "ImmaculateConception" );
        if( $festivity !== null ) {
            $this->Cal->setProperty( "ImmaculateConception", "name", $festivity->name . ", Patronal feastday of the United States of America" );
        }

        //move Saint Vincent Deacon from Jan 22 to Jan 23 in order to allow for National Day of Prayer for the Unborn on Jan 22
        //however if Jan 22 is a Sunday, National Day of Prayer for the Unborn is moved to Jan 23 ( in place of Saint Vincent Deacon )
        $festivity = $this->Cal->getFestivity( "StVincentDeacon" );
        if( $festivity !== null ){
            //I believe we don't have to worry about suppressing, because if it's on a Sunday it won't exist already
            //so if the National Day of Prayer happens on a Sunday and must be moved to Monday, Saint Vincent will be already gone anyways
            $StVincentDeaconNewDate = $festivity->date->add( new DateInterval( 'P1D' ) );
            $this->Cal->moveFestivityDate( "StVincentDeacon", $StVincentDeaconNewDate );
            //let's not worry about translating these messages, just leave them in English
            $this->Messages[] = sprintf(
                "USA: The Memorial '%s' was moved from Jan 22 to Jan 23 to make room for the National Day of Prayer for the Unborn, as per the 2011 Roman Missal issued by the USCCB",
                '<i>' . $festivity->name . '</i>'
            );
            $this->Cal->setProperty( "StVincentDeacon", "name", "[ USA ] " . $festivity->name );
        }

        $festivity = $this->Cal->getFestivity( "StsJeanBrebeuf" );
        if( $festivity !== null ) {
            //if it exists, it means it's not on a Sunday, so we can go ahead and elevate it to Memorial
            $this->Cal->setProperty( "StsJeanBrebeuf", "grade", LitGrade::MEMORIAL );
            $this->Messages[] = sprintf(
                "USA: The optional memorial '%s' is elevated to Memorial on Oct 19 as per the 2011 Roman Missal issued by the USCCB, applicable to the year %d",
                '<i>' . $festivity->name . '</i>',
                $this->LITSETTINGS->YEAR
            );
            $this->Cal->setProperty( "StsJeanBrebeuf", "name", "[ USA ] " . $festivity->name );

            $festivity1 = $this->Cal->getFestivity( "StPaulCross" );
            if( $festivity1 !== null ){ //of course it will exist if StsJeanBrebeuf exists, they are originally on the same day
                $this->Cal->moveFestivityDate( "StPaulCross", $festivity1->date->add( new DateInterval( 'P1D' ) ) );
                if( $this->Cal->inSolemnitiesFeastsOrMemorials( $festivity1->date ) ) {
                    $this->Messages[] = sprintf(
                        "USA: The optional memorial '%s' is transferred from Oct 19 to Oct 20 as per the 2011 Roman Missal issued by the USCCB, to make room for '%s' elevated to the rank of Memorial, however in the year %d it is superseded by a higher ranking liturgical event",
                        '<i>' . $festivity1->name . '</i>',
                        '<i>' . $festivity->name . '</i>',
                        $this->LITSETTINGS->YEAR
                    );
                    $this->Cal->removeFestivity( "StPaulCross" );
                }else{
                    $this->Messages[] = sprintf(
                        'USA: The optional memorial \'%1$s\' is transferred from Oct 19 to Oct 20 as per the 2011 Roman Missal issued by the USCCB, to make room for \'%2$s\' elevated to the rank of Memorial: applicable to the year %3$d.',
                        '<i>' . $festivity1->name . '</i>',
                        '<i>' . $festivity->name . '</i>',
                        $this->LITSETTINGS->YEAR
                    );
                    $this->Cal->setProperty( "StPaulCross", "name", "[ USA ] " . $festivity1->name );
                }
            }
        }
        else{
            //if Oct 19 is a Sunday or Solemnity, Saint Paul of the Cross won't exist. But it still needs to be moved to Oct 20 so we must create it again
            //just keep in mind the StsJeanBrebeuf also won't exist, so we need to retrieve the name from the tempCal
            $currentFeastDate = DateTime::createFromFormat( '!j-n-Y', '20-10-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
            $festivity = $this->Cal->getFestivity( "StPaulCross" );
            if( !$this->Cal->inSolemnities( $currentFeastDate ) && $festivity === null ) {
                $row = $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ][ "StPaulCross" ];
                $row2 = $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ][ "StsJeanBrebeuf" ];
                $festivity = new Festivity( "[ USA ] " . $row->NAME, $currentFeastDate, $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON );
                $this->Cal->addFestivity( "StPaulCross", $festivity );
                $this->Messages[] = sprintf(
                    'USA: The optional memorial \'%1$s\' is transferred from Oct 19 to Oct 20 as per the 2011 Roman Missal issued by the USCCB, to make room for \'%2$s\' elevated to the rank of Memorial: applicable to the year %3$d.',
                    $row->NAME,
                    '<i>' . $row2->NAME . '</i>',
                    $this->LITSETTINGS->YEAR
                );
            }
        }

        //The fourth Thursday of November is Thanksgiving
        $thanksgivingDateTS = strtotime( 'fourth thursday of november ' . $this->LITSETTINGS->YEAR . ' UTC' );
        $thanksgivingDate = new DateTime( "@$thanksgivingDateTS", new DateTimeZone( 'UTC' ) );
        $festivity = new Festivity( "[ USA ] Thanksgiving", $thanksgivingDate, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::MEMORIAL, '', 'National Holiday' );
        $this->Cal->addFestivity( "ThanksgivingDay", $festivity );

        $currentFeastDate = DateTime::createFromFormat( '!j-n-Y', '18-7-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        $this->moveFestivityDate( "StCamillusDeLellis", $currentFeastDate, "Blessed Kateri Tekakwitha" );

        $currentFeastDate = DateTime::createFromFormat( '!j-n-Y', '5-7-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
        $this->moveFestivityDate( "StElizabethPortugal", $currentFeastDate, "Independence Day" );

        $this->readPropriumDeSanctisJSONData( RomanMissal::USA_EDITION_2011 );

        foreach ( $this->tempCal[ RomanMissal::USA_EDITION_2011 ] as $row ) {
            $currentFeastDate = DateTime::createFromFormat( '!j-n-Y', $row->DAY . '-' . $row->MONTH . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
            if( !$this->Cal->inSolemnities( $currentFeastDate ) ) {
                $festivity = new Festivity( "[ USA ] " . $row->NAME, $currentFeastDate, $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON, $row->DISPLAYGRADE );
                $this->Cal->addFestivity( $row->TAG, $festivity );
            }
            else if( self::DateIsSunday( $currentFeastDate ) && $row->TAG === "PrayerUnborn" ){
                $festivity = new Festivity( "[ USA ] " . $row->NAME, $currentFeastDate->add( new DateInterval( 'P1D' ) ), $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON, $row->DISPLAYGRADE );
                $this->Cal->addFestivity( $row->TAG, $festivity );
                $this->Messages[] = sprintf(
                    "USA: The National Day of Prayer for the Unborn is set to Jan 22 as per the 2011 Roman Missal issued by the USCCB, however since it coincides with a Sunday or a Solemnity in the year %d, it has been moved to Jan 23",
                    $this->LITSETTINGS->YEAR
                );
            }
            else{
                $this->Messages[] = sprintf(
                    "USA: the %s '%s', added to the calendar as per the 2011 Roman Missal issued by the USCCB, is superseded by a Sunday or a Solemnity in the year %d",
                    $row->DISPLAYGRADE !== "" ? $row->DISPLAYGRADE : LitGrade::i18n( $row->GRADE, $this->LITSETTINGS->LOCALE, false ),
                    '<i>' . $row->NAME . '</i>',
                    $this->LITSETTINGS->YEAR
                );
            }
        }

    }

    /**currently only using this for the USA calendar
     * The celebrations being transferred are all from the 1970 Editio Typica
     * 
     * If it were to become useful for other national calendars,
     * we might have to abstract out the Calendar that is the source
     * of the festivity that is being transferred
     */
    private function moveFestivityDate( string $tag, DateTime $newDate, string $inFavorOf ) {
        $festivity = $this->Cal->getFestivity( $tag );
        $oldDateStr = $festivity->date->format('F jS');
        $newDateStr = $newDate->format('F jS');
        if( !$this->Cal->inSolemnities( $newDate ) ) {
            if( $festivity !== null ){
                //Move from old date to new date, to make room for another celebration
                $this->Cal->moveFestivityDate( $tag, $newDate );
            }
            else{
                //if it was suppressed on the original date because of a higher ranking celebration,
                //we should recreate it on the new date
                $row = $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ][ $tag ];
                $festivity = new Festivity( $row->NAME, $newDate, $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON );
                $this->Cal->addFestivity( $tag, $festivity );
            }
            $this->Messages[] = sprintf(
                'USA: The optional memorial \'%1$s\' is transferred from %4$s to %5$s as per the 2011 Roman Missal issued by the USCCB, to make room for the Memorial \'%2$s\': applicable to the year %3$d.',
                '<i>' . $festivity->name . '</i>',
                '<i>' . $inFavorOf . '</i>',
                $this->LITSETTINGS->YEAR,
                $oldDateStr,
                $newDateStr
            );
            $this->Cal->setProperty( $tag, "name", "[ USA ] " . $festivity->name );
        }
        else{
            if( $festivity !== null ){
                //If the new date is already covered by a Solmenity, then we can't move the celebration, so we simply suppress it
                $this->Messages[] = sprintf(
                    'USA: The optional memorial \'%1$s\' is transferred from %4$s to %5$s as per the 2011 Roman Missal issued by the USCCB, to make room for the Memorial \'%2$s\', however it is superseded by a higher ranking festivity in the year %3$d.',
                    '<i>' . $festivity->name . '</i>',
                    '<i>' . $inFavorOf . '</i>',
                    $this->LITSETTINGS->YEAR,
                    $oldDateStr,
                    $newDateStr
                );
                $this->Cal->removeFestivity( $tag );
            }
        }
    }

    private static function DateIsSunday( DateTime $dt ) : bool {
        return (int)$dt->format( 'N' ) === 7;
    }

    private static function DateIsNotSunday( DateTime $dt ) : bool {
        return (int)$dt->format( 'N' ) !== 7;
    }

    private function calculateUniversalCalendar() : void {

        $this->populatePropriumDeTempore();
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
        $this->readPropriumDeSanctisJSONData( ROMANMISSAL::EDITIO_TYPICA_1970 );
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
        $this->calculateMemorials( LitGrade::MEMORIAL, RomanMissal::EDITIO_TYPICA_1970 );

        if ( $this->LITSETTINGS->YEAR >= 1998 ) {
            //St Therese of the Child Jesus was proclaimed a Doctor of the Church in 1998
            $this->applyDoctorDecree1998();
        }

        if ( $this->LITSETTINGS->YEAR >= 2002 ) {
            $this->readPropriumDeSanctisJSONData( ROMANMISSAL::EDITIO_TYPICA_TERTIA_2002 );
            $this->calculateMemorials( LitGrade::MEMORIAL, RomanMissal::EDITIO_TYPICA_TERTIA_2002 );
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
        //a ) obligatory memorial of the seconday Patron of a place, of a diocese, of a region or religious province
        //b ) other obligatory memorials in the calendar of a single diocese, order or congregation
        //these will be dealt with later when loading Local Calendar Data

        //12. Optional memorials ( a proper memorial is to be preferred to a general optional memorial ( PC, 23 c ) )
        //  which however can be celebrated even in those days listed at n. 9,
        //  in the special manner described by the General Instructions of the Roman Missal and of the Liturgy of the Hours ( cf pp. 26-27, n. 10 )

        $this->calculateMemorials( LitGrade::MEMORIAL_OPT, RomanMissal::EDITIO_TYPICA_1970 );

        if ( $this->LITSETTINGS->YEAR >= 2002 ) {
            $this->calculateMemorials( LitGrade::MEMORIAL_OPT, RomanMissal::EDITIO_TYPICA_TERTIA_2002 );
        }

        if ( $this->LITSETTINGS->YEAR >= 2008 ) {
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

        //13. Weekdays of Advent up until Dec. 16 included ( already calculated and defined together with weekdays 17 Dec. - 24 Dec. )
        //    Weekdays of Christmas season from 2 Jan. until the Saturday after Epiphany
        //    Weekdays of the Easter season, from the Monday after the Octave of Easter to the Saturday before Pentecost
        //    Weekdays of Ordinary time
        $this->calculateWeekdaysMajorSeasons();
        $this->calculateWeekdaysOrdinaryTime();

        //15. On Saturdays in Ordinary Time when there is no obligatory memorial, an optional memorial of the Blessed Virgin Mary is allowed.
        $this->calculateSaturdayMemorialBVM();

    }

    private function applyDiocesanCalendar() {

        foreach( $this->DiocesanData->LitCal as $key => $obj ) {
            if( is_array( $obj->color ) ) {
                $obj->color = implode( ',', $obj->color );
            }
            //if sinceYear is undefined or null or empty, let's go ahead and create the event in any case
            //creation will be restricted only if explicitly defined by the sinceYear property
            if( $this->LITSETTINGS->YEAR >= $obj->sinceYear || $obj->sinceYear === null || $obj->sinceYear == '' ) {
                $currentFeastDate = DateTime::createFromFormat( '!j-n-Y', $obj->day . '-' . $obj->month . '-' . $this->LITSETTINGS->YEAR, new DateTimeZone( 'UTC' ) );
                if( $obj->grade > LitGrade::FEAST ) {
                    if( $this->Cal->inSolemnities( $currentFeastDate ) && $key != $this->Cal->solemnityKeyFromDate( $currentFeastDate ) ) {
                        //there seems to be a coincidence with a different Solemnity on the same day!
                        //should we attempt to move to the next open slot?
                        $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                            $this->LITSETTINGS->DIOCESAN . ": the Solemnity '%s', proper to the calendar of the " . $this->GeneralIndex->{$this->LITSETTINGS->DIOCESAN}->diocese . " and usually celebrated on %s, coincides with the Sunday or Solemnity '%s' in the year %d! Does something need to be done about this?",
                            '<i>' . $obj->name . '</i>',
                            '<b>' . $this->dayAndMonth->format( $currentFeastDate->format( 'U' ) ) . '</b>',
                            '<i>' . $this->Cal->solemnityFromDate( $currentFeastDate )->name . '</i>',
                            $this->LITSETTINGS->YEAR
                        );
                    }
                    $this->Cal->addFestivity( $this->LITSETTINGS->DIOCESAN . "_" . $key, new Festivity( "[ " . $this->GeneralIndex->{$this->LITSETTINGS->DIOCESAN}->diocese . " ] " . $obj->name, $currentFeastDate, strtolower( $obj->color ), LitFeastType::FIXED, $obj->grade, $obj->common ) );
                } else if ( $obj->grade <= LitGrade::FEAST && !$this->Cal->inSolemnities( $currentFeastDate ) ) {
                    $this->Cal->addFestivity( $this->LITSETTINGS->DIOCESAN . "_" . $key, new Festivity( "[ " . $this->GeneralIndex->{$this->LITSETTINGS->DIOCESAN}->diocese . " ] " . $obj->name, $currentFeastDate, strtolower( $obj->color ), LitFeastType::FIXED, $obj->grade, $obj->common ) );
                } else {
                    $this->Messages[] = sprintf(
                        $this->LITSETTINGS->DIOCESAN . ": the %s '%s', proper to the calendar of the " . $this->GeneralIndex->{$this->LITSETTINGS->DIOCESAN}->diocese . " and usually celebrated on %s, is suppressed by the Sunday or Solemnity %s in the year %d",
                        LitGrade::i18n( $obj->grade, $this->LITSETTINGS->LOCALE, false ),
                        '<i>' . $obj->name . '</i>',
                        '<b>' . $this->dayAndMonth->format( $currentFeastDate->format( 'U' ) ) . '</b>',
                        '<i>' . $this->Cal->solemnityFromDate( $currentFeastDate )->name . '</i>',
                        $this->LITSETTINGS->YEAR
                    );
                }
            }
        }

    }

    private function getGithubReleaseInfo() : stdClass {
        $returnObj = new stdClass();
        $GithubReleasesAPI = "https://api.github.com/repos/Liturgical-Calendar/LiturgicalCalendarAPI/releases/latest";
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $GithubReleasesAPI );
        curl_setopt( $ch, CURLOPT_USERAGENT, 'LiturgicalCalendar' );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        $currentVersionForDownload = curl_exec( $ch );

        if ( curl_errno( $ch ) ) {
          $returnObj->status = "error";
          $returnObj->message = curl_error( $ch );
        }
        curl_close( $ch );

        $GitHubReleasesObj = json_decode( $currentVersionForDownload );
        if( json_last_error() !== JSON_ERROR_NONE ){
            $returnObj->status = "error";
            $returnObj->message = json_last_error_msg();
        } else {
            $returnObj->status = "success";
            $returnObj->obj = $GitHubReleasesObj;
        }
        return $returnObj;
    }

    private function produceIcal( stdClass $SerializeableLitCal, stdClass $GitHubReleasesObj ) : string {

        $publishDate = $GitHubReleasesObj->published_at;
        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "PRODID:-//John Romano D'Orazio//Liturgical Calendar V1.0//EN\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "METHOD:PUBLISH\r\n";
        $ical .= "X-MS-OLK-FORCEINSPECTOROPEN:FALSE\r\n";
        $ical .= "X-WR-CALNAME:Roman Catholic Universal Liturgical Calendar " . strtoupper( $this->LITSETTINGS->LOCALE ) . "\r\n";
        $ical .= "X-WR-TIMEZONE:Europe/Vatican\r\n"; //perhaps allow this to be set through a GET or POST?
        $ical .= "X-PUBLISHED-TTL:PT1D\r\n";
        foreach( $SerializeableLitCal->LitCal as $FestivityKey => $CalEvent ){
            $displayGrade = "";
            $displayGradeHTML = "";
            if( $FestivityKey === 'AllSouls' ){
                $displayGrade = LitGrade::i18n( LitGrade::COMMEMORATION, $this->LITSETTINGS->LOCALE, false );
                $displayGradeHTML = LitGrade::i18n( LitGrade::COMMEMORATION, $this->LITSETTINGS->LOCALE, true );
            }
            else if( (int)$CalEvent->date->format( 'N' ) !==7 ){
                if( property_exists( $CalEvent,'displayGrade' ) && $CalEvent->displayGrade !== "" ){
                    $displayGrade = $CalEvent->displayGrade;
                    $displayGradeHTML = '<B>' . $CalEvent->displayGrade . '</B>';
                } else {
                    $displayGrade = LitGrade::i18n( $CalEvent->grade, $this->LITSETTINGS->LOCALE, false );
                    $displayGradeHTML = LitGrade::i18n( $CalEvent->grade, $this->LITSETTINGS->LOCALE, true );
                }
            }
            else if( (int)$CalEvent->grade > LitGrade::MEMORIAL ){
                if( property_exists( $CalEvent,'displayGrade' ) && $CalEvent->displayGrade !== "" ){
                    $displayGrade = $CalEvent->displayGrade;
                    $displayGradeHTML = '<B>' . $CalEvent->displayGrade . '</B>';
                } else {
                    $displayGrade = LitGrade::i18n( $CalEvent->grade, $this->LITSETTINGS->LOCALE, false );
                    $displayGradeHTML = LitGrade::i18n( $CalEvent->grade, $this->LITSETTINGS->LOCALE, true );
                }
            }

            $description = $this->LitCommon->C( $CalEvent->common );
            $description .=  '\n' . $displayGrade;
            $description .= $CalEvent->color != "" ? '\n' . LITCAL_MESSAGES::ParseColorString( $CalEvent->color, $this->LITSETTINGS->LOCALE, false ) : "";
            $description .= property_exists( $CalEvent,'liturgicalyear' ) && $CalEvent->liturgicalYear !== null && $CalEvent->liturgicalYear != "" ? '\n' . $CalEvent->liturgicalYear : "";
            $htmlDescription = "<P DIR=LTR>" . $this->LitCommon->C( $CalEvent->common );
            $htmlDescription .=  '<BR>' . $displayGradeHTML;
            $htmlDescription .= $CalEvent->color != "" ? "<BR>" . LITCAL_MESSAGES::ParseColorString( $CalEvent->color, $this->LITSETTINGS->LOCALE, true ) : "";
            $htmlDescription .= property_exists( $CalEvent,'liturgicalyear' ) && $CalEvent->liturgicalYear !== null && $CalEvent->liturgicalYear != "" ? '<BR>' . $CalEvent->liturgicalYear . "</P>" : "</P>";
            $ical .= "BEGIN:VEVENT\r\n";
            $ical .= "CLASS:PUBLIC\r\n";
            $ical .= "DTSTART;VALUE=DATE:" . $CalEvent->date->format( 'Ymd' ) . "\r\n";// . "T" . $CalEvent->date->format( 'His' ) . "Z\r\n";
            //$CalEvent->date->add( new DateInterval( 'P1D' ) );
            //$ical .= "DTEND:" . $CalEvent->date->format( 'Ymd' ) . "T" . $CalEvent->date->format( 'His' ) . "Z\r\n";
            $ical .= "DTSTAMP:" . date( 'Ymd' ) . "T" . date( 'His' ) . "Z\r\n";
            /** The event created in the calendar is specific to this year, next year it may be different.
             *  So UID must take into account the year
             *  Next year's event should not cancel this year's event, they are different events
             **/
            $ical .= "UID:" . md5( "LITCAL-" . $FestivityKey . '-' . $CalEvent->date->format( 'Y' ) ) . "\r\n";
            $ical .= "CREATED:" . str_replace( ':' , '', str_replace( '-', '', $publishDate ) ) . "\r\n";
            $desc = "DESCRIPTION:" . str_replace( ',','\,', $description );
            $ical .= strlen( $desc ) > 75 ? rtrim( chunk_split( $desc,71,"\r\n\t" ) ) . "\r\n" : "$desc\r\n";
            $ical .= "LAST-MODIFIED:" . str_replace( ':' , '', str_replace( '-', '', $publishDate ) ) . "\r\n";
            $summaryLang = ";LANGUAGE=" . strtolower( $this->LITSETTINGS->LOCALE ); //strtolower( $this->LITSETTINGS->LOCALE ) === "la" ? "" :
            $summary = "SUMMARY".$summaryLang.":" . str_replace( ',','\,',str_replace( "\r\n"," ", $CalEvent->name ) );
            $ical .= strlen( $summary ) > 75 ? rtrim( chunk_split( $summary,75,"\r\n\t" ) ) . "\r\n" : $summary . "\r\n";
            $ical .= "TRANSP:TRANSPARENT\r\n";
            $ical .= "X-MICROSOFT-CDO-ALLDAYEVENT:TRUE\r\n";
            $ical .= "X-MICROSOFT-DISALLOW-COUNTER:TRUE\r\n";
            $xAltDesc = 'X-ALT-DESC;FMTTYPE=text/html:<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 3.2//EN">\n<HTML>\n<BODY>\n\n';
            $xAltDesc .= str_replace( ',','\,', $htmlDescription );
            $xAltDesc .= '\n\n</BODY>\n</HTML>';
            $ical .= strlen( $xAltDesc ) > 75 ? rtrim( chunk_split( $xAltDesc,71,"\r\n\t" ) ) . "\r\n" : "$xAltDesc\r\n";
            $ical .= "END:VEVENT\r\n";
        }
        $ical .= "END:VCALENDAR";

        return $ical;

    }

    private function generateResponse() {

        $SerializeableLitCal                          = new stdClass();
        $SerializeableLitCal->Settings                = new stdClass();
        $SerializeableLitCal->Metadata                = new stdClass();

        $this->Cal->sortFestivities();
        $SerializeableLitCal->LitCal                  = $this->Cal->getFestivities();
        $SerializeableLitCal->Messages                = $this->Messages;
        $SerializeableLitCal->Settings->YEAR          = $this->LITSETTINGS->YEAR;
        $SerializeableLitCal->Settings->EPIPHANY      = $this->LITSETTINGS->EPIPHANY;
        $SerializeableLitCal->Settings->ASCENSION     = $this->LITSETTINGS->ASCENSION;
        $SerializeableLitCal->Settings->CORPUSCHRISTI = $this->LITSETTINGS->CORPUSCHRISTI;
        $SerializeableLitCal->Settings->LOCALE        = $this->LITSETTINGS->LOCALE;
        $SerializeableLitCal->Settings->RETURNTYPE    = $this->LITSETTINGS->RETURNTYPE;
        if( $this->LITSETTINGS->NATIONAL !== null ){
            $SerializeableLitCal->Settings->NATIONALPRESET = $this->LITSETTINGS->NATIONAL;
        }
        if( $this->LITSETTINGS->DIOCESAN !== null ){
            $SerializeableLitCal->Settings->DIOCESANPRESET = $this->LITSETTINGS->DIOCESAN;
        }

        $SerializeableLitCal->Metadata->SOLEMNITIES       = $this->Cal->getSolemnities();
        $SerializeableLitCal->Metadata->FEASTS_MEMORIALS  = $this->Cal->getFeastsAndMemorials();
        $SerializeableLitCal->Metadata->VERSION           = self::API_VERSION;
        $SerializeableLitCal->Metadata->REQUEST_HEADERS   = $this->APICore->getJsonEncodedRequestHeaders();

        //make sure we have an engineCache folder for the current Version
        if( realpath( "engineCache/v" . str_replace( ".","_",self::API_VERSION ) ) === false ){
            mkdir( "engineCache/v" . str_replace( ".","_",self::API_VERSION ),0755, true );
        }

        switch ( $this->LITSETTINGS->RETURNTYPE ) {
            case RETURN_TYPE::JSON:
                file_put_contents( $this->CACHEFILE, json_encode( $SerializeableLitCal ) );
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
                $infoObj = $this->getGithubReleaseInfo();
                if( $infoObj->status === "success" ) {
                    $ical = $this->produceIcal( $SerializeableLitCal, $infoObj->obj );
                    file_put_contents( $this->CACHEFILE, $ical );
                    echo $ical;
                }
                else{
                    die( 'Error receiving or parsing info from github about latest release: '.$infoObj->message );
                }
                break;
            default:
                file_put_contents( $this->CACHEFILE, json_encode( $SerializeableLitCal ) );
                echo json_encode( $SerializeableLitCal );
                break;
        }
        die();
    }

    private function prepareL10N() : void {
        $localeArray = [
            strtolower( $this->LITSETTINGS->LOCALE ) . '_' . $this->LITSETTINGS->LOCALE . '.utf8',
            strtolower( $this->LITSETTINGS->LOCALE ) . '_' . $this->LITSETTINGS->LOCALE . '.UTF-8',
            strtolower( $this->LITSETTINGS->LOCALE ) . '_' . $this->LITSETTINGS->LOCALE,
            strtolower( $this->LITSETTINGS->LOCALE )
        ];
        setlocale( LC_ALL, $localeArray );
        $this->createFormatters();
        bindtextdomain("litcal", "i18n");
        textdomain("litcal");
        $this->Cal          = new FestivityCollection( $this->LITSETTINGS );
        $this->LitCommon    = new LitCommon( $this->LITSETTINGS->LOCALE );
    }

    public function setCacheDuration( string $duration ) : void {
        switch( $duration ) {
            case CACHEDURATION::DAY:
                $this->CACHEDURATION = "_" . $duration . date( "z" ); //The day of the year ( starting from 0 through 365 )
                break;
            case CACHEDURATION::WEEK:
                $this->CACHEDURATION = "_" . $duration . date( "W" ); //ISO-8601 week number of year, weeks starting on Monday
                break;
            case CACHEDURATION::MONTH:
                $this->CACHEDURATION = "_" . $duration . date( "m" ); //Numeric representation of a month, with leading zeros
                break;
            case CACHEDURATION::YEAR:
                $this->CACHEDURATION = "_" . $duration . date( "Y" ); //A full numeric representation of a year, 4 digits
                break;
        }
    }

    public function setAllowedReturnTypes( array $returnTypes ) : void {
        $this->ALLOWED_RETURN_TYPES = array_values( array_intersect( RETURN_TYPE::$values, $returnTypes ) );
    }

    /**
     * The LitCalEngine will only work once you call the public Init() method
     * Do not change the order of the methods that follow,
     * each one can depend on the one before it in order to function correctly!
     */
    public function Init(){
        $this->APICore->Init();
        $this->initParameterData();
        $this->APICore->setResponseContentTypeHeader();
        $this->loadLocalCalendarData();
        if( $this->cacheFileIsAvailable() ){
            //If we already have done the calculation
            //and stored the results in a cache file
            //then we're done, just output this and die
            echo file_get_contents( $this->CACHEFILE );
            die();
        } else {
            $this->dieIfBeforeMinYear();
            $this->prepareL10N();
            $this->calculateUniversalCalendar();

            if( $this->LITSETTINGS->NATIONAL !== null ) {
                switch( $this->LITSETTINGS->NATIONAL ){
                    case 'ITALY':
                        $this->applyCalendarItaly();
                        break;
                    case 'USA':
                        //I don't have any data before 2011
                        //I need copies of the calendar from the Missals printed before 2011...
                        if( $this->LITSETTINGS->YEAR >= 2011 ) {
                            $this->applyCalendarUSA();
                        }
                        break;
                }
            }

            if( $this->LITSETTINGS->DIOCESAN !== null && $this->DiocesanData !== null ) {
                $this->applyDiocesanCalendar();
            }

            //$this->setCyclesAndVigils();
            $this->Cal->setCyclesAndVigils();
            $this->generateResponse();
        }
    }

}
