<?php

include_once( 'includes/enums/AcceptHeader.php' );
include_once( 'includes/enums/CacheDuration.php' );
include_once( 'includes/enums/LitColor.php' );
include_once( 'includes/enums/LitCommon.php' );
include_once( 'includes/enums/LitFeastType.php' );
include_once( 'includes/enums/LitGrade.php' );
include_once( 'includes/enums/LitSeason.php' );
include_once( 'includes/enums/RequestMethod.php' );
include_once( 'includes/enums/RequestContentType.php' );
include_once( 'includes/enums/ReturnType.php' );
include_once( 'includes/enums/RomanMissal.php' );

include_once( 'includes/APICore.php' );
include_once( "includes/Festivity.php" );
include_once( "includes/FestivityCollection.php" );
include_once( "includes/LitSettings.php" );
include_once( "includes/LitFunc.php" );
include_once( "includes/LitMessages.php" );
include_once( "includes/LitDateTime.php" );
include_once( "includes/pgettext.php" );

class LitCalAPI {

    const API_VERSION                               = '3.9';
    public APICore $APICore;

    private string $CacheDuration                   = "";
    private string $CACHEFILE                       = "";
    private array $AllowedReturnTypes;
    private LitSettings $LitSettings;
    private LitCommon $LitCommon;
    private LitGrade $LitGrade;

    private ?object $DiocesanData                   = null;
    private ?object $NationalData                   = null;
    private ?object $WiderRegionData                = null;
    private ?object $GeneralIndex                   = null;
    private NumberFormatter $formatter;
    private NumberFormatter $formatterFem;
    private IntlDateFormatter $dayAndMonth;
    private IntlDateFormatter $dayOfTheWeek;

    private array $PropriumDeTempore                = [];
    private array $Messages                         = [];
    private FestivityCollection $Cal;
    private array $tempCal                          = [];
    private string $BaptismLordFmt;
    private string $BaptismLordMod;

    private int $startTime;
    private int $endTime;

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
    private static $noSpelloutOrdinal               = [
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
    ];

    //whatever does spellout-ordinal-common mean? perhaps it's common to both masculine and feminine? with neuter being different?
    private static $commonNeutSpelloutOrdinal       = [
        'da'  //Danish
    ];

    public function __construct(){
        $this->startTime        = hrtime(true);
        $this->APICore          = new APICore();
        $this->CacheDuration    = "_" . CacheDuration::MONTH . date( "m" );
    }

    private static function debugWrite( string $string ) {
        file_put_contents( "debug.log", $string . PHP_EOL, FILE_APPEND );
    }

    private function initParameterData() {
        if ( $this->APICore->getRequestContentType() === RequestContentType::JSON ) {
            $json = file_get_contents( 'php://input' );
            $data = json_decode( $json, true );
            if( NULL === $json || "" === $json ){
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad Request", true, 400 );
                die( '{"error":"No JSON data received in the request: <' . $json . '>"' );
            } else if ( json_last_error() !== JSON_ERROR_NONE ) {
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad Request", true, 400 );
                die( '{"error":"Malformed JSON data received in the request: <' . $json . '>, ' . json_last_error_msg() . '"}' );
            } else {
                $this->LitSettings = new LitSettings( $data );
            }
        } else {
            switch( $this->APICore->getRequestMethod() ) {
                case RequestMethod::POST:
                    $this->LitSettings = new LitSettings( $_POST );
                    break;
                case RequestMethod::GET:
                    $this->LitSettings = new LitSettings( $_GET );
                    break;
                case RequestMethod::OPTIONS:
                    //continue
                    break;
                default:
                    header( $_SERVER[ "SERVER_PROTOCOL" ]." 405 Method Not Allowed", true, 405 );
                    $errorMessage = '{"error":"You seem to be forming a strange kind of request? Allowed Request Methods are ';
                    $errorMessage .= implode( ' and ', $this->APICore->getAllowedRequestMethods() );
                    $errorMessage .= ', but your Request Method was ' . $this->APICore->getRequestMethod() . '"}';
                    die( $errorMessage );
            }
        }
        if( $this->LitSettings->ReturnType !== null ) {
            if( in_array( $this->LitSettings->ReturnType, $this->AllowedReturnTypes ) ) {
                $this->APICore->setResponseContentType( $this->APICore->getAllowedAcceptHeaders()[ array_search( $this->LitSettings->ReturnType, $this->AllowedReturnTypes ) ] );
            } else {
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 406 Not Acceptable", true, 406 );
                $errorMessage = '{"error":"You are requesting a content type which this API cannot produce. Allowed content types are ';
                $errorMessage .= implode( ' and ', $this->AllowedReturnTypes );
                $errorMessage .= ', but you have issued a parameter requesting a Content Type of ' . strtoupper( $this->LitSettings->ReturnType ) . '"}';
                die( $errorMessage );
            }
        } else {
            if( $this->APICore->hasAcceptHeader() ) {
                if( $this->APICore->isAllowedAcceptHeader() ) {
                    $this->LitSettings->ReturnType = $this->AllowedReturnTypes[ $this->APICore->getIdxAcceptHeaderInAllowed() ];
                    $this->APICore->setResponseContentType( $this->APICore->getAcceptHeader() );
                } else {
                    //Requests from browser windows using the address bar will probably have an Accept header of text/html
                    //In order to not be too drastic, let's treat text/html as though it were application/json
                    $acceptHeaders = explode( ",", $this->APICore->getAcceptHeader() );
                    if( in_array( 'text/html', $acceptHeaders ) || in_array( 'text/plain', $acceptHeaders ) || in_array( '*/*', $acceptHeaders ) ) {
                        $this->LitSettings->ReturnType = ReturnType::JSON;
                        $this->APICore->setResponseContentType( AcceptHeader::JSON );
                    } else {
                        header( $_SERVER[ "SERVER_PROTOCOL" ]." 406 Not Acceptable", true, 406 );
                        $errorMessage = '{"error":"You are requesting a content type which this API cannot produce. Allowed Accept headers are ';
                        $errorMessage .= implode( ' and ', $this->APICore->getAllowedAcceptHeaders() );
                        $errorMessage .= ', but you have issued an request with an Accept header of ' . $this->APICore->getAcceptHeader() . '"}';
                        die( $errorMessage );
                    }

                }
            } else {
                $this->LitSettings->ReturnType = $this->AllowedReturnTypes[ 0 ];
                $this->APICore->setResponseContentType( $this->APICore->getAllowedAcceptHeaders()[ 0 ] );
            }
        }
    }

    private function updateSettingsBasedOnNationalCalendar() : void {
        if( $this->LitSettings->NationalCalendar !== null ) {
            if( $this->LitSettings->NationalCalendar === 'VATICAN' ) {
                $this->LitSettings->Epiphany        = Epiphany::JAN6;
                $this->LitSettings->Ascension       = Ascension::THURSDAY;
                $this->LitSettings->CorpusChristi   = CorpusChristi::THURSDAY;
                $this->LitSettings->Locale          = LitLocale::LATIN;
            } else {
                if( property_exists( $this->NationalData, 'Settings' ) ) {
                    if(
                        property_exists( $this->NationalData->Settings, 'Epiphany' )
                        && Epiphany::isValid( $this->NationalData->Settings->Epiphany )
                    ) {
                        $this->LitSettings->Epiphany        = $this->NationalData->Settings->Epiphany;
                    }
                    if(
                        property_exists( $this->NationalData->Settings, 'Ascension' )
                        && Ascension::isValid( $this->NationalData->Settings->Ascension )
                    ) {
                        $this->LitSettings->Ascension       = $this->NationalData->Settings->Ascension;
                    }
                    if(
                        property_exists( $this->NationalData->Settings, 'CorpusChristi' )
                        && CorpusChristi::isValid( $this->NationalData->Settings->CorpusChristi )
                    ) {
                        $this->LitSettings->CorpusChristi   = $this->NationalData->Settings->CorpusChristi;
                    }
                    if(
                        property_exists( $this->NationalData->Settings, 'EternalHighPriest' )
                        && is_bool( $this->NationalData->Settings->EternalHighPriest )
                    ) {
                        $this->LitSettings->EternalHighPriest = $this->NationalData->Settings->EternalHighPriest;
                    }
                    if(
                        property_exists( $this->NationalData->Settings, 'Locale'  )
                        && LitLocale::isValid( $this->NationalData->Settings->Locale )
                    ) {
                        $this->LitSettings->Locale          = $this->NationalData->Settings->Locale;
                    }
                }
            }
        }
    }

    private function updateSettingsBasedOnDiocesanCalendar() : void {
        if( $this->LitSettings->DiocesanCalendar !== null && $this->DiocesanData !== null ) {
            if( property_exists( $this->DiocesanData, "Overrides" ) ) {
                foreach( $this->DiocesanData->Overrides as $key => $value ) {
                    switch( $key ) {
                        case "Epiphany":
                            if( Epiphany::isValid( $value ) ) {
                                $this->LitSettings->Epiphany        = $value;
                            }
                        break;
                        case "Ascension":
                            if( Ascension::isValid( $value ) ) {
                                $this->LitSettings->Ascension       = $value;
                            }
                        break;
                        case "CorpusChristi":
                            if( CorpusChristi::isValid( $value ) ) {
                                $this->LitSettings->CorpusChristi   = $value;
                            }
                        break;
                    }
                }
            }
        }
    }


    private function loadDiocesanCalendarData() : void {
        if( $this->LitSettings->DiocesanCalendar !== null ){
            //since a Diocesan calendar is being requested, we need to retrieve the JSON data
            //first we need to discover the path, so let's retrieve our index file
            if( file_exists( "nations/index.json" ) ){
                $this->GeneralIndex = json_decode( file_get_contents( "nations/index.json" ) );
                if( property_exists( $this->GeneralIndex, $this->LitSettings->DiocesanCalendar ) ){
                    $diocesanDataFile = $this->GeneralIndex->{$this->LitSettings->DiocesanCalendar}->path;
                    $this->LitSettings->NationalCalendar = $this->GeneralIndex->{$this->LitSettings->DiocesanCalendar}->nation;
                    if( file_exists( $diocesanDataFile ) ){
                        $this->DiocesanData = json_decode( file_get_contents( $diocesanDataFile ) );
                    }
                }
            }
        }
    }

    private function cacheFileIsAvailable() : bool {
        $cacheFilePath = "engineCache/v" . str_replace( ".", "_", self::API_VERSION ) . "/";
        $paramsHash = md5( serialize( $this->LitSettings) );
        LitCommon::$HASH_REQUEST = $paramsHash;
        $cacheFileName = $paramsHash . $this->CacheDuration . "." . strtolower( $this->LitSettings->ReturnType );
        $this->CACHEFILE = $cacheFilePath . $cacheFileName;
        return file_exists( $this->CACHEFILE );
    }

    private function createFormatters() : void {
        $baseLocale = Locale::getPrimaryLanguage($this->LitSettings->Locale);
        $this->dayAndMonth = IntlDateFormatter::create( $baseLocale, IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, "d MMMM" );
        $this->dayOfTheWeek  = IntlDateFormatter::create( $baseLocale, IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, "EEEE" );
        $this->formatter = new NumberFormatter( $baseLocale, NumberFormatter::SPELLOUT );
        //follow rules as indicated here: https://www.saxonica.com/html/documentation11/extensibility/localizing/ICU-numbering-dates/ICU-numbering.html
        if( in_array( $baseLocale, self::$genericSpelloutOrdinal ) ) {
            $this->formatter->setTextAttribute( NumberFormatter::DEFAULT_RULESET, "%spellout-ordinal" );
            //feminine version will be the same as masculine
            $this->formatterFem = $this->formatter;
        }
        else if( in_array( $baseLocale, self::$mascFemSpelloutOrdinal ) || in_array( $baseLocale, self::$mascFemNeutSpelloutOrdinal ) ) {
            $this->formatter->setTextAttribute( NumberFormatter::DEFAULT_RULESET, "%spellout-ordinal-masculine" );
            $this->formatterFem = new NumberFormatter( $baseLocale, NumberFormatter::SPELLOUT );
            $this->formatterFem->setTextAttribute( NumberFormatter::DEFAULT_RULESET, "%spellout-ordinal-feminine" );
        }
        else if( in_array( $baseLocale, self::$commonNeutSpelloutOrdinal ) ) {
            $this->formatter->setTextAttribute( NumberFormatter::DEFAULT_RULESET, "%spellout-ordinal-common" );
            //feminine version will be the same as masculine
            $this->formatterFem = $this->formatter;
        }
        else {
            $this->formatter = new NumberFormatter( $baseLocale, NumberFormatter::ORDINAL );
            //feminine version will be the same as masculine
            $this->formatterFem = $this->formatter;
        }
    }

    private function dieIfBeforeMinYear() : void {
        //for the time being, we cannot accept a year any earlier than 1970, since this engine is based on the liturgical reform from Vatican II
        //with the Prima Editio Typica of the Roman Missal and the General Norms promulgated with the Motu Proprio "Mysterii Paschali" in 1969
        if ( $this->LitSettings->Year < 1970 ) {
            $this->Messages[] = sprintf( _( "Only years from 1970 and after are supported. You tried requesting the year %d." ), $this->LitSettings->Year );
            $this->GenerateResponse();
        }
    }

    /**
     * Retrieve Higher Ranking Solemnities from Proprium de Tempore
     */
    private function loadPropriumDeTemporeData() : void {
        $locale = strtolower( explode('_', $this->LitSettings->Locale)[0] );
        $propriumdetemporeFile = "data/propriumdetempore/{$locale}.json";
        if( file_exists( $propriumdetemporeFile ) ) {
            $PropriumDeTempore = json_decode( file_get_contents( $propriumdetemporeFile ), true );
            if( json_last_error() === JSON_ERROR_NONE ){
                foreach( $PropriumDeTempore as $key => $event ) {
                    $this->PropriumDeTempore[ $key ] = [ "NAME" => $event ];
                }
            } else {
                die( '{"ERROR": "There was an error trying to retrieve and decode JSON data for the Proprium de Tempore. ' . json_last_error_msg() . '"}' );
            }
        }
    }

    private function loadPropriumDeSanctisData( string $missal ) : void {
        $propriumdesanctisFile = RomanMissal::getSanctoraleFileName( $missal );
        $propriumdesanctisI18nPath = RomanMissal::getSanctoraleI18nFilePath( $missal );

        if( $propriumdesanctisI18nPath !== false ) {
            $locale = strtolower( explode('_', $this->LitSettings->Locale)[0] );
            $propriumdesanctisI18nFile = $propriumdesanctisI18nPath . $locale . ".json";
            if( file_exists( $propriumdesanctisI18nFile ) ) {
                $NAME = json_decode( file_get_contents( $propriumdesanctisI18nFile ), true );
                if( json_last_error() !== JSON_ERROR_NONE ) {
                    die( '{"ERROR": "There was an error trying to retrieve and decode JSON i18n data for the Proprium de Sanctis for the Missal ' . RomanMissal::getName( $missal ) . ': ' . json_last_error_msg() . '"}' );
                }
            } else {
                $this->Messages[] = sprintf(
                    /**translators: name of the Roman Missal */
                    _( 'Data for the sanctorale from %s could not be found.' ),
                    RomanMissal::getName( $missal )
                );
            }
        } else {
            $this->Messages[] = sprintf(
                /**translators: name of the Roman Missal */
                _( 'Translation data for the sanctorale from %s could not be found.' ),
                RomanMissal::getName( $missal )
            );
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
                die( '{"ERROR": "There was an error trying to retrieve and decode JSON data for the Proprium de Sanctis for the Missal ' . RomanMissal::getName( $missal ) . ': ' . json_last_error_msg() . '"}' );
            }
        }
    }

    private function loadMemorialsFromDecreesData() : void {
        $memorialsFromDecreesFile       = "data/memorialsFromDecrees/memorialsFromDecrees.json";
        $memorialsFromDecreesI18nPath   = "data/memorialsFromDecrees/i18n/";
        $locale = strtolower( explode('_', $this->LitSettings->Locale)[0] );
        $memorialsFromDecreesI18nFile = $memorialsFromDecreesI18nPath . $locale . ".json";
        $NAME = null;

        if( file_exists( $memorialsFromDecreesI18nFile ) ) {
            $NAME = json_decode( file_get_contents( $memorialsFromDecreesI18nFile ), true );
            if( json_last_error() !== JSON_ERROR_NONE ) {
                die( '{"ERROR": "There was an error trying to retrieve and decode JSON i18n data for Memorials based on Decrees: ' . json_last_error_msg() . '"}' );
            }
        }

        if( file_exists( $memorialsFromDecreesFile ) ) {
            $memorialsFromDecrees = json_decode( file_get_contents( $memorialsFromDecreesFile ) );
            if( json_last_error() === JSON_ERROR_NONE ){
                $this->tempCal[ "MEMORIALS_FROM_DECREES" ] = [];
                foreach( $memorialsFromDecrees as $row ) {
                    if( ( $row->Metadata->action === "createNew" || ($row->Metadata->action === "setProperty" && $row->Metadata->property === "name" ) ) && $NAME !== null ) {
                        $row->Festivity->NAME = $NAME[ $row->Festivity->TAG ];
                    }
                    $this->tempCal[ "MEMORIALS_FROM_DECREES" ][ $row->Festivity->TAG ] = $row;
                }
            } else {
                die( '{"ERROR": "There was an error trying to retrieve and decode JSON data for Memorials based on Decrees: ' . json_last_error_msg() . '"}' );
            }
        }
    }

    private function calculateEasterTriduum() : void {
        $HolyThurs        = new Festivity( $this->PropriumDeTempore[ "HolyThurs" ][ "NAME" ],    LitFunc::calcGregEaster( $this->LitSettings->Year )->sub( new DateInterval( 'P3D' ) ), LitColor::WHITE, LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $GoodFri          = new Festivity( $this->PropriumDeTempore[ "GoodFri" ][ "NAME" ],      LitFunc::calcGregEaster( $this->LitSettings->Year )->sub( new DateInterval( 'P2D' ) ), LitColor::RED,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $EasterVigil      = new Festivity( $this->PropriumDeTempore[ "EasterVigil" ][ "NAME" ],  LitFunc::calcGregEaster( $this->LitSettings->Year )->sub( new DateInterval( 'P1D' ) ), LitColor::WHITE, LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $Easter           = new Festivity( $this->PropriumDeTempore[ "Easter" ][ "NAME" ],       LitFunc::calcGregEaster( $this->LitSettings->Year ),                               LitColor::WHITE, LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );

        $this->Cal->addFestivity( "HolyThurs",      $HolyThurs );
        $this->Cal->addFestivity( "GoodFri",        $GoodFri );
        $this->Cal->addFestivity( "EasterVigil",    $EasterVigil );
        $this->Cal->addFestivity( "Easter",         $Easter );
    }

    private function calculateEpiphanyJan6() : void {
        $Epiphany = new Festivity( $this->PropriumDeTempore[ "Epiphany" ][ "NAME" ], LitDateTime::createFromFormat( '!j-n-Y', '6-1-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) ),  LitColor::WHITE, LitFeastType::FIXED,  LitGrade::HIGHER_SOLEMNITY );
        $this->Cal->addFestivity( "Epiphany",   $Epiphany );
        //If a Sunday occurs on a day from Jan. 2 through Jan. 5, it is called the "Second Sunday of Christmas"
        //Weekdays from Jan. 2 through Jan. 5 are called "*day before Epiphany" (in which calendar? England?)
        //Actually in Latin they are "Feria II temporis Nativitatis", in English "Monday - Christmas Weekday", in Italian "Feria propria del 3 gennaio" ecc.
        $nth = 0;
        for ( $i = 2; $i <= 5; $i++ ) {
            $dateTime = LitDateTime::createFromFormat( '!j-n-Y', $i . '-1-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
            if ( self::DateIsSunday( $dateTime ) ) {
                $Christmas2 = new Festivity( $this->PropriumDeTempore[ "Christmas2" ][ "NAME" ], $dateTime, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::FEAST_LORD );
                $this->Cal->addFestivity( "Christmas2", $Christmas2 );
            } else {
                $nth++;
                //$nthStr = $this->LitSettings->Locale === LitLocale::LATIN ? LitMessages::LATIN_ORDINAL[ $nth ] : $this->formatter->format( $nth );
                $locale = strtoupper( explode('_', $this->LitSettings->Locale)[0] );
                $dayOfTheWeek = $locale === LitLocale::LATIN
                    ? LitMessages::LATIN_DAYOFTHEWEEK[ $dateTime->format( 'w' ) ]
                    : ( $locale === 'IT'
                        ? $this->dayAndMonth->format( $dateTime->format( 'U' ) )
                        : ucfirst( $this->dayOfTheWeek->format( $dateTime->format( 'U' ) ) )
                    );
                $name = $locale === LitLocale::LATIN
                    ? sprintf( "%s temporis Nativitatis", $dayOfTheWeek )
                    : ( $locale === 'IT'
                        ? sprintf( "Feria propria del %s", $dayOfTheWeek )
                        : sprintf(
                            /**translators: days before Epiphany when Epiphany falls on Jan 6 (not useful in Italian!) */
                            _( "%s - Christmas Weekday" ),
                            ucfirst( $dayOfTheWeek )
                        )
                    );
                $festivity = new Festivity( $name, $dateTime, LitColor::WHITE, LitFeastType::MOBILE );
                $this->Cal->addFestivity( "DayBeforeEpiphany" . $nth, $festivity );
            }
        }

        //Weekdays from Jan. 7 until the following Sunday are called "*day after Epiphany" (in which calendar? England?)
        //Actually in Latin they are still "Feria II temporis Nativitatis", in English "Monday - Christmas Weekday", in Italian "Feria propria del 3 gennaio" ecc.
        $SundayAfterEpiphany = (int)LitDateTime::createFromFormat( '!j-n-Y', '6-1-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) )->modify( 'next Sunday' )->format( 'j' );
        if ( $SundayAfterEpiphany !== 7 ) { //this means January 7th, it does not refer to the day of the week which is obviously Sunday in this case
            $nth = 0;
            for ( $i = 7; $i < $SundayAfterEpiphany; $i++ ) {
                $nth++;
                //$nthStr = $this->LitSettings->Locale === LitLocale::LATIN ? LitMessages::LATIN_ORDINAL[ $nth ] : $this->formatter->format( $nth );
                $dateTime = LitDateTime::createFromFormat( '!j-n-Y', $i . '-1-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
                $locale = strtoupper( explode('_', $this->LitSettings->Locale)[0] );
                $dayOfTheWeek = $locale === LitLocale::LATIN
                    ? LitMessages::LATIN_DAYOFTHEWEEK[ $dateTime->format( 'w' ) ]
                    : ( $locale === 'IT'
                        ? $this->dayAndMonth->format( $dateTime->format( 'U' ) )
                        : ucfirst( $this->dayOfTheWeek->format( $dateTime->format( 'U' ) ) )
                    );
                //$name = $locale === LitLocale::LATIN ? sprintf( "Dies %s post Epiphaniam", $nthStr ) : sprintf( _( "%s day after Epiphany" ), ucfirst( $nthStr ) );
                $name = $locale === LitLocale::LATIN
                    ? sprintf( "%s temporis Nativitatis", $dayOfTheWeek )
                    : ( $locale === 'IT'
                        ? sprintf( "Feria propria del %s", $dayOfTheWeek )
                        : sprintf(
                            /**translators: days after Epiphany when Epiphany falls on Jan 6 (not useful in Italian!) */
                            _( "%s - Christmas Weekday" ),
                            ucfirst( $dayOfTheWeek )
                        )
                    );
                $festivity = new Festivity( $name, $dateTime, LitColor::WHITE, LitFeastType::MOBILE );
                $this->Cal->addFestivity( "DayAfterEpiphany" . $nth, $festivity );
            }
        }
    }

    private function calculateEpiphanySunday() : void {
        //If January 2nd is a Sunday, then go with Jan 2nd
        $dateTime = LitDateTime::createFromFormat( '!j-n-Y', '2-1-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
        if ( self::DateIsSunday( $dateTime ) ) {
            $Epiphany = new Festivity( $this->PropriumDeTempore[ "Epiphany" ][ "NAME" ], $dateTime, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
            $this->Cal->addFestivity( "Epiphany", $Epiphany );
            $DayOfEpiphany = (int)$Epiphany->date->format( 'j' );
            $SundayAfterEpiphany =  (int)LitDateTime::createFromFormat( '!j-n-Y', '2-1-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) )->modify( 'next Sunday' )->format( 'j' );
        }
        //otherwise find the Sunday following Jan 2nd
        else {
            $SundayOfEpiphany = $dateTime->modify( 'next Sunday' );
            $Epiphany = new Festivity( $this->PropriumDeTempore[ "Epiphany" ][ "NAME" ], $SundayOfEpiphany, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
            $this->Cal->addFestivity( "Epiphany",   $Epiphany );
            //Weekdays from Jan. 2 until the following Sunday are called "*day before Epiphany" (in which calendar? England?)
            //Actually in Latin they are "Feria II temporis Nativitatis", in English "Monday - Christmas Weekday", in Italian "Feria propria del 3 gennaio" etc.
            $DayOfEpiphany = (int)$SundayOfEpiphany->format( 'j' );
            $SundayAfterEpiphany =  (int)LitDateTime::createFromFormat( '!j-n-Y', '2-1-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) )->modify( 'next Sunday' )->modify( 'next Sunday' )->format( 'j' );
            $nth = 0;
            for ( $i = 2; $i < $DayOfEpiphany - 1; $i++ ) {
                $nth++;
                //$nthStr = $this->LitSettings->Locale === LitLocale::LATIN ? LitMessages::LATIN_ORDINAL[ $nth ] : $this->formatter->format( $nth );
                $dateTime = LitDateTime::createFromFormat( '!j-n-Y', $i . '-1-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
                $locale = strtoupper( explode('_', $this->LitSettings->Locale)[0] );
                $dayOfTheWeek = $locale === LitLocale::LATIN
                    ? LitMessages::LATIN_DAYOFTHEWEEK[ $dateTime->format( 'w' ) ]
                    : ( $locale === 'IT'
                        ? $this->dayAndMonth->format( $dateTime->format( 'U' ) )
                        : ucfirst( $this->dayOfTheWeek->format( $dateTime->format( 'U' ) ) )
                    );
                //$name = $locale === LitLocale::LATIN ? sprintf( "Dies %s ante Epiphaniam", $nthStr ) : sprintf( _( "%s day before Epiphany" ), ucfirst( $nthStr ) );
                $name = $locale === LitLocale::LATIN
                    ? sprintf( "%s temporis Nativitatis", $dayOfTheWeek )
                    : ( $locale === 'IT'
                        ? sprintf( "Feria propria del %s", $dayOfTheWeek )
                        : sprintf(
                            /**translators: days before Epiphany when Epiphany is on a Sunday (not useful in Italian!) */
                            _( "%s - Christmas Weekday" ),
                            ucfirst( $dayOfTheWeek )
                        )
                    );
                $festivity = new Festivity( $name, $dateTime, LitColor::WHITE, LitFeastType::MOBILE );
                $this->Cal->addFestivity( "DayBeforeEpiphany" . $nth, $festivity );
            }

        }
        //Weekday from the Sunday of Epiphany until Baptism of the Lord are called "*day after Epiphany" (in which calendar? England?)
        //Actually in Latin they are still "Feria II temporis Nativitatis", in English "Monday - Christmas Weekday", in Italian "Feria propria del 3 gennaio" ecc.
        $nth = 0;
        for ( $i = $DayOfEpiphany + 1; $i < $SundayAfterEpiphany - 1; $i++ ) {
            $nth++;
            //$nthStr = $this->LitSettings->Locale === LitLocale::LATIN ? LitMessages::LATIN_ORDINAL[ $nth ] : $this->formatter->format( $nth );
            $dateTime = LitDateTime::createFromFormat( '!j-n-Y', $i . '-1-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
            $locale = strtoupper( explode('_', $this->LitSettings->Locale)[0] );
            $dayOfTheWeek = $locale === LitLocale::LATIN
                ? LitMessages::LATIN_DAYOFTHEWEEK[ $dateTime->format( 'w' ) ]
                : ( $locale === 'IT'
                    ? $this->dayAndMonth->format( $dateTime->format( 'U' ) )
                    : ucfirst( $this->dayOfTheWeek->format( $dateTime->format( 'U' ) ) )
                );
            //$name = $locale === LitLocale::LATIN ? sprintf( "Dies %s post Epiphaniam", $nthStr ) : sprintf( _( "%s day after Epiphany" ), ucfirst( $nthStr ) );
            $name = $locale === LitLocale::LATIN
                ? sprintf( "%s temporis Nativitatis", $dayOfTheWeek )
                : ( $locale === 'IT'
                    ? sprintf( "Feria propria del %s", $dayOfTheWeek )
                    : sprintf(
                        /**translators: days after Epiphany when Epiphany is on a Sunday (not useful in Italian!) */
                        _( "%s - Christmas Weekday" ),
                        ucfirst( $dayOfTheWeek )
                    )
                );
            $festivity = new Festivity( $name, $dateTime, LitColor::WHITE, LitFeastType::MOBILE );
            $this->Cal->addFestivity( "DayAfterEpiphany" . $nth, $festivity );
        }

    }

    private function calculateChristmasEpiphany() : void {
        $Christmas = new Festivity( 
            $this->PropriumDeTempore[ "Christmas" ][ "NAME" ],
            LitDateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) ),
            LitColor::WHITE,
            LitFeastType::FIXED,
            LitGrade::HIGHER_SOLEMNITY
        );
        $this->Cal->addFestivity( "Christmas", $Christmas );

        if ( $this->LitSettings->Epiphany === Epiphany::JAN6 ) {
            $this->calculateEpiphanyJan6();
        } else if ( $this->LitSettings->Epiphany === Epiphany::SUNDAY_JAN2_JAN8 ) {
            $this->calculateEpiphanySunday();
        }
    }

    private function calculateAscensionPentecost() : void {

        if ( $this->LitSettings->Ascension === Ascension::THURSDAY ) {
            $Ascension = new Festivity( $this->PropriumDeTempore[ "Ascension" ][ "NAME" ],  LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P39D' ) ),                LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
            $Easter7 = new Festivity( $this->PropriumDeTempore[ "Easter7" ][ "NAME" ],      LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P' . ( 7 * 6 ) . 'D' ) ), LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
            $this->Cal->addFestivity( "Easter7", $Easter7 );
        } else if ( $this->LitSettings->Ascension === "SUNDAY" ) {
            $Ascension = new Festivity( $this->PropriumDeTempore[ "Ascension" ][ "NAME" ],  LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P' . ( 7 * 6 ) . 'D' ) ), LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        }
        $this->Cal->addFestivity( "Ascension", $Ascension );

        $Pentecost = new Festivity( $this->PropriumDeTempore[ "Pentecost" ][ "NAME" ],      LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P' . ( 7 * 7 ) . 'D' ) ), LitColor::RED,      LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $this->Cal->addFestivity( "Pentecost", $Pentecost );

    }

    private function calculateSundaysMajorSeasons() : void {
        $this->Cal->addFestivity( "Advent1",    new Festivity( $this->PropriumDeTempore[ "Advent1" ][ "NAME" ],      LitDateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' )->sub( new DateInterval( 'P' . ( 3 * 7 ) . 'D' ) ), LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Advent2",    new Festivity( $this->PropriumDeTempore[ "Advent2" ][ "NAME" ],      LitDateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' )->sub( new DateInterval( 'P' . ( 2 * 7 ) . 'D' ) ), LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Advent3",    new Festivity( $this->PropriumDeTempore[ "Advent3" ][ "NAME" ],      LitDateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' )->sub( new DateInterval( 'P7D' ) ),                 LitColor::PINK,     LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Advent4",    new Festivity( $this->PropriumDeTempore[ "Advent4" ][ "NAME" ],      LitDateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' ),                                                   LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Lent1",      new Festivity( $this->PropriumDeTempore[ "Lent1" ][ "NAME" ],        LitFunc::calcGregEaster( $this->LitSettings->Year )->sub( new DateInterval( 'P' . ( 6 * 7 ) . 'D' ) ),    LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Lent2",      new Festivity( $this->PropriumDeTempore[ "Lent2" ][ "NAME" ],        LitFunc::calcGregEaster( $this->LitSettings->Year )->sub( new DateInterval( 'P' . ( 5 * 7 ) . 'D' ) ),    LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Lent3",      new Festivity( $this->PropriumDeTempore[ "Lent3" ][ "NAME" ],        LitFunc::calcGregEaster( $this->LitSettings->Year )->sub( new DateInterval( 'P' . ( 4 * 7 ) . 'D' ) ),    LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Lent4",      new Festivity( $this->PropriumDeTempore[ "Lent4" ][ "NAME" ],        LitFunc::calcGregEaster( $this->LitSettings->Year )->sub( new DateInterval( 'P' . ( 3 * 7 ) . 'D' ) ),    LitColor::PINK,     LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Lent5",      new Festivity( $this->PropriumDeTempore[ "Lent5" ][ "NAME" ],        LitFunc::calcGregEaster( $this->LitSettings->Year )->sub( new DateInterval( 'P' . ( 2 * 7 ) . 'D' ) ),    LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "PalmSun",    new Festivity( $this->PropriumDeTempore[ "PalmSun" ][ "NAME" ],      LitFunc::calcGregEaster( $this->LitSettings->Year )->sub( new DateInterval( 'P7D' ) ),                    LitColor::RED,      LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Easter2",    new Festivity( $this->PropriumDeTempore[ "Easter2" ][ "NAME" ],      LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P7D' ) ),                    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Easter3",    new Festivity( $this->PropriumDeTempore[ "Easter3" ][ "NAME" ],      LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P' . ( 7 * 2 ) . 'D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Easter4",    new Festivity( $this->PropriumDeTempore[ "Easter4" ][ "NAME" ],      LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P' . ( 7 * 3 ) . 'D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Easter5",    new Festivity( $this->PropriumDeTempore[ "Easter5" ][ "NAME" ],      LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P' . ( 7 * 4 ) . 'D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Easter6",    new Festivity( $this->PropriumDeTempore[ "Easter6" ][ "NAME" ],      LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P' . ( 7 * 5 ) . 'D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        $this->Cal->addFestivity( "Trinity",    new Festivity( $this->PropriumDeTempore[ "Trinity" ][ "NAME" ],      LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P' . ( 7 * 8 ) . 'D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY ) );
        if ( $this->LitSettings->CorpusChristi === CorpusChristi::THURSDAY ) {
            $CorpusChristi = new Festivity( $this->PropriumDeTempore[ "CorpusChristi" ][ "NAME" ], LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P' . ( 7 * 8 + 4 ) . 'D' ) ),  LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
            //Seeing the Sunday is not taken by Corpus Christi, it should be later taken by a Sunday of Ordinary Time (they are calculate back to Pentecost)
        } else if ( $this->LitSettings->CorpusChristi === CorpusChristi::SUNDAY ) {
            $CorpusChristi = new Festivity( $this->PropriumDeTempore[ "CorpusChristi" ][ "NAME" ], LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P' . ( 7 * 9 ) . 'D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        }
        $this->Cal->addFestivity( "CorpusChristi", $CorpusChristi );

        if( $this->LitSettings->Year >= 2000 ) {
            if( $this->LitSettings->Locale === LitLocale::LATIN ) {
                $divineMercySunday = $this->PropriumDeTempore[ "Easter2" ][ "NAME" ] . " vel Dominica Divinæ Misericordiæ";
            } else {
                $or = _( "or" );
                /**translators: as instituted on the day of the canonization of St Faustina Kowalska by Pope John Paul II in the year 2000 */
                $divineMercySunday = $this->PropriumDeTempore[ "Easter2" ][ "NAME" ] . " $or " . _( "Divine Mercy Sunday" );
            }
            $this->Cal->setProperty( "Easter2", "name", $divineMercySunday );
        }

    }

    private function calculateAshWednesday() : void {
        $AshWednesday = new Festivity( $this->PropriumDeTempore[ "AshWednesday" ][ "NAME" ], LitFunc::calcGregEaster( $this->LitSettings->Year )->sub( new DateInterval( 'P46D' ) ),           LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $this->Cal->addFestivity( "AshWednesday", $AshWednesday );
    }

    private function calculateWeekdaysHolyWeek() : void {
        //Weekdays of Holy Week from Monday to Thursday inclusive ( that is, thursday morning chrism mass... the In Coena Domini mass begins the Easter Triduum )
        $MonHolyWeek = new Festivity( $this->PropriumDeTempore[ "MonHolyWeek" ][ "NAME" ], LitFunc::calcGregEaster( $this->LitSettings->Year )->sub( new DateInterval( 'P6D' ) ),            LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $TueHolyWeek = new Festivity( $this->PropriumDeTempore[ "TueHolyWeek" ][ "NAME" ], LitFunc::calcGregEaster( $this->LitSettings->Year )->sub( new DateInterval( 'P5D' ) ),            LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $WedHolyWeek = new Festivity( $this->PropriumDeTempore[ "WedHolyWeek" ][ "NAME" ], LitFunc::calcGregEaster( $this->LitSettings->Year )->sub( new DateInterval( 'P4D' ) ),            LitColor::PURPLE,   LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $this->Cal->addFestivity( "MonHolyWeek", $MonHolyWeek );
        $this->Cal->addFestivity( "TueHolyWeek", $TueHolyWeek );
        $this->Cal->addFestivity( "WedHolyWeek", $WedHolyWeek );
    }

    private function calculateEasterOctave() : void {
        //Days within the octave of Easter
        $MonOctaveEaster = new Festivity( $this->PropriumDeTempore[ "MonOctaveEaster" ][ "NAME" ], LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P1D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $TueOctaveEaster = new Festivity( $this->PropriumDeTempore[ "TueOctaveEaster" ][ "NAME" ], LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P2D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $WedOctaveEaster = new Festivity( $this->PropriumDeTempore[ "WedOctaveEaster" ][ "NAME" ], LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P3D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $ThuOctaveEaster = new Festivity( $this->PropriumDeTempore[ "ThuOctaveEaster" ][ "NAME" ], LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P4D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $FriOctaveEaster = new Festivity( $this->PropriumDeTempore[ "FriOctaveEaster" ][ "NAME" ], LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P5D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );
        $SatOctaveEaster = new Festivity( $this->PropriumDeTempore[ "SatOctaveEaster" ][ "NAME" ], LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P6D' ) ),    LitColor::WHITE,    LitFeastType::MOBILE, LitGrade::HIGHER_SOLEMNITY );

        $this->Cal->addFestivity( "MonOctaveEaster", $MonOctaveEaster );
        $this->Cal->addFestivity( "TueOctaveEaster", $TueOctaveEaster );
        $this->Cal->addFestivity( "WedOctaveEaster", $WedOctaveEaster );
        $this->Cal->addFestivity( "ThuOctaveEaster", $ThuOctaveEaster );
        $this->Cal->addFestivity( "FriOctaveEaster", $FriOctaveEaster );
        $this->Cal->addFestivity( "SatOctaveEaster", $SatOctaveEaster );
    }

    private function calculateMobileSolemnitiesOfTheLord() : void {
        $SacredHeart = new Festivity( $this->PropriumDeTempore[ "SacredHeart" ][ "NAME" ],    LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P' . ( 7 * 9 + 5 ) . 'D' ) ),  LitColor::RED,      LitFeastType::MOBILE, LitGrade::SOLEMNITY );
        $this->Cal->addFestivity( "SacredHeart", $SacredHeart );

        //Christ the King is calculated backwards from the first sunday of advent
        $ChristKing = new Festivity( $this->PropriumDeTempore[ "ChristKing" ][ "NAME" ],     LitDateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' )->sub( new DateInterval( 'P' . ( 4 * 7 ) . 'D' ) ),    LitColor::WHITE,  LitFeastType::MOBILE, LitGrade::SOLEMNITY );
        $this->Cal->addFestivity( "ChristKing", $ChristKing );
    }

    private function calculateFixedSolemnities() : void {
        //even though Mary Mother of God is a fixed date solemnity, however it is found in the Proprium de Tempore and not in the Proprium de Sanctis
        $MotherGod = new Festivity( $this->PropriumDeTempore[ "MotherGod" ][ "NAME" ], LitDateTime::createFromFormat( '!j-n-Y', '1-1-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) ),      LitColor::WHITE,    LitFeastType::FIXED, LitGrade::SOLEMNITY );
        $this->Cal->addFestivity( "MotherGod", $MotherGod );

        $tempCalSolemnities = array_filter( $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ], function( $el ){ return $el->GRADE === LitGrade::SOLEMNITY; } );
        foreach( $tempCalSolemnities as $row ) {
            $currentFeastDate = LitDateTime::createFromFormat( '!j-n-Y', $row->DAY . '-' . $row->MONTH . '-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
            $tempFestivity = new Festivity( $row->NAME, $currentFeastDate, $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON );
            //LitCalAPI::debugWrite( "adding new fixed solemnity '$row->NAME', common vartype = " . gettype( $row->COMMON ) . ", common = " . implode(', ', $row->COMMON) );
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
                    $locale = strtoupper( explode('_', $this->LitSettings->Locale)[0] );
                    if( $row->TAG === "StJoseph" && $currentFeastDate >= $this->Cal->getFestivity("PalmSun")->date && $currentFeastDate <= $this->Cal->getFestivity("Easter")->date ){
                        $tempFestivity->date = LitFunc::calcGregEaster( $this->LitSettings->Year )->sub( new DateInterval( 'P8D' ) );
                        $this->Messages[] = sprintf(
                            /**translators: 1: Festivity name, 2: Festivity date, 3: Requested calendar year, 4: Explicatory string for the transferral (ex. the Saturday preceding Palm Sunday), 5: actual date for the transferral, 6: Decree of the Congregation for Divine Worship  */
                            _( 'The Solemnity \'%1$s\' falls on %2$s in the year %3$d, the celebration has been transferred to %4$s (%5$s) as per the %6$s.' ),
                            $tempFestivity->name,
                            $this->Cal->solemnityFromDate( $currentFeastDate )->name,
                            $this->LitSettings->Year,
                            _( "the Saturday preceding Palm Sunday" ),
                            $locale === LitLocale::LATIN ? ( $tempFestivity->date->format( 'j' ) . ' ' . LitMessages::LATIN_MONTHS[ (int)$tempFestivity->date->format( 'n' ) ] ) :
                                ( $locale === 'EN' ? $tempFestivity->date->format( 'F jS' ) :
                                    $this->dayAndMonth->format( $tempFestivity->date->format( 'U' ) )
                                ),
                            '<a href="http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/2006/475-476.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>'
                        );
                    }
                    else if( $row->TAG === "Annunciation" && $currentFeastDate >= $this->Cal->getFestivity( "PalmSun" )->date && $currentFeastDate <= $this->Cal->getFestivity( "Easter2" )->date ){
                        //if the Annunciation falls during Holy Week or within the Octave of Easter, it is transferred to the Monday after the Second Sunday of Easter.
                        $tempFestivity->date = LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P8D' ) );
                        $this->Messages[] = sprintf(
                            /**translators: 1: Festivity name, 2: Festivity date, 3: Requested calendar year, 4: Explicatory string for the transferral (ex. the Saturday preceding Palm Sunday), 5: actual date for the transferral, 6: Decree of the Congregation for Divine Worship */
                            _( 'The Solemnity \'%1$s\' falls on %2$s in the year %3$d, the celebration has been transferred to %4$s (%5$s) as per the %6$s.' ),
                            $tempFestivity->name,
                            $this->Cal->solemnityFromDate( $currentFeastDate )->name,
                            $this->LitSettings->Year,
                            _( 'the Monday following the Second Sunday of Easter' ),
                            $locale === LitLocale::LATIN ? ( $tempFestivity->date->format( 'j' ) . ' ' . LitMessages::LATIN_MONTHS[ (int)$tempFestivity->date->format( 'n' ) ] ) :
                                ( $locale === 'EN' ? $tempFestivity->date->format( 'F jS' ) :
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
                            /**translators: 1: Festivity name, 2: Festivity date, 3: Requested calendar year, 4: Explicatory string for the transferral, 5: actual date for the transferral, 6: Decree of the Congregation for Divine Worship  */
                            _( 'The Solemnity \'%1$s\' falls on %2$s in the year %3$d, the celebration has been transferred to %4$s (%5$s) as per the %6$s.' ),
                            $tempFestivity->name,
                            $this->Cal->solemnityFromDate( $currentFeastDate )->name,
                            $this->LitSettings->Year,
                            _( "the following Monday" ),
                            $locale === LitLocale::LATIN ? ( $tempFestivity->date->format( 'j' ) . ' ' . LitMessages::LATIN_MONTHS[ (int)$tempFestivity->date->format( 'n' ) ] ) :
                                ( $locale === 'EN' ? $tempFestivity->date->format( 'F jS' ) :
                                    $this->dayAndMonth->format( $tempFestivity->date->format( 'U' ) )
                                ),
                            '<a href="http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/1990/284-285.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>'
                        );
                    }
                    else{
                        //In all other cases, let's make a note of what's happening and ask the Congegation for Divine Worship
                        $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                            /**translators: 1: Festivity name, 2: Coinciding Festivity name, 3: Requested calendar year */
                            _( 'The Solemnity \'%1$s\' coincides with the Solemnity \'%2$s\' in the year %3$d. We should ask the Congregation for Divine Worship what to do about this!' ),
                            $row->NAME,
                            $this->Cal->solemnityFromDate( $currentFeastDate )->name,
                            $this->LitSettings->Year
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
                                /**translators: 1: Festivity name, 2: Coinciding Festivity name, 3: Requested calendar year, 4: Decree of the Congregation for Divine Worship */
                                _( 'Seeing that the Solemnity \'%1$s\' coincides with the Solemnity \'%2$s\' in the year %3$d, it has been anticipated by one day as per %4$s.' ),
                                $tempFestivity->name,
                                $SacredHeart->name,
                                $this->LitSettings->Year,
                                '<a href="http://www.cultodivino.va/content/cultodivino/it/documenti/responsa-ad-dubia/2020/de-calendario-liturgico-2022.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>'
                            );
                        }
                    }
            }
            $this->Cal->addFestivity( $row->TAG, $tempFestivity );
        }

        //let's add a displayGrade property for AllSouls so applications don't have to worry about fixing it
        $this->Cal->setProperty( "AllSouls", "displayGrade", $this->LitGrade->i18n( LitGrade::COMMEMORATION, false ) );

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
        $this->BaptismLordFmt = '6-1-' . $this->LitSettings->Year;
        $this->BaptismLordMod = 'next Sunday';
        //If Epiphany is celebrated on Sunday between Jan. 2 - Jan 8, and Jan. 7 or Jan. 8 is Sunday, then Baptism of the Lord is celebrated on the Monday immediately following that Sunday
        if ( $this->LitSettings->Epiphany === Epiphany::SUNDAY_JAN2_JAN8 ) {
            $dateJan7 = LitDateTime::createFromFormat( '!j-n-Y', '7-1-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
            $dateJan8 = LitDateTime::createFromFormat( '!j-n-Y', '8-1-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
            if ( self::DateIsSunday( $dateJan7 ) ) {
                $this->BaptismLordFmt = '7-1-' . $this->LitSettings->Year;
                $this->BaptismLordMod = 'next Monday';
            } else if ( self::DateIsSunday( $dateJan8 ) ) {
                $this->BaptismLordFmt = '8-1-' . $this->LitSettings->Year;
                $this->BaptismLordMod = 'next Monday';
            }
        }
        $BaptismLord      = new Festivity( $this->PropriumDeTempore[ "BaptismLord" ][ "NAME" ], LitDateTime::createFromFormat( '!j-n-Y', $this->BaptismLordFmt, new DateTimeZone( 'UTC' ) )->modify( $this->BaptismLordMod ), LitColor::WHITE, LitFeastType::MOBILE, LitGrade::FEAST_LORD );
        $this->Cal->addFestivity( "BaptismLord", $BaptismLord );

        // the other feasts of the Lord ( Presentation, Transfiguration and Triumph of the Holy Cross) are fixed date feasts
        // and are found in the Proprium de Sanctis
        // :DedicationLateran is a specific case, we consider it a Feast of the Lord even though it is displayed as FEAST
        //  source: in the Missale Romanum, in the section Index Alphabeticus Celebrationum, under Iesus Christus D. N., the Dedicatio Basilicae Lateranensis is also listed
        $tempCal = array_filter( $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ], function( $el ){ return $el->GRADE === LitGrade::FEAST_LORD; } );

        foreach ( $tempCal as $row ) {
            $currentFeastDate = LitDateTime::createFromFormat( '!j-n-Y', $row->DAY . '-' . $row->MONTH . '-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
            $festivity = new Festivity( $row->NAME, $currentFeastDate, $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON );
            if( $row->TAG === 'DedicationLateran' ) {
                $festivity->displayGrade = $this->LitGrade->i18n( LitGrade::FEAST, false );
            }
            $this->Cal->addFestivity( $row->TAG, $festivity );
        }

        //Holy Family is celebrated the Sunday after Christmas, unless Christmas falls on a Sunday, in which case it is celebrated Dec. 30
        $locale = strtoupper( explode('_', $this->LitSettings->Locale)[0] );
        if ( self::DateIsSunday( $this->Cal->getFestivity( "Christmas" )->date ) ) {
            $holyFamilyDate = LitDateTime::createFromFormat( '!j-n-Y', '30-12-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
            $HolyFamily = new Festivity( $this->PropriumDeTempore[ "HolyFamily" ][ "NAME" ], $holyFamilyDate, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::FEAST_LORD );
            $this->Messages[] = sprintf(
                /**translators: 1: Festivity name (Christmas), 2: Requested calendar year, 3: Festivity name (Holy Family), 4: New date for Holy Family */
                _( '\'%1$s\' falls on a Sunday in the year %2$d, therefore the Feast \'%3$s\' is celebrated on %4$s rather than on the Sunday after Christmas.' ),
                $this->Cal->getFestivity( "Christmas" )->name,
                $this->LitSettings->Year,
                $HolyFamily->name,
                $locale === LitLocale::LATIN ? ( $HolyFamily->date->format( 'j' ) . ' ' . LitMessages::LATIN_MONTHS[ (int)$HolyFamily->date->format( 'n' ) ] ) :
                    ( $locale === 'EN' ? $HolyFamily->date->format( 'F jS' ) :
                        $this->dayAndMonth->format( $HolyFamily->date->format( 'U' ) )
                    )
            );
        } else {
            $holyFamilyDate = LitDateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) )->modify( 'next Sunday' );
            $HolyFamily = new Festivity( $this->PropriumDeTempore[ "HolyFamily" ][ "NAME" ], $holyFamilyDate, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::FEAST_LORD );
        }
        $this->Cal->addFestivity( "HolyFamily", $HolyFamily );

        // In 2012, Pope Benedict XVI gave faculty to the Episcopal Conferences
        //  to insert the Feast of Our Lord Jesus Christ, the Eternal High Priest
        //  in their own liturgical calendars on the Thursday after Pentecost,
        //  see http://notitiae.ipsissima-verba.org/pdf/notitiae-2012-335-368.pdf
        if( $this->LitSettings->Year >= 2012 && true === $this->LitSettings->EternalHighPriest ) {
            $EternalHighPriestDate = clone( $this->Cal->getFestivity( "Pentecost" )->date );
            $EternalHighPriestDate->modify('next Thursday');
            $locale = strtoupper( explode('_', $this->LitSettings->Locale)[0] );
            $EternalHighPriestName = $locale === LitLocale::LATIN ? 'Domini Nostri Iesu Christi Summi et Aeterni Sacerdotis' :
                /**translators: You can ignore this translation if the Feast has not been inserted by the Episcopal Conference */
                _( 'Our Lord Jesus Christ, The Eternal High Priest' );
            $festivity = new Festivity( $EternalHighPriestName, $EternalHighPriestDate, LitColor::WHITE, LitFeastType::FIXED, LitGrade::FEAST_LORD, LitCommon::PROPRIO );
            $this->Cal->addFestivity( 'JesusChristEternalHighPriest', $festivity );
            $this->Messages[] = sprintf(
                /**translators: 1: National Calendar, 2: Requested calendar year, 3: source of the rule */
                _( 'In 2012, Pope Benedict XVI gave faculty to the Episcopal Conferences' .
                   ' to insert the Feast of Our Lord Jesus Christ the Eternal High Priest in their own liturgical calendars' .
                   ' on the Thursday after Pentecost: applicable to the calendar \'%1$s\' in the year \'%2$d\' (%3$s).' ),
                $this->LitSettings->NationalCalendar,
                $this->LitSettings->Year,
                '<a href="http://notitiae.ipsissima-verba.org/pdf/notitiae-2012-335-368.pdf" target="_blank">notitiae-2012-335-368.pdf</a>'
            );
        }

    }

    private function calculateSundaysChristmasOrdinaryTime() : void {
        //If a fixed date Solemnity occurs on a Sunday of Ordinary Time or on a Sunday of Christmas, the Solemnity is celebrated in place of the Sunday. ( e.g., Birth of John the Baptist, 1990 )
        //If a fixed date Feast of the Lord occurs on a Sunday in Ordinary Time, the feast is celebrated in place of the Sunday

        //Sundays of Ordinary Time in the First part of the year are numbered from after the Baptism of the Lord ( which begins the 1st week of Ordinary Time ) until Ash Wednesday
        $firstOrdinary = LitDateTime::createFromFormat( '!j-n-Y', $this->BaptismLordFmt, new DateTimeZone( 'UTC' ) )->modify( $this->BaptismLordMod );
        //Basically we take Ash Wednesday as the limit...
        //Here is ( Ash Wednesday - 7 ) since one more cycle will complete...
        $firstOrdinaryLimit = LitFunc::calcGregEaster( $this->LitSettings->Year )->sub( new DateInterval( 'P53D' ) );
        $ordSun = 1;
        while ( $firstOrdinary >= $this->Cal->getFestivity( "BaptismLord" )->date && $firstOrdinary < $firstOrdinaryLimit ) {
            $firstOrdinary = LitDateTime::createFromFormat( '!j-n-Y', $this->BaptismLordFmt, new DateTimeZone( 'UTC' ) )->modify( $this->BaptismLordMod )->modify( 'next Sunday' )->add( new DateInterval( 'P' . ( ( $ordSun - 1 ) * 7 ) . 'D' ) );
            $ordSun++;
            if ( !$this->Cal->inSolemnities( $firstOrdinary ) ) {
                $this->Cal->addFestivity( "OrdSunday" . $ordSun, new Festivity( $this->PropriumDeTempore[ "OrdSunday" . $ordSun ][ "NAME" ], $firstOrdinary, LitColor::GREEN, LitFeastType::MOBILE, LitGrade::FEAST_LORD ) );
            } else {
                $this->Messages[] = sprintf(
                    /**translators: 1: Festivity name, 2: Superseding Festivity grade, 3: Superseding Festivity name, 4: Requested calendar year */
                    _( '\'%1$s\' is superseded by the %2$s \'%3$s\' in the year %4$d.' ),
                    $this->PropriumDeTempore[ "OrdSunday" . $ordSun ][ "NAME" ],
                    $this->Cal->solemnityFromDate( $firstOrdinary )->grade > LitGrade::SOLEMNITY ? '<i>' . $this->LitGrade->i18n( $this->Cal->solemnityFromDate( $firstOrdinary )->grade, false ) . '</i>' : $this->LitGrade->i18n( $this->Cal->solemnityFromDate( $firstOrdinary )->grade, false ),
                    $this->Cal->solemnityFromDate( $firstOrdinary )->name,
                    $this->LitSettings->Year
                );
            }
        }

        //Sundays of Ordinary Time in the Latter part of the year are numbered backwards from Christ the King ( 34th ) to Pentecost
        $lastOrdinary = LitDateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' )->sub( new DateInterval( 'P' . ( 4 * 7 ) . 'D' ) );
        //We take Trinity Sunday as the limit...
        //Here is ( Trinity Sunday + 7 ) since one more cycle will complete...
        $lastOrdinaryLowerLimit = LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P' . ( 7 * 9 ) . 'D' ) );
        $ordSun = 34;
        $ordSunCycle = 4;

        while ( $lastOrdinary <= $this->Cal->getFestivity( "ChristKing" )->date && $lastOrdinary > $lastOrdinaryLowerLimit ) {
            $lastOrdinary = LitDateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' )->sub( new DateInterval( 'P' . ( ++$ordSunCycle * 7 ) . 'D' ) );
            $ordSun--;
            if ( !$this->Cal->inSolemnities( $lastOrdinary ) ) {
                $this->Cal->addFestivity( "OrdSunday" . $ordSun, new Festivity( $this->PropriumDeTempore[ "OrdSunday" . $ordSun ][ "NAME" ], $lastOrdinary, LitColor::GREEN, LitFeastType::MOBILE, LitGrade::FEAST_LORD ) );
            } else {
                $this->Messages[] = sprintf(
                    /**translators: 1: Festivity name, 2: Superseding Festivity grade, 3: Superseding Festivity name, 4: Requested calendar year */
                    _( '\'%1$s\' is superseded by the %2$s \'%3$s\' in the year %4$d.' ),
                    $this->PropriumDeTempore[ "OrdSunday" . $ordSun ][ "NAME" ],
                    $this->Cal->solemnityFromDate( $lastOrdinary )->grade > LitGrade::SOLEMNITY ? '<i>' . $this->LitGrade->i18n( $this->Cal->solemnityFromDate( $lastOrdinary )->grade, false ) . '</i>' : $this->LitGrade->i18n( $this->Cal->solemnityFromDate( $lastOrdinary )->grade, false ),
                    $this->Cal->solemnityFromDate( $lastOrdinary )->name,
                    $this->LitSettings->Year
                );
            }
        }

    }

    private function calculateFeastsMarySaints() : void {
        $tempCal = array_filter( $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ], function( $el ){ return $el->GRADE === LitGrade::FEAST; } );

        foreach ( $tempCal as $row ) {
            $row->DATE = LitDateTime::createFromFormat( '!j-n-Y', $row->DAY . '-' . $row->MONTH . '-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
            // If a Feast ( not of the Lord ) occurs on a Sunday in Ordinary Time, the Sunday is celebrated.  ( e.g., St. Luke, 1992 )
            // obviously solemnities also have precedence
            // The Dedication of the Lateran Basilica is an exceptional case, where it is treated as a Feast of the Lord, even if it is displayed as a Feast
            //  source: in the Missale Romanum, in the section Index Alphabeticus Celebrationum, under Iesus Christus D. N., the Dedicatio Basilicae Lateranensis is also listed
            //  so we give it a grade of 5 === FEAST_LORD but a displayGrade of FEAST
            //  it should therefore have already been handled in $this->calculateFeastsOfTheLord(), see :DedicationLateran
            if ( self::DateIsNotSunday( $row->DATE ) && !$this->Cal->inSolemnities( $row->DATE ) ) {
                $festivity = new Festivity( $row->NAME, $row->DATE, $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON );
                $this->Cal->addFestivity( $row->TAG, $festivity );
            } else {
                $this->handleCoincidence( $row, RomanMissal::EDITIO_TYPICA_1970 );
            }
        }

        // With the decree Apostolorum Apostola ( June 3rd 2016 ), the Congregation for Divine Worship
        // with the approval of Pope Francis elevated the memorial of Saint Mary Magdalen to a Feast
        // source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/articolo-roche-maddalena_it.pdf
        // This is taken care of ahead when the "memorials from decrees" are applied
        // see :MEMORIALS_FROM_DECREES

    }

    private function calculateWeekdaysAdvent() : void {
        //  Here we are calculating all weekdays of Advent, but we are giving a certain importance to the weekdays of Advent from 17 Dec. to 24 Dec.
        //  ( the same will be true of the Octave of Christmas and weekdays of Lent )
        //  on which days obligatory memorials can only be celebrated in partial form

        $DoMAdvent1     = $this->Cal->getFestivity("Advent1")->date->format( 'j' ); //DoM == Day of Month
        $MonthAdvent1   = $this->Cal->getFestivity("Advent1")->date->format( 'n' );
        $weekdayAdvent  = LitDateTime::createFromFormat( '!j-n-Y', $DoMAdvent1 . '-' . $MonthAdvent1 . '-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
        $weekdayAdventCnt = 1;
        while ( $weekdayAdvent >= $this->Cal->getFestivity("Advent1")->date && $weekdayAdvent < $this->Cal->getFestivity("Christmas")->date ) {
            $weekdayAdvent = LitDateTime::createFromFormat( '!j-n-Y', $DoMAdvent1 . '-' . $MonthAdvent1 . '-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) )->add( new DateInterval( 'P' . $weekdayAdventCnt . 'D' ) );

            //if we're not dealing with a sunday or a solemnity, then create the weekday
            if ( $this->Cal->notInSolemnitiesFeastsOrMemorials( $weekdayAdvent ) && self::DateIsNotSunday( $weekdayAdvent ) ) {
                $upper = (int)$weekdayAdvent->format( 'z' );
                $diff = $upper - (int)$this->Cal->getFestivity("Advent1")->date->format( 'z' ); //day count between current day and First Sunday of Advent
                $currentAdvWeek = ( ( $diff - $diff % 7 ) / 7 ) + 1; //week count between current day and First Sunday of Advent

                $dayOfTheWeek = $this->LitSettings->Locale === LitLocale::LATIN ? LitMessages::LATIN_DAYOFTHEWEEK[ $weekdayAdvent->format( 'w' ) ] : ucfirst( $this->dayOfTheWeek->format( $weekdayAdvent->format( 'U' ) ) );
                $ordinal = ucfirst( LitMessages::getOrdinal( $currentAdvWeek, $this->LitSettings->Locale, $this->formatterFem, LitMessages::LATIN_ORDINAL_FEM_GEN ) );
                $nthStr = $this->LitSettings->Locale === LitLocale::LATIN
                    ? sprintf( "Hebdomadæ %s Adventus", $ordinal )
                    : sprintf(
                        /**translators: %s is an ordinal number (first, second...) */
                        _( "of the %s Week of Advent" ),
                        $ordinal
                    );
                $name = $dayOfTheWeek . " " . $nthStr;
                $festivity = new Festivity( $name, $weekdayAdvent, LitColor::PURPLE, LitFeastType::MOBILE );
                $this->Cal->addFestivity( "AdventWeekday" . $weekdayAdventCnt, $festivity );
            }

            $weekdayAdventCnt++;
        }
    }

    private function calculateWeekdaysChristmasOctave() : void {
        $weekdayChristmas = LitDateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
        $weekdayChristmasCnt = 1;
        while ( $weekdayChristmas >= $this->Cal->getFestivity( "Christmas" )->date && $weekdayChristmas < LitDateTime::createFromFormat( '!j-n-Y', '31-12-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) ) ) {
            $weekdayChristmas = LitDateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) )->add( new DateInterval( 'P' . $weekdayChristmasCnt . 'D' ) );
            if ( $this->Cal->notInSolemnitiesFeastsOrMemorials( $weekdayChristmas ) && self::DateIsNotSunday( $weekdayChristmas ) ) {
                $ordinal = ucfirst( LitMessages::getOrdinal( ( $weekdayChristmasCnt + 1 ), $this->LitSettings->Locale, $this->formatter, LitMessages::LATIN_ORDINAL ) );
                $name = $this->LitSettings->Locale === LitLocale::LATIN
                    ? sprintf( "Dies %s Octavæ Nativitatis", $ordinal )
                    : sprintf(
                        /**translators: %s is an ordinal number (first, second...) */
                        _( "%s Day of the Octave of Christmas" ),
                        $ordinal
                    );
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
        $weekdayLent = LitDateTime::createFromFormat( '!j-n-Y', $DoMAshWednesday . '-' . $MonthAshWednesday . '-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
        $weekdayLentCnt = 1;
        while ( $weekdayLent >= $this->Cal->getFestivity( "AshWednesday" )->date && $weekdayLent < $this->Cal->getFestivity( "PalmSun" )->date ) {
            $weekdayLent = LitDateTime::createFromFormat( '!j-n-Y', $DoMAshWednesday . '-' . $MonthAshWednesday . '-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) )->add( new DateInterval( 'P' . $weekdayLentCnt . 'D' ) );
            if ( !$this->Cal->inSolemnities( $weekdayLent ) && self::DateIsNotSunday( $weekdayLent ) ) {
                if ( $weekdayLent > $this->Cal->getFestivity("Lent1")->date ) {
                    $upper =  (int)$weekdayLent->format( 'z' );
                    $diff = $upper -  (int)$this->Cal->getFestivity( "Lent1" )->date->format( 'z' ); //day count between current day and First Sunday of Lent
                    $currentLentWeek = ( ( $diff - $diff % 7 ) / 7 ) + 1; //week count between current day and First Sunday of Lent
                    $ordinal = ucfirst( LitMessages::getOrdinal( $currentLentWeek, $this->LitSettings->Locale, $this->formatterFem, LitMessages::LATIN_ORDINAL_FEM_GEN ) );
                    $dayOfTheWeek = $this->LitSettings->Locale == LitLocale::LATIN ? LitMessages::LATIN_DAYOFTHEWEEK[ $weekdayLent->format( 'w' ) ] : ucfirst( $this->dayOfTheWeek->format( $weekdayLent->format( 'U' ) ) );
                    $nthStr = $this->LitSettings->Locale === LitLocale::LATIN
                        ? sprintf( "Hebdomadæ %s Quadragesimæ", $ordinal )
                        : sprintf(
                            /**translators: %s is an ordinal number (first, second...) */
                            _( "of the %s Week of Lent" ),
                            $ordinal
                        );
                    $name = $dayOfTheWeek . " ".  $nthStr;
                    $festivity = new Festivity( $name, $weekdayLent, LitColor::PURPLE, LitFeastType::MOBILE );
                } else {
                    $dayOfTheWeek = $this->LitSettings->Locale == LitLocale::LATIN ? LitMessages::LATIN_DAYOFTHEWEEK[ $weekdayLent->format( 'w' ) ] : ucfirst( $this->dayOfTheWeek->format( $weekdayLent->format( 'U' ) ) );
                    $postStr = $this->LitSettings->Locale === LitLocale::LATIN ? "post Feria IV Cinerum" : _( "after Ash Wednesday" );
                    $name = $dayOfTheWeek . " ". $postStr;
                    $festivity = new Festivity( $name, $weekdayLent, LitColor::PURPLE, LitFeastType::MOBILE );
                }
                $this->Cal->addFestivity( "LentWeekday" . $weekdayLentCnt, $festivity );
            }
            $weekdayLentCnt++;
        }

    }

    private function addMissalMemorialMessage( object $row ) {
        /**translators:
         * 1. Grade or rank of the festivity
         * 2. Name of the festivity
         * 3. Day of the festivity
         * 4. Year from which the festivity has been added
         * 5. Source of the information
         * 6. Requested calendar year
         */
        $locale = strtoupper( explode('_', $this->LitSettings->Locale)[0] );
        $message = _( 'The %1$s \'%2$s\' has been added on %3$s since the year %4$d (%5$s), applicable to the year %6$d.' );
        $this->Messages[] = sprintf(
            $message,
            $this->LitGrade->i18n( $row->GRADE, false ),
            $row->NAME,
            $locale === LitLocale::LATIN ? ( $row->DATE->format( 'j' ) . ' ' . LitMessages::LATIN_MONTHS[ (int)$row->DATE->format( 'n' ) ] ) :
                ( $locale === 'EN' ? $row->DATE->format( 'F jS' ) :
                    $this->dayAndMonth->format( $row->DATE->format( 'U' ) )
                ),
            $row->yearSince,
            $row->DECREE,
            $this->LitSettings->Year
        );
    }

    private function calculateMemorials( int $grade = LitGrade::MEMORIAL, string $missal = RomanMissal::EDITIO_TYPICA_1970 ) : void {
        if( $missal === RomanMissal::EDITIO_TYPICA_1970 && $grade === LitGrade::MEMORIAL ) {
            $this->createImmaculateHeart();
        }
        $tempCal = array_filter( $this->tempCal[ $missal ], function( $el ) use ( $grade ){ return $el->GRADE === $grade; } );
        foreach ( $tempCal as $row ) {
            //If it doesn't occur on a Sunday or a Solemnity or a Feast of the Lord or a Feast or an obligatory memorial, then go ahead and create the optional memorial
            $row->DATE = LitDateTime::createFromFormat( '!j-n-Y', $row->DAY . '-' . $row->MONTH . '-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
            if ( self::DateIsNotSunday( $row->DATE ) && $this->Cal->notInSolemnitiesFeastsOrMemorials( $row->DATE ) ) {
                $newFestivity = new Festivity( $row->NAME, $row->DATE, $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON );
                //LitCalAPI::debugWrite( "adding new memorial '$row->NAME', common vartype = " . gettype( $row->COMMON ) . ", common = " . implode(', ', $row->COMMON) );
                //LitCalAPI::debugWrite( ">>> added new memorial '$newFestivity->name', common vartype = " . gettype( $newFestivity->common ) . ", common = " . implode(', ', $newFestivity->common) );

                $this->Cal->addFestivity( $row->TAG, $newFestivity );

                $this->reduceMemorialsInAdventLentToCommemoration( $row->DATE, $row );

                if( $missal === RomanMissal::EDITIO_TYPICA_TERTIA_2002 ) {
                    $row->yearSince = 2002;
                    $row->DECREE = '<a href="https://press.vatican.va/content/salastampa/it/bollettino/pubblico/2002/03/22/0150/00449.html">' . _( 'Vatican Press conference: Presentation of the Editio Typica Tertia of the Roman Missal' ) . '</a>';
                    $this->addMissalMemorialMessage( $row );
                }
                else if( $missal === RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008 ) {
                    $row->yearSince = 2008;
                    switch( $row->TAG ) {
                        case "StPioPietrelcina":
                            $row->DECREE = RomanMissal::getName( $missal );
                        break;
                        /**both of the following tags refer to the same decree, no need for a break between them */
                        case "LadyGuadalupe":
                        case "JuanDiego":
                            $langs = ["LA" => "lt", "ES" => "es"];
                            $lang = in_array( $this->LitSettings->Locale, array_keys($langs) ) ? $langs[$this->LitSettings->Locale] : "lt";
                            $row->DECREE = "<a href=\"http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_$lang.html\">" . _( 'Decree of the Congregation for Divine Worship' ) . '</a>';
                        break;
                    }
                    $this->addMissalMemorialMessage( $row );
                }
                if ( $grade === LitGrade::MEMORIAL && $this->Cal->getFestivity( $row->TAG )->grade > LitGrade::MEMORIAL_OPT ) {
                    $this->removeWeekdaysEpiphanyOverridenByMemorials( $row->TAG );
                }
            } else {
                if( false === $this->checkImmaculateHeartCoincidence( $row->DATE, $row ) ) {
                    $this->handleCoincidence( $row, RomanMissal::EDITIO_TYPICA_1970 );
                }
                else if( $missal === RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008 ) {
                    $this->handleCoincidence( $row, RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008 );
                }
            }
        }
        if( $missal === RomanMissal::EDITIO_TYPICA_TERTIA_2002 && $grade === LitGrade::MEMORIAL_OPT ) {
            $this->handleSaintJaneFrancesDeChantal();
        }
    }

    private function reduceMemorialsInAdventLentToCommemoration( DateTime $currentFeastDate, stdClass $row ) {
        //If a fixed date optional memorial falls between 17 Dec. to 24 Dec., the Octave of Christmas or weekdays of the Lenten season,
        //it is reduced in rank to a Commemoration ( only the collect can be used )
        if ( $this->Cal->inWeekdaysAdventChristmasLent( $currentFeastDate ) ) {
            $this->Cal->setProperty( $row->TAG, "grade", LitGrade::COMMEMORATION );
            /**translators:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Requested calendar year
             */
            $message = _( 'The %1$s \'%2$s\' either falls between 17 Dec. and 24 Dec., or during the Octave of Christmas, or on the weekdays of the Lenten season in the year %3$d, rank reduced to Commemoration.' );
            $this->Messages[] = sprintf(
                $message,
                $this->LitGrade->i18n( $row->GRADE, false ),
                $row->NAME,
                $this->LitSettings->Year
            );
        }
    }

    private function removeWeekdaysEpiphanyOverridenByMemorials( string $tag ) {
        $festivity = $this->Cal->getFestivity( $tag );
        if( $this->Cal->inWeekdaysEpiphany( $festivity->date ) ){
            $key = $this->Cal->weekdayEpiphanyKeyFromDate( $festivity->date );
            if ( false !== $key ) {
                /**translators:
                 * 1. Grade or rank of the festivity that has been superseded
                 * 2. Name of the festivity that has been superseded
                 * 3. Grade or rank of the festivity that is superseding
                 * 4. Name of the festivity that is superseding
                 * 5. Requested calendar year
                 */
                $message = _( 'The %1$s \'%2$s\' is superseded by the %3$s \'%4$s\' in the year %5$d.' );
                $this->Messages[] = sprintf(
                    $message,
                    $this->LitGrade->i18n( $this->Cal->getFestivity( $key )->grade ),
                    $this->Cal->getFestivity( $key )->name,
                    $this->LitGrade->i18n( $festivity->grade, false ),
                    $festivity->name,
                    $this->LitSettings->Year
                );
                $this->Cal->removeFestivity( $key );
            }
        }
    }

    private function handleCoincidence( stdClass $row, string $missal = RomanMissal::EDITIO_TYPICA_1970 ) {
        $coincidingFestivity = $this->Cal->determineSundaySolemnityOrFeast( $row->DATE, $this->LitSettings );
        switch( $missal ){
            case RomanMissal::EDITIO_TYPICA_1970:
                $YEAR = 1970;
                $lang = in_array($this->LitSettings->Locale, ["DE","EN","IT","LA","PT"]) ? strtolower($this->LitSettings->Locale) : "en";
                $DECREE = "<a href=\"https://www.vatican.va/content/paul-vi/$lang/apost_constitutions/documents/hf_p-vi_apc_19690403_missale-romanum.html\">" . _( 'Apostolic Constitution Missale Romanum' ) . "</a>";
                break;
            case RomanMissal::EDITIO_TYPICA_TERTIA_2002:
                $YEAR = 2002;
                $DECREE = '<a href="https://press.vatican.va/content/salastampa/it/bollettino/pubblico/2002/03/22/0150/00449.html">' . _( 'Vatican Press conference: Presentation of the Editio Typica Tertia of the Roman Missal' ) . '</a>';
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
        $locale = strtoupper( explode('_', $this->LitSettings->Locale)[0] );
        $message = _( 'The %1$s \'%2$s\', added in the %3$s of the Roman Missal since the year %4$d (%5$s) and usually celebrated on %6$s, is suppressed by the %7$s \'%8$s\' in the year %9$d.' );
        $this->Messages[] = sprintf(
            $message,
            $this->LitGrade->i18n( $row->GRADE, false ),
            $row->NAME,
            RomanMissal::getName( $missal ),
            $YEAR,
            $DECREE,
            $locale === LitLocale::LATIN ? ( $row->DATE->format( 'j' ) . ' ' . LitMessages::LATIN_MONTHS[  (int)$row->DATE->format( 'n' ) ] ) :
                ( $locale === 'EN' ? $row->DATE->format( 'F jS' ) :
                    $this->dayAndMonth->format( $row->DATE->format( 'U' ) )
                ),
            $coincidingFestivity->grade,
            $coincidingFestivity->event->name,
            $this->LitSettings->Year
        );
    }

    private function handleCoincidenceDecree( object $row ) : void {
        $lang = ( property_exists( $row->Metadata, 'decreeLangs' ) && property_exists( $row->Metadata->decreeLangs, $this->LitSettings->Locale ) ) ? 
            $row->Metadata->decreeLangs->{$this->LitSettings->Locale} :
            "en";
        $url = str_contains( $row->Metadata->decreeURL, '%s' ) ? sprintf($row->Metadata->decreeURL, $lang) : $row->Metadata->decreeURL;
        $decree = '<a href="' . $url . '">' . _( "Decree of the Congregation for Divine Worship" ) . '</a>';
        $coincidingFestivity = $this->Cal->determineSundaySolemnityOrFeast( $row->Festivity->DATE, $this->LitSettings );
        $locale = strtoupper( explode('_', $this->LitSettings->Locale)[0] );
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
            _( 'The %1$s \'%2$s\', added on %3$s since the year %4$d (%5$s), is however superseded by a %6$s \'%7$s\' in the year %8$d.' ),
            $this->LitGrade->i18n( $row->Festivity->GRADE ),
            $row->Festivity->NAME,
            $locale === LitLocale::LATIN ? ( $row->Festivity->DATE->format( 'j' ) . ' ' . LitMessages::LATIN_MONTHS[ (int)$row->Festivity->DATE->format( 'n' ) ] ) :
                ( $locale === 'EN' ? $row->Festivity->DATE->format( 'F jS' ) :
                    $this->dayAndMonth->format( $row->Festivity->DATE->format( 'U' ) )
                ),
            $row->Metadata->sinceYear,
            $decree,
            $coincidingFestivity->grade,
            $coincidingFestivity->event->name,
            $this->LitSettings->Year
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
                        /**translators:
                         * 1. Name of the first coinciding Memorial
                         * 2. Name of the second coinciding Memorial
                         * 3. Requested calendar year
                         * 4. Source of the information
                         */
                        _( 'The Memorial \'%1$s\' coincides with another Memorial \'%2$s\' in the year %3$d. They are both reduced in rank to optional memorials (%4$s).' ),
                        $ImmaculateHeart->name,
                        $festivity->name,
                        $this->LitSettings->Year,
                        '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html">' . _( 'Decree of the Congregation for Divine Worship' ) . '</a>'
                    );
                    $coincidence = true;
                }
            }
        }
        return $coincidence;
    }

    private function createFestivityFromDecree( object $row ) : void {
        if( $row->Festivity->TYPE === "mobile" ) {
            //we won't have a date defined for mobile festivites, we'll have to calculate them here case by case
            //otherwise we'll have to create a language that we can interpret in an automated fashion...
            //for example we can use strtotime
            if( property_exists( $row->Metadata, 'strtotime' ) ) {
                switch( gettype($row->Metadata->strtotime) ) {
                    case 'object':
                        if(
                            property_exists( $row->Metadata->strtotime, 'dayOfTheWeek' )
                            && property_exists( $row->Metadata->strtotime, 'relativeTime' )
                            && property_exists( $row->Metadata->strtotime, 'festivityKey' )
                        ) {
                            $festivity = $this->Cal->getFestivity( $row->Metadata->strtotime->festivityKey );
                            if( $festivity !== null ) {
                                $relString = '';
                                $row->Festivity->DATE = clone( $festivity->date );
                                switch( $row->Metadata->strtotime->relativeTime ) {
                                    case 'before':
                                        $row->Festivity->DATE->modify("previous {$row->Metadata->strtotime->dayOfTheWeek}");
                                         /**translators: e.g. 'Monday before Palm Sunday' */
                                        $relString = _( 'before' );
                                    break;
                                    case 'after':
                                        $row->Festivity->DATE->modify("next {$row->Metadata->strtotime->dayOfTheWeek}");
                                        /**translators: e.g. 'Monday after Pentecost' */
                                        $relString = _( 'after' );
                                    break;
                                    default:
                                        $this->Messages[] = sprintf(
                                            /**translators: 1. Name of the mobile festivity being created, 2. Name of the festivity that it is relative to */
                                            _( 'Cannot create mobile festivity \'%1$s\': can only be relative to festivity with key \'%2$s\' using keywords %3$s' ),
                                            $row->Festivity->name,
                                            $row->Metadata->strtotime->festivityKey,
                                            implode(', ', ['\'before\'', '\'after\''])
                                        );
                                    break;
                                }
                                $dayOfTheWeek = $this->LitSettings->Locale === LitLocale::LATIN ? LitMessages::LATIN_DAYOFTHEWEEK[ $row->Festivity->DATE->format( 'w' ) ] : ucfirst( $this->dayOfTheWeek->format( $row->Festivity->DATE->format( 'U' ) ) );
                                $row->Metadata->addedWhen = $dayOfTheWeek . ' ' . $relString . ' ' . $festivity->name;
                                if( true === $this->checkCoincidencesNewMobileFestivity( $row ) ) {
                                    $this->createMobileFestivity( $row );
                                }
                            } else {
                                $this->Messages[] = sprintf(
                                    /**translators: 1. Name of the mobile festivity being created, 2. Name of the festivity that it is relative to */
                                    _( 'Cannot create mobile festivity \'%1$s\' relative to festivity with key \'%2$s\'' ),
                                    $row->Festivity->name,
                                    $row->Metadata->strtotime->festivityKey
                                );
                            }
                        } else {
                            $this->Messages[] = sprintf(
                                /**translators: Do not translate 'strtotime'! 1. Name of the mobile festivity being created 2. list of properties */
                                _( 'Cannot create mobile festivity \'%1$s\': when the \'strtotime\' property is an object, it must have properties %2$s' ),
                                $row->Festivity->name,
                                implode(', ', ['\'dayOfTheWeek\'', '\'relativeTime\'', '\'festivityKey\''])
                            );
                        }
                        break;
                    case 'string':
                        if( $row->Metadata->strtotime !== '' ) {
                            $festivityDateTS = strtotime( $row->Metadata->strtotime . ' ' . $this->LitSettings->Year . ' UTC' );
                            $row->Festivity->DATE = new LitDateTime( "@$festivityDateTS" );
                            $row->Festivity->DATE->setTimeZone(new DateTimeZone('UTC'));
                            $row->Metadata->addedWhen = $row->Metadata->strtotime;
                            if( true === $this->checkCoincidencesNewMobileFestivity( $row ) ) {
                                $this->createMobileFestivity( $row );
                            }
                        }
                        break;
                    default:
                        $this->Messages[] = sprintf(
                            /**translators: Do not translate 'strtotime'! 1. Name of the mobile festivity being created */
                            _( 'Cannot create mobile festivity \'%1$s\': \'strtotime\' property must be either an object or a string! Currently it has type \'%2$s\'' ),
                            $row->Festivity->name,
                            gettype( $row->Metadata->strtotime )
                        );
                }
            } else {
                $this->Messages[] = sprintf(
                    /**translators: Do not translate 'strtotime'! 1. Name of the mobile festivity being created */
                    _( 'Cannot create mobile festivity \'%1$s\' without a \'strtotime\' property!' ),
                    $row->Festivity->name
                );
            }
        } else {
            $row->Festivity->DATE = LitDateTime::createFromFormat( '!j-n-Y', "{$row->Festivity->DAY}-{$row->Festivity->MONTH}-{$this->LitSettings->Year}", new DateTimeZone( 'UTC' ) );
            $decree = $this->elaborateDecreeSource( $row );
            $locale = strtoupper( explode('_', $this->LitSettings->Locale)[0] );
            if( $row->Festivity->GRADE === LitGrade::MEMORIAL_OPT ) {
                if( $this->Cal->notInSolemnitiesFeastsOrMemorials( $row->Festivity->DATE ) ) {
                    $festivity = new Festivity( $row->Festivity->NAME, $row->Festivity->DATE, $row->Festivity->COLOR, LitFeastType::FIXED, $row->Festivity->GRADE, $row->Festivity->COMMON );
                    $this->Cal->addFestivity( $row->Festivity->TAG, $festivity );
                    $this->Messages[] = sprintf(
                        /**translators:
                         * 1. Grade or rank of the festivity
                         * 2. Name of the festivity
                         * 3. Day of the festivity
                         * 4. Year from which the festivity has been added
                         * 5. Source of the information
                         * 6. Requested calendar year
                         */
                        _( 'The %1$s \'%2$s\' has been added on %3$s since the year %4$d (%5$s), applicable to the year %6$d.' ),
                        $this->LitGrade->i18n( $row->Festivity->GRADE, false ),
                        $row->Festivity->NAME,
                        $locale === LitLocale::LATIN ? ( $row->Festivity->DATE->format( 'j' ) . ' ' . LitMessages::LATIN_MONTHS[ (int)$row->Festivity->DATE->format( 'n' ) ] ) :
                            ( $locale === 'EN' ? $row->Festivity->DATE->format( 'F jS' ) :
                                $this->dayAndMonth->format( $row->Festivity->DATE->format( 'U' ) )
                            ),
                        $row->Metadata->sinceYear,
                        $decree,
                        $this->LitSettings->Year
                    );
                }
                else{
                    $this->handleCoincidenceDecree( $row );
                }
            }
        }
    }

    private function setPropertyBasedOnDecree( object $row ) : void {
        $festivity = $this->Cal->getFestivity( $row->Festivity->TAG );
        if ( $festivity !== null ) {
            $decree = $this->elaborateDecreeSource( $row );
            switch( $row->Metadata->property ) {
                case "name":
                    //example: StMartha becomes Martha, Mary and Lazarus in 2021
                    $this->Cal->setProperty( $row->Festivity->TAG, "name", $row->Festivity->NAME );
                    /**translators:
                     * 1. Grade or rank of the festivity
                     * 2. Name of the festivity
                     * 3. New name of the festivity
                     * 4. Year from which the grade has been changed
                     * 5. Requested calendar year
                     * 6. Source of the information
                     */
                    $message = _( 'The name of the %1$s \'%2$s\' has been changed to %3$s since the year %4$d, applicable to the year %5$d (%6$s).' );
                    $this->Messages[] = sprintf(
                        $message,
                        $this->LitGrade->i18n( $festivity->grade, false ),
                        '<i>' . $festivity->name . '</i>',
                        '<i>' . $row->Festivity->NAME . '</i>',
                        $row->Metadata->sinceYear,
                        $this->LitSettings->Year,
                        $decree
                    );
                break;
                case "grade":
                    if( $row->Festivity->GRADE > $festivity->grade ) {
                        //example: StMaryMagdalene raised to Feast in 2016
                        /**translators:
                         * 1. Grade or rank of the festivity
                         * 2. Name of the festivity
                         * 3. New grade of the festivity
                         * 4. Year from which the grade has been changed
                         * 5. Requested calendar year
                         * 6. Source of the information
                         */
                        $message = _( 'The %1$s \'%2$s\' has been raised to the rank of %3$s since the year %4$d, applicable to the year %5$d (%6$s).' );
                    } else {
                        /**translators:
                         * 1. Grade or rank of the festivity
                         * 2. Name of the festivity
                         * 3. New grade of the festivity
                         * 4. Year from which the grade has been changed
                         * 5. Requested calendar year
                         * 6. Source of the information
                         */
                        $message = _( 'The %1$s \'%2$s\' has been lowered to the rank of %3$s since the year %4$d, applicable to the year %5$d (%6$s).' );
                    }
                    $this->Messages[] = sprintf(
                        $message,
                        $this->LitGrade->i18n( $festivity->grade, false ),
                        $festivity->name,
                        $this->LitGrade->i18n( $row->Festivity->GRADE, false ),
                        $row->Metadata->sinceYear,
                        $this->LitSettings->Year,
                        $decree
                    );
                    $this->Cal->setProperty( $row->Festivity->TAG, "grade", $row->Festivity->GRADE );
                break;
            }
        }
    }

    private function createDoctorsFromDecrees() : void {
        $DoctorsDecrees = array_filter(
            $this->tempCal[ "MEMORIALS_FROM_DECREES" ],
            function( $row ) {
                return $row->Metadata->action === "makeDoctor";
            });
        foreach( $DoctorsDecrees as $row ) {
            if( $this->LitSettings->Year >= $row->Metadata->sinceYear ) {
                $festivity = $this->Cal->getFestivity( $row->Festivity->TAG );
                if( $festivity !== null ) {
                    $decree = $this->elaborateDecreeSource( $row );
                    /**translators:
                     * 1. Name of the festivity
                     * 2. Year in which was declared Doctor
                     * 3. Requested calendar year
                     * 4. Source of the information
                     */
                    $message = _( '\'%1$s\' has been declared a Doctor of the Church since the year %2$d, applicable to the year %3$d (%4$s).' );
                    $this->Messages[] = sprintf(
                        $message,
                        '<i>' . $festivity->name . '</i>',
                        $row->Metadata->sinceYear,
                        $this->LitSettings->Year,
                        $decree
                    );
                    $etDoctor = $this->LitSettings->Locale === LitLocale::LATIN ? " et Ecclesiæ doctoris" : " " . _( "and Doctor of the Church" );
                    $this->Cal->setProperty( $row->Festivity->TAG, "name", $festivity->name . $etDoctor );
                }
            }
        }
    }

    private function elaborateDecreeSource( object $row ) : string {
        $lang = ( property_exists( $row->Metadata, 'decreeLangs' ) && property_exists( $row->Metadata->decreeLangs, $this->LitSettings->Locale ) ) ? 
            $row->Metadata->decreeLangs->{$this->LitSettings->Locale} :
            "en";
        $url = str_contains( $row->Metadata->decreeURL, '%s' ) ? sprintf($row->Metadata->decreeURL, $lang) : $row->Metadata->decreeURL;
        return '<a href="' . $url . '">' . _( "Decree of the Congregation for Divine Worship" ) . '</a>';
    }

    private function applyDecrees( int|string $grade = LitGrade::MEMORIAL ) : void {
        if( !isset($this->tempCal[ "MEMORIALS_FROM_DECREES" ]) || !is_array( $this->tempCal[ "MEMORIALS_FROM_DECREES" ] ) ) {
            die( '{"ERROR": "We seem to be missing data for Memorials based on Decrees: array data was not found!"}' );
        }
        if( gettype($grade) === "integer" ) {
            $MemorialsFromDecrees = array_filter(
                $this->tempCal[ "MEMORIALS_FROM_DECREES" ],
                function( $row ) use ( $grade ) {
                    return $row->Metadata->action !== "makeDoctor" && $row->Festivity->GRADE === $grade;
                });
            foreach( $MemorialsFromDecrees as $row ) {
                if( $this->LitSettings->Year >= $row->Metadata->sinceYear ) {
                    switch( $row->Metadata->action ) {
                        case "createNew":
                            //example: MaryMotherChurch in 2018
                            $this->createFestivityFromDecree( $row );
                        break;
                        case "setProperty":
                            $this->setPropertyBasedOnDecree( $row );
                        break;
                    }
                }
            }

            if( $this->LitSettings->Year === 2009 ) {
                //Conversion of St. Paul falls on a Sunday in the year 2009
                //Faculty to celebrate as optional memorial
                $this->applyOptionalMemorialDecree2009();
            }
        }
        else if( gettype($grade) === "string" && $grade === "DOCTORS" ) {
            $this->createDoctorsFromDecrees();
        }
    }

    private function createMobileFestivity( object $row ) : void {
        $festivity = new Festivity( $row->Festivity->NAME, $row->Festivity->DATE, $row->Festivity->COLOR, LitFeastType::MOBILE, $row->Festivity->GRADE, $row->Festivity->COMMON );
        $this->Cal->addFestivity( $row->Festivity->TAG, $festivity );
        $lang = ( property_exists( $row->Metadata, 'decreeLangs' ) && property_exists( $row->Metadata->decreeLangs, $this->LitSettings->Locale ) ) ? 
            $row->Metadata->decreeLangs->{$this->LitSettings->Locale} :
            "en";
        $url = str_contains( $row->Metadata->decreeURL, '%s' ) ? sprintf($row->Metadata->decreeURL, $lang) : $row->Metadata->decreeURL;
        $decree = '<a href="' . $url . '">' . _( "Decree of the Congregation for Divine Worship" ) . '</a>';

        $this->Messages[] = sprintf(
            /**translators:
             * 1. Grade or rank of the festivity being created
             * 2. Name of the festivity being created
             * 3. Indication of the mobile date for the festivity being created
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Requested calendar year
             */
            _( 'The %1$s \'%2$s\' has been added on %3$s since the year %4$d (%5$s), applicable to the year %6$d.' ),
            $this->LitGrade->i18n( $row->Festivity->GRADE, false ),
            $row->Festivity->NAME,
            $row->Metadata->addedWhen,
            $row->Metadata->sinceYear,
            $decree,
            $this->LitSettings->Year
        );
    }

    private function checkCoincidencesNewMobileFestivity( object $row ) : bool {
        if( $row->Festivity->GRADE === LitGrade::MEMORIAL ) {
            $lang = ( property_exists( $row->Metadata, 'decreeLangs' ) && property_exists( $row->Metadata->decreeLangs, $this->LitSettings->Locale ) ) ? 
                $row->Metadata->decreeLangs->{$this->LitSettings->Locale} :
                "en";
            $url = str_contains( $row->Metadata->decreeURL, '%s' ) ? sprintf($row->Metadata->decreeURL, $lang) : $row->Metadata->decreeURL;
            $decree = '<a href="' . $url . '">' . _( "Decree of the Congregation for Divine Worship" ) . '</a>';

            //A Memorial is superseded by Solemnities and Feasts, but not by Memorials of Saints
            if( $this->Cal->inSolemnities( $row->Festivity->DATE ) || $this->Cal->inFeasts( $row->Festivity->DATE ) ) {
                if( $this->Cal->inSolemnities( $row->Festivity->DATE ) ) {
                    $coincidingFestivity = $this->Cal->solemnityFromDate( $row->Festivity->DATE );
                } else {
                    $coincidingFestivity = $this->Cal->feastOrMemorialFromDate( $row->Festivity->DATE );
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
                    _( 'The %1$s \'%2$s\', added on %3$s since the year %4$d (%5$s), is however superseded by the %6$s \'%7$s\' in the year %8$d.' ),
                    $this->LitGrade->i18n( $row->Festivity->GRADE, false ),
                    '<i>' . $row->Festivity->NAME . '</i>',
                    $row->Metadata->addedWhen,
                    $row->Metadata->sinceYear,
                    $decree,
                    $coincidingFestivity->grade,
                    '<i>' . $coincidingFestivity->name . '</i>',
                    $this->LitSettings->Year
                );
                return false;
            }
            else {
                if( $this->Cal->inCalendar( $row->Festivity->DATE ) ) {
                    $coincidingFestivities = $this->Cal->getCalEventsFromDate( $row->Festivity->DATE );
                    if( count( $coincidingFestivities ) > 0 ){
                        foreach( $coincidingFestivities as $coincidingFestivityKey => $coincidingFestivity ) {
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
                                _( 'In the year %1$d, the %2$s \'%3$s\' has been suppressed by the %4$s \'%5$s\', added on %6$s since the year %7$d (%8$s).' ),
                                $this->LitGrade->i18n( $coincidingFestivity->grade, false ),
                                '<i>' . $coincidingFestivity->name . '</i>',
                                $this->LitGrade->i18n( $row->Festivity->GRADE, false ),
                                '<i>' . $row->Festivity->NAME . '</i>',
                                $row->Metadata->addedWhen,
                                $row->Metadata->sinceYear,
                                $decree
                            );
                            $this->Cal->removeFestivity( $coincidingFestivityKey );
                        }
                    }
                }
                return true;
            }
        }
        return false;
    }

    private function createImmaculateHeart() {
        $row = new stdClass();
        $row->DATE = LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P' . ( 7 * 9 + 6 ) . 'D' ) );
        if ( $this->Cal->notInSolemnitiesFeastsOrMemorials( $row->DATE ) ) {
            //Immaculate Heart of Mary fixed on the Saturday following the second Sunday after Pentecost
            //( see Calendarium Romanum Generale in Missale Romanum Editio Typica 1970 )
            //Pentecost = LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P'.( 7*7 ).'D' ) )
            //Second Sunday after Pentecost = LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P'.( 7*9 ).'D' ) )
            //Following Saturday = LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P'.( 7*9+6 ).'D' ) )
            $this->Cal->addFestivity( "ImmaculateHeart", new Festivity( $this->PropriumDeTempore[ "ImmaculateHeart" ][ "NAME" ], $row->DATE, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::MEMORIAL ) );

            //In years when this memorial coincides with another obligatory memorial, as happened in 2014 [ 28 June, Saint Irenaeus ] and 2015 [ 13 June, Saint Anthony of Padua ], both must be considered optional for that year
            //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
            //This is taken care of in the next code cycle, see tag IMMACULATEHEART: in the code comments ahead
        } else {
            $row = (object)$this->PropriumDeTempore[ "ImmaculateHeart" ];
            $row->GRADE = LitGrade::MEMORIAL;
            $row->DATE = LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P' . ( 7 * 9 + 6 ) . 'D' ) );
            $this->handleCoincidence( $row, RomanMissal::EDITIO_TYPICA_1970 );
        }
    }

    /**
     * In the Tertia Editio Typica (2002),
     * Saint Jane Frances de Chantal was moved from December 12 to August 12,
     * probably to allow local bishop's conferences to insert Our Lady of Guadalupe as an optional memorial on December 12
     * seeing that with the decree of March 25th 1999 of the Congregation of Divine Worship
     * Our Lady of Guadalupe was granted as a Feast day for all dioceses and territories of the Americas
     * source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_lt.html
     */
    private function handleSaintJaneFrancesDeChantal() {
        $StJaneFrancesNewDate = LitDateTime::createFromFormat( '!j-n-Y', '12-8-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
        $langs = ["LA" => "lt", "ES" => "es"];
        $lang = in_array( $this->LitSettings->Locale, array_keys($langs) ) ? $langs[$this->LitSettings->Locale] : "lt";
        if ( self::DateIsNotSunday( $StJaneFrancesNewDate ) && $this->Cal->notInSolemnitiesFeastsOrMemorials( $StJaneFrancesNewDate ) ) {
            $festivity = $this->Cal->getFestivity( "StJaneFrancesDeChantal" );
            if( $festivity !== null ) {
                $this->Cal->moveFestivityDate( "StJaneFrancesDeChantal", $StJaneFrancesNewDate );
                $this->Messages[] = sprintf(
                    /**translators: 1: Festivity name, 2: Source of the information, 3: Requested calendar year  */
                    _( 'The optional memorial \'%1$s\' has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d.' ),
                    $festivity->name,
                    "<a href=\"http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_$lang.html\">" . _( 'Decree of the Congregation for Divine Worship' ) . '</a>',
                    $this->LitSettings->Year
                );
            } else {
                //perhaps it wasn't created on December 12th because it was superseded by a Sunday, Solemnity or Feast
                //but seeing that there is no problem for August 12th, let's go ahead and try creating it again
                $row = $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ][ 'StJaneFrancesDeChantal' ];
                $festivity = new Festivity( $row->NAME, $StJaneFrancesNewDate, $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON );
                $this->Cal->addFestivity( "StJaneFrancesDeChantal", $festivity );
                $this->Messages[] = sprintf(
                    /**translators: 1: Festivity name, 2: Source of the information, 3: Requested calendar year  */
                    _( 'The optional memorial \'%1$s\', which would have been superseded this year by a Sunday or Solemnity were it on Dec. 12, has however been transferred to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d.' ),
                    $festivity->name,
                    "<a href=\"http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_$lang.html\">" . _( 'Decree of the Congregation for Divine Worship' ) . '</a>',
                    $this->LitSettings->Year
                );
            }
        } else {
            $coincidingFestivity = $this->Cal->determineSundaySolemnityOrFeast( $StJaneFrancesNewDate );
            $festivity = $this->Cal->getFestivity( "StJaneFrancesDeChantal" );
            //we can't move it, but we still need to remove it from Dec 12th if it's there!!!
            if( $festivity !== null ) {
                $this->Cal->removeFestivity( "StJaneFrancesDeChantal" );
            }
            $row = $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ][ 'StJaneFrancesDeChantal' ];
            $this->Messages[] = sprintf(
                _( 'The optional memorial \'%1$s\' has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d. However, it is superseded by a Sunday, a Solemnity, or a Feast \'%4$s\' in the year %3$d.' ),
                $row->NAME,
                "<a href=\"http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_$lang.html\">" . _( 'Decree of the Congregation for Divine Worship' ) . '</a>',
                $this->LitSettings->Year,
                $coincidingFestivity->event->name
            );
        }
    }


    /**
     * The Conversion of St. Paul falls on a Sunday in the year 2009.
     * However, considering that it is the Year of Saint Paul,
     * with decree of Jan 25 2008 the Congregation for Divine Worship gave faculty to the single churches
     * to celebrate the Conversion of St. Paul anyways. So let's re-insert it as an optional memorial?
     * http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20080125_san-paolo_la.html
     */
    private function applyOptionalMemorialDecree2009() : void {
        $festivity = $this->Cal->getFestivity( "ConversionStPaul" );
        if( $festivity === null ) {
            $row = $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ][ "ConversionStPaul" ];
            $festivity = new Festivity( $row->NAME, LitDateTime::createFromFormat( '!j-n-Y', '25-1-2009', new DateTimeZone( 'UTC' ) ), LitColor::WHITE, LitFeastType::FIXED, LitGrade::MEMORIAL_OPT, LitCommon::PROPRIO );
            $this->Cal->addFestivity( "ConversionStPaul", $festivity );
            $langs = ["FR" => "fr", "EN" => "en", "IT" => "it", "LA" => "lt", "PT" => "pt", "ES" => "sp", "DE" => "ge"];
            $lang = in_array( $this->LitSettings->Locale, array_keys($langs) ) ? $langs[$this->LitSettings->Locale] : "en";
            $this->Messages[] = sprintf(
                /**translators: 1: Festivity name, 2: Source of the information  */
                _( 'The Feast \'%1$s\' would have been suppressed this year ( 2009 ) since it falls on a Sunday, however being the Year of the Apostle Paul, as per the %2$s it has been reinstated so that local churches can optionally celebrate the memorial.' ),
                '<i>' . $row->NAME . '</i>',
                "<a href=\"http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20080125_san-paolo_$lang.html\">" . _( 'Decree of the Congregation for Divine Worship' ) . '</a>'
            );
        }
    }

    //13. Weekdays of Advent up until Dec. 16 included ( already calculated and defined together with weekdays 17 Dec. - 24 Dec. )
    //    Weekdays of Christmas season from 2 Jan. until the Saturday after Epiphany
    //    Weekdays of the Easter season, from the Monday after the Octave of Easter to the Saturday before Pentecost
    private function calculateWeekdaysMajorSeasons() : void {
        $DoMEaster = $this->Cal->getFestivity( "Easter" )->date->format( 'j' );      //day of the month of Easter
        $MonthEaster = $this->Cal->getFestivity( "Easter" )->date->format( 'n' );    //month of Easter
        //let's start cycling dates one at a time starting from Easter itself
        $weekdayEaster = LitDateTime::createFromFormat( '!j-n-Y', $DoMEaster . '-' . $MonthEaster . '-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
        $weekdayEasterCnt = 1;
        while ( $weekdayEaster >= $this->Cal->getFestivity( "Easter" )->date && $weekdayEaster < $this->Cal->getFestivity( "Pentecost" )->date ) {
            $weekdayEaster = LitDateTime::createFromFormat( '!j-n-Y', $DoMEaster . '-' . $MonthEaster . '-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) )->add( new DateInterval( 'P' . $weekdayEasterCnt . 'D' ) );
            if ( $this->Cal->notInSolemnitiesFeastsOrMemorials( $weekdayEaster ) && self::DateIsNotSunday( $weekdayEaster ) ) {
                $upper =  (int)$weekdayEaster->format( 'z' );
                $diff = $upper - (int)$this->Cal->getFestivity( "Easter" )->date->format( 'z' ); //day count between current day and Easter Sunday
                $currentEasterWeek = ( ( $diff - $diff % 7 ) / 7 ) + 1;         //week count between current day and Easter Sunday
                $ordinal = ucfirst( LitMessages::getOrdinal( $currentEasterWeek, $this->LitSettings->Locale, $this->formatterFem, LitMessages::LATIN_ORDINAL_FEM_GEN ) );
                $dayOfTheWeek = $this->LitSettings->Locale === LitLocale::LATIN ? LitMessages::LATIN_DAYOFTHEWEEK[ $weekdayEaster->format( 'w' ) ] : ucfirst( $this->dayOfTheWeek->format( $weekdayEaster->format( 'U' ) ) );
                $t = $this->LitSettings->Locale === LitLocale::LATIN ? sprintf( "Hebdomadæ %s Temporis Paschali", $ordinal ) : sprintf( _( "of the %s Week of Easter" ), $ordinal );
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
        $firstOrdinary = LitDateTime::createFromFormat( '!j-n-Y', $this->BaptismLordFmt, new DateTimeZone( 'UTC' ) )->modify( $this->BaptismLordMod );
        $firstSunday = LitDateTime::createFromFormat( '!j-n-Y', $this->BaptismLordFmt, new DateTimeZone( 'UTC' ) )->modify( $this->BaptismLordMod )->modify( 'next Sunday' );
        $dayFirstSunday =  (int)$firstSunday->format( 'z' );

        while ( $firstOrdinary >= $FirstWeekdaysLowerLimit && $firstOrdinary < $FirstWeekdaysUpperLimit ) {
            $firstOrdinary = LitDateTime::createFromFormat( '!j-n-Y', $this->BaptismLordFmt, new DateTimeZone( 'UTC' ) )->modify( $this->BaptismLordMod )->add( new DateInterval( 'P' . $ordWeekday . 'D' ) );
            if ( $this->Cal->notInSolemnitiesFeastsOrMemorials( $firstOrdinary ) ) {
                //The Baptism of the Lord is the First Sunday, so the weekdays following are of the First Week of Ordinary Time
                //After the Second Sunday, let's calculate which week of Ordinary Time we're in
                if ( $firstOrdinary > $firstSunday ) {
                    $upper          = (int)$firstOrdinary->format( 'z' );
                    $diff           = $upper - $dayFirstSunday;
                    $currentOrdWeek = ( ( $diff - $diff % 7 ) / 7 ) + 2;
                }
                $ordinal = ucfirst( LitMessages::getOrdinal( $currentOrdWeek, $this->LitSettings->Locale, $this->formatterFem,LitMessages::LATIN_ORDINAL_FEM_GEN ) );
                $dayOfTheWeek = $this->LitSettings->Locale === LitLocale::LATIN ? LitMessages::LATIN_DAYOFTHEWEEK[ $firstOrdinary->format( 'w' ) ] : ucfirst( $this->dayOfTheWeek->format( $firstOrdinary->format( 'U' ) ) );
                $nthStr = $this->LitSettings->Locale === LitLocale::LATIN ? sprintf( "Hebdomadæ %s Temporis Ordinarii", $ordinal ) : sprintf( _( "of the %s Week of Ordinary Time" ), $ordinal );
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
        $SecondWeekdaysUpperLimit = LitDateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' )->sub( new DateInterval( 'P' . ( 3 * 7 ) . 'D' ) );

        $ordWeekday = 1;
        //$currentOrdWeek = 1;
        $lastOrdinary = LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P' . ( 7 * 7 ) . 'D' ) );
        $dayLastSunday =  (int)LitDateTime::createFromFormat( '!j-n-Y', '25-12-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) )->modify( 'last Sunday' )->sub( new DateInterval( 'P' . ( 3 * 7 ) . 'D' ) )->format( 'z' );

        while ( $lastOrdinary >= $SecondWeekdaysLowerLimit && $lastOrdinary < $SecondWeekdaysUpperLimit ) {
            $lastOrdinary = LitFunc::calcGregEaster( $this->LitSettings->Year )->add( new DateInterval( 'P' . ( 7 * 7 + $ordWeekday ) . 'D' ) );
            if ( $this->Cal->notInSolemnitiesFeastsOrMemorials( $lastOrdinary ) ) {
                $lower          = (int)$lastOrdinary->format( 'z' );
                $diff           = $dayLastSunday - $lower; //day count between current day and Christ the King Sunday
                $weekDiff       = ( ( $diff - $diff % 7 ) / 7 ); //week count between current day and Christ the King Sunday;
                $currentOrdWeek = 34 - $weekDiff;

                $ordinal = ucfirst( LitMessages::getOrdinal( $currentOrdWeek, $this->LitSettings->Locale, $this->formatterFem,LitMessages::LATIN_ORDINAL_FEM_GEN ) );
                $dayOfTheWeek = $this->LitSettings->Locale === LitLocale::LATIN ? LitMessages::LATIN_DAYOFTHEWEEK[ $lastOrdinary->format( 'w' ) ] : ucfirst( $this->dayOfTheWeek->format( $lastOrdinary->format( 'U' ) ) );
                $nthStr = $this->LitSettings->Locale === LitLocale::LATIN ? sprintf( "Hebdomadæ %s Temporis Ordinarii", $ordinal ) : sprintf( _( "of the %s Week of Ordinary Time" ), $ordinal );
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
        $currentSaturday = new LitDateTime( "previous Saturday January {$this->LitSettings->Year}", new DateTimeZone( 'UTC' ) );
        $lastSatDT = new LitDateTime( "last Saturday December {$this->LitSettings->Year}", new DateTimeZone( 'UTC' ) );
        $SatMemBVM_cnt = 0;
        while( $currentSaturday <= $lastSatDT ){
            $currentSaturday = LitDateTime::createFromFormat( '!j-n-Y', $currentSaturday->format( 'j-n-Y' ),new DateTimeZone( 'UTC' ) )->modify( 'next Saturday' );
            if( $this->Cal->inOrdinaryTime( $currentSaturday ) && $this->Cal->notInSolemnitiesFeastsOrMemorials( $currentSaturday ) ) {
                $memID = "SatMemBVM" . ++$SatMemBVM_cnt;
                $name = $this->LitSettings->Locale === LitLocale::LATIN ? "Memoria Sanctæ Mariæ in Sabbato" : _( "Saturday Memorial of the Blessed Virgin Mary" );
                $festivity = new Festivity( $name, $currentSaturday, LitColor::WHITE, LitFeastType::MOBILE, LitGrade::MEMORIAL_OPT, LitCommon::BEATAE_MARIAE_VIRGINIS );
                $this->Cal->addFestivity( $memID, $festivity );
            }
        }
    }

    private function loadNationalCalendarData() : void {
        $nationalDataFile = "nations/{$this->LitSettings->NationalCalendar}/{$this->LitSettings->NationalCalendar}.json";
        if( file_exists( $nationalDataFile ) ) {
            $this->NationalData = json_decode( file_get_contents( $nationalDataFile ) );
            if( json_last_error() === JSON_ERROR_NONE ) {
                if( property_exists( $this->NationalData, "Metadata" ) && property_exists( $this->NationalData->Metadata, "WiderRegion" ) ) {
                    $widerRegionDataFile = $this->NationalData->Metadata->WiderRegion->jsonFile;
                    $widerRegionI18nFile = $this->NationalData->Metadata->WiderRegion->i18nFile;
                    if( file_exists( $widerRegionI18nFile ) ) {
                        $widerRegionI18nData = json_decode( file_get_contents( $widerRegionI18nFile ) );
                        if( json_last_error() === JSON_ERROR_NONE && file_exists( $widerRegionDataFile ) ) {
                            $this->WiderRegionData = json_decode( file_get_contents( $widerRegionDataFile ) );
                            if( json_last_error() === JSON_ERROR_NONE && property_exists( $this->WiderRegionData, "LitCal" ) ) {
                                foreach( $this->WiderRegionData->LitCal as $idx => $value ) {
                                    $tag = $value->Festivity->tag;
                                    $this->WiderRegionData->LitCal[$idx]->Festivity->name = $widerRegionI18nData->{ $tag };
                                }
                            } else {
                                $this->Messages[] = sprintf( _( "Error retrieving and decoding Wider Region data from file %s." ), $widerRegionDataFile ) . ": " . json_last_error_msg();
                            }
                        } else {
                            $this->Messages[] = sprintf( _( "Error retrieving and decoding Wider Region data from file %s." ), $widerRegionI18nFile ) . ": " . json_last_error_msg();
                        }
                    }
                } else {
                    $this->Messages[] = "Could not find a WiderRegion property in the Metadata for the National Calendar {$this->LitSettings->NationalCalendar}";
                }
            } else {
                $this->Messages[] = sprintf( _( "Error retrieving and decoding National data from file %s." ), $nationalDataFile ) . ": " . json_last_error_msg();
            }
        }
    }

    private function handleMissingFestivity( object $row ) : void {
        $currentFeastDate = LitDateTime::createFromFormat( '!j-n-Y', "{$row->Festivity->day}-{$row->Festivity->month}-" . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
        //let's also get the name back from the database, so we can give some feedback and maybe even recreate the festivity
        if( $this->Cal->inSolemnitiesFeastsOrMemorials( $currentFeastDate ) || self::DateIsSunday( $currentFeastDate ) ) {
            $coincidingFestivity = $this->Cal->determineSundaySolemnityOrFeast( $currentFeastDate, $this->LitSettings );
            if ( $this->Cal->inFeastsOrMemorials( $currentFeastDate ) ) {
                //we should probably be able to create it anyways in this case?
                $this->Cal->addFestivity(
                    $row->Festivity->tag,
                    new Festivity(
                        $row->Festivity->name,
                        $currentFeastDate,
                        $row->Festivity->color,
                        LitFeastType::FIXED,
                        $row->Festivity->grade,
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
                _( 'The %1$s \'%2$s\', usually celebrated on %3$s, is suppressed by the %4$s \'%5$s\' in the year %6$d.' ),
                $this->LitGrade->i18n( $row->Festivity->grade, false ),
                $row->Festivity->name,
                $this->dayAndMonth->format( $currentFeastDate->format( 'U' ) ),
                $coincidingFestivity->grade,
                $coincidingFestivity->event->name,
                $this->LitSettings->Year
            );
        }
    }

    private function festivityCanBeCreated( object $row ) : bool {
        switch( $row->Festivity->grade ) {
            case LitGrade::MEMORIAL_OPT:
                return $this->Cal->notInSolemnitiesFeastsOrMemorials( $row->Festivity->DATE );
            case LitGrade::MEMORIAL:
                return $this->Cal->notInSolemnitiesOrFeasts( $row->Festivity->DATE );
                //however we still have to handle possible coincidences with another memorial
            case LitGrade::FEAST:
                return $this->Cal->notInSolemnities( $row->Festivity->DATE );
                //however we still have to handle possible coincidences with another feast
            case LitGrade::SOLEMNITY:
                return true;
                //however we still have to handle possible coincidences with another solemnity
        }
        return false;
    }

    private function festivityDoesNotCoincide( object $row ) : bool {
        switch( $row->Festivity->grade ) {
            case LitGrade::MEMORIAL_OPT:
                return true;
                //optional memorials never have problems as regards coincidence with another optional memorial
            case LitGrade::MEMORIAL:
                return $this->Cal->notInMemorials( $row->Festivity->DATE );
            case LitGrade::FEAST:
                return $this->Cal->notInFeasts( $row->Festivity->DATE );
            case LitGrade::SOLEMNITY:
                return $this->Cal->notInSolemnities( $row->Festivity->DATE );
        }
        //functions should generally have a default return value
        //however, it would make no sense to give a default return value here
        //we really need to cover all cases and give a sure return value
    }

    private function handleFestivityCreationWithCoincidence( object $row ) : void {
        switch( $row->Festivity->grade ) {
            case LitGrade::MEMORIAL:
                //both memorials become optional memorials
                $coincidingFestivities = $this->Cal->getCalEventsFromDate( $row->Festivity->DATE );
                $coincidingMemorials = array_filter( $coincidingFestivities, function( $el ) { return $el->grade === LitGrade::MEMORIAL; } );
                $coincidingMemorialName = '';
                foreach( $coincidingMemorials as $key => $value ) {
                    $this->Cal->setProperty( $key, "grade", LitGrade::MEMORIAL_OPT );
                    $coincidingMemorialName = $value->name;
                }
                $festivity = new Festivity( $row->Festivity->name, $row->Festivity->DATE, $row->Festivity->color, LitFeastType::FIXED, LitGrade::MEMORIAL_OPT, $row->Festivity->common );
                $this->Cal->addFestivity( $row->Festivity->tag, $festivity );
                $this->Messages[] = sprintf(
                    /**translators:
                     * 1. Name of the first coinciding Memorial
                     * 2. Name of the second coinciding Memorial
                     * 3. Requested calendar year
                     * 4. Source of the information
                     */
                    _( 'The Memorial \'%1$s\' coincides with another Memorial \'%2$s\' in the year %3$d. They are both reduced in rank to optional memorials.' ),
                    $row->Festivity->name,
                    $coincidingMemorialName,
                    $this->LitSettings->Year
                );
                break;
            case LitGrade::FEAST:
                //there seems to be a coincidence with a different Feast on the same day!
                //what should we do about this? perhaps move one of them?
                $coincidingFestivities = $this->Cal->getCalEventsFromDate( $row->Festivity->DATE );
                $coincidingFeasts = array_filter( $coincidingFestivities, function( $el ) { return $el->grade === LitGrade::FEAST; } );
                $coincidingFeastName = '';
                foreach( $coincidingFeasts as $key => $value ) {
                    //$this->Cal->setProperty( $key, "grade", LitGrade::MEMORIAL_OPT );
                    $coincidingFeastName = $value->name;
                }
                $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . $this->LitSettings->NationalCalendar . ": " . sprintf(
                    /**translators: 1. Festivity name, 2. Festivity date, 3. Coinciding festivity name, 4. Requested calendar year */
                    'The Feast \'%1$s\', usually celebrated on %2$s, coincides with another Feast \'%3$s\' in the year %4$d! Does something need to be done about this?',
                    '<b>' . $row->Festivity->name . '</b>',
                    '<b>' . $this->dayAndMonth->format(  $row->Festivity->DATE->format( 'U' ) ) . '</b>',
                    '<b>' . $coincidingFeastName . '</b>',
                    $this->LitSettings->Year
                );
                break;
            case LitGrade::SOLEMNITY:
                //there seems to be a coincidence with a different Solemnity on the same day!
                //should we attempt to move to the next open slot?
                $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . $this->LitSettings->NationalCalendar . ": " . sprintf(
                    /**translators: 1. Festivity name, 2. Festivity date, 3. Coinciding festivity name, 4. Requested calendar year */
                    'The Solemnity \'%1$s\', usually celebrated on %2$s, coincides with the Sunday or Solemnity \'%3$s\' in the year %4$d! Does something need to be done about this?',
                    '<i>' . $row->Festivity->name . '</i>',
                    '<b>' . $this->dayAndMonth->format(  $row->Festivity->DATE->format( 'U' ) ) . '</b>',
                    '<i>' . $this->Cal->solemnityFromDate(  $row->Festivity->DATE )->name . '</i>',
                    $this->LitSettings->Year
                );
                break;
        }
    }

    private function createNewRegionalOrNationalFestivity( object $row ) : void {
        if(
            property_exists( $row->Festivity, 'strtotime' )
            && $row->Festivity->strtotime !== ''
        ) {
            $festivityDateTS = strtotime( $row->Festivity->strtotime . ' ' . $this->LitSettings->Year . ' UTC' );
            $row->Festivity->DATE = new LitDateTime( "@$festivityDateTS" );
            $row->Festivity->DATE->setTimeZone(new DateTimeZone('UTC'));
        }
        else if(
            property_exists( $row->Festivity, 'month' )
            && $row->Festivity->month >= 1
            && $row->Festivity->month <= 12
            && property_exists( $row->Festivity, 'day' )
            && $row->Festivity->day >= 1
            && $row->Festivity->day <= cal_days_in_month(CAL_GREGORIAN, $row->Festivity->month, $this->LitSettings->Year)
        ) {
            $row->Festivity->DATE = LitDateTime::createFromFormat( '!j-n-Y', "{$row->Festivity->day}-{$row->Festivity->month}-{$this->LitSettings->Year}", new DateTimeZone( 'UTC' ) );
        } else {
            ob_start();
            var_dump($row);
            $a=ob_get_contents();
            ob_end_clean();
            $this->Messages[] = _( 'We should be creating a new festivity, however we do not seem to have the correct date information in order to proceed' ) . ' :: ' . $a;
            return;
        }
        if( $this->festivityCanBeCreated( $row ) ) {
            if( $this->festivityDoesNotCoincide( $row ) ) {
                if( !property_exists( $row->Festivity, 'type' ) || !LitFeastType::isValid( $row->Festivity->type ) ) {
                    $row->Festivity->type = property_exists( $row->Festivity, 'strtotime' ) ? LitFeastType::MOBILE : LitFeastType::FIXED;
                }
                $festivity = new Festivity( $row->Festivity->name, $row->Festivity->DATE, $row->Festivity->color, $row->Festivity->type, $row->Festivity->grade, $row->Festivity->common );
                $this->Cal->addFestivity( $row->Festivity->tag, $festivity );
            } else {
                $this->handleFestivityCreationWithCoincidence( $row );
            }
            $infoSource = 'unknown';
            if( property_exists( $row->Metadata, 'decreeURL' ) ) {
                $infoSource = $this->elaborateDecreeSource( $row );
            }
            else if( property_exists( $row->Metadata, 'missal' ) ) {
                $infoSource = RomanMissal::getName( $row->Metadata->missal );
            }

            $locale = strtoupper( explode('_', $this->LitSettings->Locale)[0] );
            $formattedDateStr = $this->LitSettings->Locale === LitLocale::LATIN ? ( $row->Festivity->DATE->format( 'j' ) . ' ' . LitMessages::LATIN_MONTHS[ (int)$row->Festivity->DATE->format( 'n' ) ] ) :
                ( $locale === 'EN' ? $row->Festivity->DATE->format( 'F jS' ) :
                    $this->dayAndMonth->format( $row->Festivity->DATE->format( 'U' ) )
                );
            $dateStr = property_exists( $row->Festivity, 'strtotime' ) && $row->Festivity->strtotime !== '' ? '<i>' . $row->Festivity->strtotime . '</i>' : $formattedDateStr;
            $this->Messages[] = sprintf(
                /**translators:
                 * 1. Grade or rank of the festivity
                 * 2. Name of the festivity
                 * 3. Day of the festivity
                 * 4. Year from which the festivity has been added
                 * 5. Source of the information
                 * 6. Requested calendar year
                 */
                _( 'The %1$s \'%2$s\' has been added on %3$s since the year %4$d (%5$s), applicable to the year %6$d.' ),
                $this->LitGrade->i18n( $row->Festivity->grade, false ),
                $row->Festivity->name,
                $dateStr,
                $row->Metadata->sinceYear,
                $infoSource,
                $this->LitSettings->Year
            );
        }// else {
            //$this->handleCoincidenceDecree( $row );
        //}
    }
    
    private function handleNationalCalendarRows( array $rows ) : void {
        foreach( $rows as $row ) {
            if( $this->LitSettings->Year >= $row->Metadata->sinceYear ) {
                if( property_exists( $row->Metadata, "untilYear" ) && $this->LitSettings->Year >= $row->Metadata->untilYear ) {
                    continue;
                } else {
                    //if either the property doesn't exist (so no limit is set)
                    //or there is a limit but we are within those limits
                    switch( $row->Metadata->action ) {
                        case "makePatron":
                            $festivity = $this->Cal->getFestivity( $row->Festivity->tag );
                            if( $festivity !== null ) {
                                if( $festivity->grade !== $row->Festivity->grade ) {
                                    $this->Cal->setProperty( $row->Festivity->tag, "grade", $row->Festivity->grade );
                                }
                                $this->Cal->setProperty( $row->Festivity->tag, "name", $row->Festivity->name );
                            } else {
                                $this->handleMissingFestivity( $row );
                            }
                            break;
                        case "createNew":
                            $this->createNewRegionalOrNationalFestivity( $row );
                            break;
                        case "setProperty":
                            switch( $row->Metadata->property ) {
                                case "name":
                                    $this->Cal->setProperty( $row->Festivity->tag, "name", $row->Festivity->name );
                                    break;
                                case "grade":
                                    $this->Cal->setProperty( $row->Festivity->tag, "grade", $row->Festivity->grade );
                                    break;
                            }
                            break;
                        case "moveFestivity":
                            $festivityNewDate = LitDateTime::createFromFormat( '!j-n-Y', $row->Festivity->day.'-'.$row->Festivity->month.'-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
                            $this->moveFestivityDate( $row->Festivity->tag, $festivityNewDate, $row->Metadata->reason, $row->Metadata->missal );
                            break;
                    }
                }
            }
        }
    }

    private function applyNationalCalendar() : void {
        //first thing is apply any wider region festivities, such as Patron Saints of the Wider Region (example: Europe)
        if( $this->WiderRegionData !== null && property_exists( $this->WiderRegionData, "LitCal" ) ) {
            $this->handleNationalCalendarRows( $this->WiderRegionData->LitCal );
        }

        if( $this->NationalData !== null && property_exists( $this->NationalData, "LitCal" ) ) {
            $this->handleNationalCalendarRows( $this->NationalData->LitCal );
        }

        if( $this->NationalData !== null && property_exists( $this->NationalData, "Metadata" ) && property_exists( $this->NationalData->Metadata, "Missals" ) ) {
            if( $this->NationalData->Metadata->Region === 'UNITED STATES' ) {
                $this->NationalData->Metadata->Region = 'USA';
            }
            $this->Messages[] = "Found Missals for region " . $this->NationalData->Metadata->Region. ": " . implode(', ', $this->NationalData->Metadata->Missals);
            foreach( $this->NationalData->Metadata->Missals as $missal ) {
                $yearLimits = RomanMissal::getYearLimits( $missal );
                if( $this->LitSettings->Year >= $yearLimits->sinceYear ) {
                    if( property_exists( $yearLimits, "untilYear" ) && $this->LitSettings->Year >= $yearLimits->untilYear ) {
                        continue;
                    } else {
                        if( RomanMissal::getSanctoraleFileName( $missal ) !== false ) {
                            $this->Messages[] = sprintf(
                                /**translators: Name of the Roman Missal */
                                _( 'Found a sanctorale data file for %s' ),
                                RomanMissal::getName( $missal )
                            );
                            $this->loadPropriumDeSanctisData( $missal );
                            foreach ( $this->tempCal[ $missal ] as $row ) {
                                $currentFeastDate = LitDateTime::createFromFormat( '!j-n-Y', $row->DAY . '-' . $row->MONTH . '-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
                                if( !$this->Cal->inSolemnitiesOrFeasts( $currentFeastDate ) ) {
                                    $festivity = new Festivity( "[ {$this->NationalData->Metadata->Region} ] " . $row->NAME, $currentFeastDate, $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON, $row->DISPLAYGRADE );
                                    $this->Cal->addFestivity( $row->TAG, $festivity );
                                }
                                else{
                                    if( self::DateIsSunday( $currentFeastDate ) && $row->TAG === "PrayerUnborn" ) {
                                        $festivity = new Festivity( "[ USA ] " . $row->NAME, $currentFeastDate->add( new DateInterval( 'P1D' ) ), $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON, $row->DISPLAYGRADE );
                                        $this->Cal->addFestivity( $row->TAG, $festivity );
                                        $this->Messages[] = sprintf(
                                            "USA: The National Day of Prayer for the Unborn is set to Jan 22 as per the 2011 Roman Missal issued by the USCCB, however since it coincides with a Sunday or a Solemnity in the year %d, it has been moved to Jan 23",
                                            $this->LitSettings->Year
                                        );
                                    } else {
                                        $coincidingFestivity = $this->Cal->determineSundaySolemnityOrFeast( $currentFeastDate, $this->LitSettings );
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
                                            $this->NationalData->Metadata->Region . ": " . _( 'The %1$s \'%2$s\' (%3$s), added to the national calendar in the %4$s, is superseded by the %5$s \'%6$s\' in the year %7$d' ),
                                            $row->DISPLAYGRADE !== "" ? $row->DISPLAYGRADE : $this->LitGrade->i18n( $row->GRADE, false ),
                                            '<i>' . $row->NAME . '</i>',
                                            $this->dayAndMonth->format( $currentFeastDate->format( 'U' ) ),
                                            RomanMissal::getName( $missal ),
                                            $coincidingFestivity->grade,
                                            $coincidingFestivity->event->name,
                                            $this->LitSettings->Year
                                        );
                                    }
                                }
                            }
                        } else {
                            $this->Messages[] = sprintf(
                                /**translators: Name of the Roman Missal */
                                _( 'Could not find a sanctorale data file for %s' ),
                                RomanMissal::getName( $missal )
                            );
                        }
                    }
                }
            }
        } else {
            if( $this->NationalData !== null && property_exists( $this->NationalData, 'Metadata' ) && property_exists( $this->NationalData->Metadata, 'Region' ) ) {
                $this->Messages[] = "Did not find any Missals for region " . $this->NationalData->Metadata->Region;
            }
        }
    }

    /**
     * So far, the celebrations being transferred are all originally from the 1970 Editio Typica
     * and they are currently only celebrations from the USA calendar...
     * However the method should work for any calendar
     */
    private function moveFestivityDate( string $tag, DateTime $newDate, string $inFavorOf, $missal ) {
        $festivity = $this->Cal->getFestivity( $tag );
        $newDateStr = $newDate->format('F jS');
        if( !$this->Cal->inSolemnitiesFeastsOrMemorials( $newDate ) ) {
            if( $festivity !== null ) {
                $oldDateStr = $festivity->date->format('F jS');
                $this->Cal->moveFestivityDate( $tag, $newDate );
            }
            else{
                //if it was suppressed on the original date because of a higher ranking celebration,
                //we should recreate it on the new date
                //except in the case of Saint Vincent Deacon when we're dealing with the Roman Missal USA edition,
                //since the National Day of Prayer will take over the new date
                if( $tag !== "StVincentDeacon" || $missal !== RomanMissal::USA_EDITION_2011 ) {
                    $row = $this->tempCal[ RomanMissal::EDITIO_TYPICA_1970 ][ $tag ];
                    $festivity = new Festivity( $row->NAME, $newDate, $row->COLOR, LitFeastType::FIXED, $row->GRADE, $row->COMMON );
                    $this->Cal->addFestivity( $tag, $festivity );
                    $oldDate = LitDateTime::createFromFormat( '!j-n-Y', $row->DAY . '-' . $row->MONTH . '-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
                    $oldDateStr = $oldDate->format('F jS');
                }
            }

            //If the festivity has been successfully recreated, let's make a note about that
            if( $festivity !== null ) {
                $this->Messages[] = sprintf(
                    /**translators: 1. Festivity grade, 2. Festivity name, 3. New festivity name, 4: Requested calendar year, 5. Old date, 6. New date */
                    _( 'The %1$s \'%2$s\' is transferred from %5$s to %6$s as per the %7$s, to make room for \'%3$s\': applicable to the year %4$d.' ),
                    $this->LitGrade->i18n( $festivity->grade ),
                    '<i>' . $festivity->name . '</i>',
                    '<i>' . $inFavorOf . '</i>',
                    $this->LitSettings->Year,
                    $oldDateStr,
                    $newDateStr,
                    RomanMissal::getName( $missal )
                );
                //$this->Cal->setProperty( $tag, "name", "[ USA ] " . $festivity->name );
            }
        }
        else{
            if( $festivity !== null ) {
                $oldDateStr = $festivity->date->format('F jS');
                $coincidingFestivity = $this->Cal->determineSundaySolemnityOrFeast( $newDate );
                //If the new date is already covered by a Solemnity, Feast or Memorial, then we can't move the celebration, so we simply suppress it
                $this->Messages[] = sprintf(
                    /**translators: 1. Festivity grade, 2. Festivity name, 3. Old date, 4. New date, 5. Source of the information, 6. New festivity name, 7. Superseding festivity grade, 8. Superseding festivity name, 9: Requested calendar year */
                    _( 'The %1$s \'%2$s\' would have been transferred from %3$s to %4$s as per the %5$s, to make room for \'%6$s\', however it is suppressed by the %7$s \'%8$s\' in the year %9$d.' ),
                    $this->LitGrade->i18n( $festivity->grade ),
                    '<i>' . $festivity->name . '</i>',
                    $oldDateStr,
                    $newDateStr,
                    RomanMissal::getName( $missal ),
                    '<i>' . $inFavorOf . '</i>',
                    $coincidingFestivity->grade,
                    $coincidingFestivity->event->name,
                    $this->LitSettings->Year
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
        $this->loadPropriumDeSanctisData( RomanMissal::EDITIO_TYPICA_1970 );
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

        if ( $this->LitSettings->Year >= 2002 ) {
            $this->loadPropriumDeSanctisData( RomanMissal::EDITIO_TYPICA_TERTIA_2002 );
            $this->calculateMemorials( LitGrade::MEMORIAL, RomanMissal::EDITIO_TYPICA_TERTIA_2002 );
        }

        if( $this->LitSettings->Year >= 2008 ) {
            $this->loadPropriumDeSanctisData( RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008 );
            $this->calculateMemorials( LitGrade::MEMORIAL, RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008 );
        }

        // :MEMORIALS_FROM_DECREES
        $this->loadMemorialsFromDecreesData();
        $this->applyDecrees( LitGrade::MEMORIAL );

        //11. Proper obligatory memorials, and that is:
        //a ) obligatory memorial of the seconday Patron of a place, of a diocese, of a region or religious province
        //b ) other obligatory memorials in the calendar of a single diocese, order or congregation
        //these will be dealt with later when loading Local Calendar Data

        //12. Optional memorials ( a proper memorial is to be preferred to a general optional memorial ( PC, 23 c ) )
        //  which however can be celebrated even in those days listed at n. 9,
        //  in the special manner described by the General Instructions of the Roman Missal and of the Liturgy of the Hours ( cf pp. 26-27, n. 10 )

        $this->calculateMemorials( LitGrade::MEMORIAL_OPT, RomanMissal::EDITIO_TYPICA_1970 );

        if ( $this->LitSettings->Year >= 2002 ) {
            $this->calculateMemorials( LitGrade::MEMORIAL_OPT, RomanMissal::EDITIO_TYPICA_TERTIA_2002 );
        }

        if ( $this->LitSettings->Year >= 2008 ) {
            $this->calculateMemorials( LitGrade::MEMORIAL_OPT, RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008 );
        }

        $this->applyDecrees( LitGrade::MEMORIAL_OPT );

        //Doctors will often have grade of Memorial, but not always
        //so let's go ahead and just apply these decrees after all memorials and optional memorials have been defined
        //so that we're sure they all exist
        $this->applyDecrees( "DOCTORS" );

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

    private function interpretStrtotime( object $row, string $key ) : LitDateTime|false {
        switch( gettype($row->Metadata->strtotime) ) {
            case 'object':
                if(
                    property_exists( $row->Metadata->strtotime, 'dayOfTheWeek' )
                    && property_exists( $row->Metadata->strtotime, 'relativeTime' )
                    && property_exists( $row->Metadata->strtotime, 'festivityKey' )
                ) {
                    $festivity = $this->Cal->getFestivity( $row->Metadata->strtotime->festivityKey );
                    if( $festivity !== null ) {
                        //$relString = '';
                        $DATE = clone( $festivity->date );
                        switch( $row->Metadata->strtotime->relativeTime ) {
                            case 'before':
                                $DATE->modify("previous {$row->Metadata->strtotime->dayOfTheWeek}");
                                    /**translators: e.g. 'Monday before Palm Sunday' */
                                //$relString = _( 'before' );
                                return $DATE;
                                //break; //unnecessary seeing we are returning immediately
                            case 'after':
                                $DATE->modify("next {$row->Metadata->strtotime->dayOfTheWeek}");
                                /**translators: e.g. 'Monday after Pentecost' */
                                //$relString = _( 'after' );
                                return $DATE;
                                //break; //unnecessary seeing we are returning immediately
                            default:
                                $this->Messages[] = sprintf(
                                    /**translators: 1. Name of the mobile festivity being created, 2. Name of the festivity that it is relative to */
                                    _( 'Cannot create mobile festivity \'%1$s\': can only be relative to festivity with key \'%2$s\' using keywords %3$s' ),
                                    $row->Festivity->name,
                                    $row->Metadata->strtotime->festivityKey,
                                    implode(', ', ['\'before\'', '\'after\''])
                                );
                                return false;
                                //break; //unnecessary seeing we are returning immediately
                        }
                        /*
                        $dayOfTheWeek = $this->LitSettings->Locale === LitLocale::LATIN ? LitMessages::LATIN_DAYOFTHEWEEK[ $DATE->format( 'w' ) ] : ucfirst( $this->dayOfTheWeek->format( $row->Festivity->DATE->format( 'U' ) ) );
                        $row->Metadata->addedWhen = $dayOfTheWeek . ' ' . $relString . ' ' . $festivity->name;
                        if( true === $this->checkCoincidencesNewMobileFestivity( $row ) ) {
                            $this->createMobileFestivity( $row );
                        }
                        */
                    } else {
                        $this->Messages[] = sprintf(
                            /**translators: 1. Name of the mobile festivity being created, 2. Name of the festivity that it is relative to */
                            _( 'Cannot create mobile festivity \'%1$s\' relative to festivity with key \'%2$s\'' ),
                            $row->Festivity->name,
                            $row->Metadata->strtotime->festivityKey
                        );
                        return false;
                    }
                } else {
                    $this->Messages[] = sprintf(
                        /**translators: Do not translate 'strtotime'! 1. Name of the mobile festivity being created 2. list of properties */
                        _( 'Cannot create mobile festivity \'%1$s\': when the \'strtotime\' property is an object, it must have properties %2$s' ),
                        $row->Festivity->name,
                        implode(', ', ['\'dayOfTheWeek\'', '\'relativeTime\'', '\'festivityKey\''])
                    );
                    return false;
                }
                break;
            case 'string':
                if( $row->Metadata->strtotime !== '' ) {
                    if( preg_match('/(before|after)/', $row->Metadata->strtotime) !== false && preg_match('/(before|after)/', $row->Metadata->strtotime) !== 0 ) {
                        $match = preg_split( '/(before|after)/', $row->Metadata->strtotime, -1, PREG_SPLIT_DELIM_CAPTURE );
                        if( $match !== false && count($match) === 3 ) {
                            $festivityDateTS = strtotime( $match[2] . ' ' . $this->LitSettings->Year . ' UTC'  );
                            if( $festivityDateTS !== false ) {
                                $DATE = new LitDateTime( "@$festivityDateTS" );
                                $DATE->setTimeZone(new DateTimeZone('UTC'));
                                if( $match[1] === 'before' ) {
                                    $DATE->modify( "previous {$match[0]}" );
                                } else if ( $match[1] === 'after' ) {
                                    $DATE->modify( "next {$match[0]}" );
                                }
                                return $DATE;
                            } else {
                                $this->Messages[] = sprintf(
                                    /**translators: Do not translate 'strtotime'! */
                                    'Could not interpret the \'strtotime\' property with value %1$s into a timestamp',
                                    $row->Metadata->strtotime
                                );
                                return false;
                            }
                        } else {
                            $this->Messages[] = sprintf(
                                /**translators: Do not translate 'strtotime'! */
                                'Could not interpret the \'strtotime\' property with value %1$s into a timestamp. Splitting failed: %2$s',
                                $row->Metadata->strtotime,
                                json_encode($match)
                            );
                            return false;
                        }
                    } else {
                        $festivityDateTS = strtotime( $row->Metadata->strtotime . ' ' . $this->LitSettings->Year . ' UTC' );
                        if( $festivityDateTS !== false ) {
                            $DATE = new LitDateTime( "@$festivityDateTS" );
                            $DATE->setTimeZone(new DateTimeZone('UTC'));
                            //$row->Metadata->addedWhen = $row->Metadata->strtotime;
                            /*if( true === $this->checkCoincidencesNewMobileFestivity( $row ) ) {
                                $this->createMobileFestivity( $row );
                            }*/
                            return $DATE;
                        } else {
                            $this->Messages[] = sprintf(
                                /**translators: Do not translate 'strtotime'! */
                                'Could not interpret the \'strtotime\' property with value %1$s into a timestamp',
                                $row->Metadata->strtotime
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
                    _( 'Cannot create mobile festivity \'%1$s\': \'strtotime\' property must be either an object or a string! Currently it has type \'%2$s\'' ),
                    $row->Festivity->name,
                    gettype( $row->Metadata->strtotime )
                );
                return false;
        }
    }

    private function applyDiocesanCalendar() {
        foreach( $this->DiocesanData->LitCal as $key => $obj ) {
            //if sinceYear is undefined or null or empty, let's go ahead and create the event in any case
            //creation will be restricted only if explicitly defined by the sinceYear property
            if(
                ($this->LitSettings->Year >= $obj->Metadata->sinceYear || $obj->Metadata->sinceYear === null || $obj->Metadata->sinceYear === 0)
                &&
                (false === property_exists($obj->Metadata, 'untilYear') || $obj->Metadata->untilYear === null || $this->LitSettings->Year <= $obj->Metadata->untilYear || $obj->Metadata->untilYear === 0)
            ) {
                if( property_exists( $obj->Metadata, 'strtotime' ) ) {
                    $currentFeastDate = $this->interpretStrtotime( $obj, $key );
                } else {
                    $currentFeastDate = LitDateTime::createFromFormat( '!j-n-Y', $obj->Festivity->day . '-' . $obj->Festivity->month . '-' . $this->LitSettings->Year, new DateTimeZone( 'UTC' ) );
                }
                if( $currentFeastDate !== false ) {
                    if( $obj->Festivity->grade > LitGrade::FEAST ) {
                        if( $this->Cal->inSolemnities( $currentFeastDate ) && $key != $this->Cal->solemnityKeyFromDate( $currentFeastDate ) ) {
                            //there seems to be a coincidence with a different Solemnity on the same day!
                            //should we attempt to move to the next open slot?
                            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . $this->LitSettings->DiocesanCalendar . ": " .  sprintf(
                                /**translators: 1: Festivity name, 2: Name of the diocese, 3: Festivity date, 4: Coinciding festivity name, 5: Requested calendar year */
                                'The Solemnity \'%1$s\', proper to the calendar of the %2$s and usually celebrated on %3$s, coincides with the Sunday or Solemnity \'%4$s\' in the year %5$d! Does something need to be done about this?',
                                '<i>' . $obj->Festivity->name . '</i>',
                                $this->GeneralIndex->{$this->LitSettings->DiocesanCalendar}->diocese,
                                '<b>' . $this->dayAndMonth->format( $currentFeastDate->format( 'U' ) ) . '</b>',
                                '<i>' . $this->Cal->solemnityFromDate( $currentFeastDate )->name . '</i>',
                                $this->LitSettings->Year
                            );
                        }
                        $this->Cal->addFestivity( $this->LitSettings->DiocesanCalendar . "_" . $key, new Festivity( "[ " . $this->GeneralIndex->{$this->LitSettings->DiocesanCalendar}->diocese . " ] " . $obj->Festivity->name, $currentFeastDate, $obj->Festivity->color, LitFeastType::FIXED, $obj->Festivity->grade, $obj->Festivity->common ) );
                    } else if ( $obj->Festivity->grade <= LitGrade::FEAST && !$this->Cal->inSolemnities( $currentFeastDate ) ) {
                        $this->Cal->addFestivity( $this->LitSettings->DiocesanCalendar . "_" . $key, new Festivity( "[ " . $this->GeneralIndex->{$this->LitSettings->DiocesanCalendar}->diocese . " ] " . $obj->Festivity->name, $currentFeastDate, $obj->Festivity->color, LitFeastType::FIXED, $obj->Festivity->grade, $obj->Festivity->common ) );
                    } else {
                        $this->Messages[] = $this->LitSettings->DiocesanCalendar . ": " . sprintf(
                            /**translators: 1: Festivity grade, 2: Festivity name, 3: Name of the diocese, 4: Festivity date, 5: Coinciding festivity name, 6: Requested calendar year */
                            'The %1$s \'%2$s\', proper to the calendar of the %3$s and usually celebrated on %4$s, is suppressed by the Sunday or Solemnity %5$s in the year %6$d',
                            $this->LitGrade->i18n( $obj->Festivity->grade, false ),
                            '<i>' . $obj->Festivity->name . '</i>',
                            $this->GeneralIndex->{$this->LitSettings->DiocesanCalendar}->diocese,
                            '<b>' . $this->dayAndMonth->format( $currentFeastDate->format( 'U' ) ) . '</b>',
                            '<i>' . $this->Cal->solemnityFromDate( $currentFeastDate )->name . '</i>',
                            $this->LitSettings->Year
                        );
                    }
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
        $ical .= "X-WR-CALNAME:Roman Catholic Universal Liturgical Calendar " . strtoupper( substr( $this->LitSettings->Locale, 0, 2 ) ) . "\r\n";
        $ical .= "X-WR-TIMEZONE:Europe/Vatican\r\n"; //perhaps allow this to be set through a GET or POST?
        $ical .= "X-PUBLISHED-TTL:PT1D\r\n";
        foreach( $SerializeableLitCal->LitCal as $FestivityKey => $CalEvent ){
            $displayGrade = "";
            $displayGradeHTML = "";
            if( $FestivityKey === 'AllSouls' ) {
                $displayGrade = $this->LitGrade->i18n( LitGrade::COMMEMORATION, false );
                $displayGradeHTML = $this->LitGrade->i18n( LitGrade::COMMEMORATION, true );
            }
            else if( $FestivityKey === 'DedicationLateran' ) {
                $displayGrade = $this->LitGrade->i18n( LitGrade::FEAST, false );
                $displayGradeHTML = $this->LitGrade->i18n( LitGrade::FEAST, true );
            }
            else if( (int)$CalEvent->date->format( 'N' ) !==7 ) {
                if( property_exists( $CalEvent,'displayGrade' ) && $CalEvent->displayGrade !== "" ) {
                    $displayGrade = $CalEvent->displayGrade;
                    $displayGradeHTML = '<B>' . $CalEvent->displayGrade . '</B>';
                } else {
                    $displayGrade = $this->LitGrade->i18n( $CalEvent->grade, false );
                    $displayGradeHTML = $this->LitGrade->i18n( $CalEvent->grade, true );
                }
            }
            else if( (int)$CalEvent->grade > LitGrade::MEMORIAL ){
                if( property_exists( $CalEvent,'displayGrade' ) && $CalEvent->displayGrade !== "" ) {
                    $displayGrade = $CalEvent->displayGrade;
                    $displayGradeHTML = '<B>' . $CalEvent->displayGrade . '</B>';
                } else {
                    $displayGrade = $this->LitGrade->i18n( $CalEvent->grade, false );
                    $displayGradeHTML = $this->LitGrade->i18n( $CalEvent->grade, true );
                }
            }

            $description = $this->LitCommon->C( $CalEvent->common );
            $description .=  '\n' . $displayGrade;
            $description .= (is_string($CalEvent->color) && $CalEvent->color != "") || (is_array($CalEvent->color) && count($CalEvent->color) > 0 ) ? '\n' . LitMessages::ParseColorString( $CalEvent->color, $this->LitSettings->Locale, false ) : "";
            $description .= property_exists( $CalEvent,'liturgicalyear' ) && $CalEvent->liturgicalYear !== null && $CalEvent->liturgicalYear != "" ? '\n' . $CalEvent->liturgicalYear : "";
            $htmlDescription = "<P DIR=LTR>" . $this->LitCommon->C( $CalEvent->common );
            $htmlDescription .=  '<BR>' . $displayGradeHTML;
            $htmlDescription .= (is_string($CalEvent->color) && $CalEvent->color != "") || (is_array($CalEvent->color) && count($CalEvent->color) > 0 ) ? "<BR>" . LitMessages::ParseColorString( $CalEvent->color, $this->LitSettings->Locale, true ) : "";
            $htmlDescription .= property_exists( $CalEvent,'liturgicalYear' ) && $CalEvent->liturgicalYear !== null && $CalEvent->liturgicalYear != "" ? '<BR>' . $CalEvent->liturgicalYear . "</P>" : "</P>";
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
            $summaryLang = ";LANGUAGE=" . strtolower( preg_replace('/_/', '-', $this->LitSettings->Locale ) );
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

        $SerializeableLitCal->LitCal                  = $this->Cal->getFestivities();
        $SerializeableLitCal->Messages                = $this->Messages;
        $SerializeableLitCal->Settings->Year          = $this->LitSettings->Year;
        $SerializeableLitCal->Settings->Epiphany      = $this->LitSettings->Epiphany;
        $SerializeableLitCal->Settings->Ascension     = $this->LitSettings->Ascension;
        $SerializeableLitCal->Settings->CorpusChristi = $this->LitSettings->CorpusChristi;
        $SerializeableLitCal->Settings->Locale        = $this->LitSettings->Locale;
        $SerializeableLitCal->Settings->ReturnType    = $this->LitSettings->ReturnType;
        $SerializeableLitCal->Settings->CalendarType  = $this->LitSettings->CalendarType;
        $SerializeableLitCal->Settings->EternalHighPriest = $this->LitSettings->EternalHighPriest;
        if( $this->LitSettings->NationalCalendar !== null ){
            $SerializeableLitCal->Settings->NationalCalendar = $this->LitSettings->NationalCalendar;
        }
        if( $this->LitSettings->DiocesanCalendar !== null ){
            $SerializeableLitCal->Settings->DiocesanCalendar = $this->LitSettings->DiocesanCalendar;
        }

        $SerializeableLitCal->Metadata->VERSION             = self::API_VERSION;
        $SerializeableLitCal->Metadata->Timestamp           = time();
        $SerializeableLitCal->Metadata->DateTime            = date('Y-m-d H:i:s');

        //$SerializeableLitCal->Metadata->RequestHeaders      = $this->APICore->getJsonEncodedRequestHeaders();
        $SerializeableLitCal->Metadata->RequestHeaders      = $this->APICore->getRequestHeaders();
        $SerializeableLitCal->Metadata->Solemnities         = $this->Cal->getSolemnities();
        $SerializeableLitCal->Metadata->FeastsMemorials     = $this->Cal->getFeastsAndMemorials();

        //make sure we have an engineCache folder for the current Version
        if( realpath( "engineCache/v" . str_replace( ".", "_", self::API_VERSION ) ) === false ) {
            mkdir( "engineCache/v" . str_replace( ".", "_", self::API_VERSION ), 0755, true );
        }

        switch ( $this->LitSettings->ReturnType ) {
            case ReturnType::JSON:
                $response = json_encode( $SerializeableLitCal );
                break;
            case ReturnType::XML:
                $jsonStr = json_encode( $SerializeableLitCal );
                $jsonObj = json_decode( $jsonStr, true );
                $xml = new SimpleXMLElement ( "<?xml version=\"1.0\" encoding=\"UTF-8\"?" . "><LiturgicalCalendar xmlns=\"https://www.bibleget.io/catholicliturgy\"/>" );
                LitFunc::convertArray2XML( $jsonObj, $xml );
                $response = $xml->asXML();
                break;
            case ReturnType::ICS:
                $infoObj = $this->getGithubReleaseInfo();
                if( $infoObj->status === "success" ) {
                    $response = $this->produceIcal( $SerializeableLitCal, $infoObj->obj );
                }
                else{
                    die( 'Error receiving or parsing info from github about latest release: '.$infoObj->message );
                }
                break;
            default:
                $response = json_encode( $SerializeableLitCal );
                break;
        }
        file_put_contents( $this->CACHEFILE, $response );
        $responseHash = md5( $response );

        $this->endTime = hrtime(true);
        $executionTime = $this->endTime - $this->startTime;
        header('X-LitCal-Starttime: ' . $this->startTime);
        header('X-LitCal-Endtime: ' . $this->endTime);
        header('X-LitCal-Executiontime: ' . $executionTime);

        header("Etag: \"{$responseHash}\"");
        if (!empty( $_SERVER['HTTP_IF_NONE_MATCH'] ) && $_SERVER['HTTP_IF_NONE_MATCH'] === $responseHash) {
            header( $_SERVER[ "SERVER_PROTOCOL" ] . " 304 Not Modified" );
            header('Content-Length: 0');
            header('X-LitCal-Generated: ClientCache');
        } else {
            header('X-LitCal-Generated: Calculation');
            echo $response;
        }
        die();
    }

    private function prepareL10N() : void {
        $baseLocale = strtolower( explode( '_', $this->LitSettings->Locale )[0] );
        $localeArray = [
            $this->LitSettings->Locale . '.utf8',
            $this->LitSettings->Locale . '.UTF-8',
            $this->LitSettings->Locale,
            $baseLocale . '_' . strtoupper( $baseLocale ) . '.utf8',
            $baseLocale . '_' . strtoupper( $baseLocale ) . '.UTF-8',
            $baseLocale . '_' . strtoupper( $baseLocale ),
            $baseLocale . '.utf8',
            $baseLocale . '.UTF-8',
            $baseLocale
        ];
        $systemLocale = setlocale( LC_ALL, $localeArray );
        $this->createFormatters();
        bindtextdomain("litcal", "i18n");
        textdomain("litcal");
        $this->Cal          = new FestivityCollection( $this->LitSettings );
        $this->LitCommon    = new LitCommon( $this->LitSettings->Locale, $systemLocale );
        $this->LitGrade     = new LitGrade( $this->LitSettings->Locale );
    }

    public function setCacheDuration( string $duration ) : void {
        switch( $duration ) {
            case CacheDuration::DAY:
                $this->CacheDuration = "_" . $duration . date( "z" ); //The day of the year ( starting from 0 through 365 )
                break;
            case CacheDuration::WEEK:
                $this->CacheDuration = "_" . $duration . date( "W" ); //ISO-8601 week number of year, weeks starting on Monday
                break;
            case CacheDuration::MONTH:
                $this->CacheDuration = "_" . $duration . date( "m" ); //Numeric representation of a month, with leading zeros
                break;
            case CacheDuration::YEAR:
                $this->CacheDuration = "_" . $duration . date( "Y" ); //A full numeric representation of a year, 4 digits
                break;
        }
    }

    public function setAllowedReturnTypes( array $returnTypes ) : void {
        $this->AllowedReturnTypes = array_values( array_intersect( ReturnType::$values, $returnTypes ) );
    }

    /**
     * The LitCalEngine will only work once you call the public Init() method
     * Do not change the order of the methods that follow,
     * each one can depend on the one before it in order to function correctly!
     */
    public function Init(){
        $this->APICore->Init();
        $this->initParameterData();
        $this->loadDiocesanCalendarData();
        $this->loadNationalCalendarData();
        $this->updateSettingsBasedOnNationalCalendar();
        $this->updateSettingsBasedOnDiocesanCalendar();
        Festivity::setLocale( $this->LitSettings->Locale );
        $this->APICore->setResponseContentTypeHeader();

        if( $this->cacheFileIsAvailable() ){
            //If we already have done the calculation
            //and stored the results in a cache file
            //then we're done, just output this and die
            //or better, make the client use it's own cache copy!
            $response       = file_get_contents( $this->CACHEFILE );
            $responseHash   = md5( $response );

            $this->endTime  = hrtime(true);
            $executionTime  = $this->endTime - $this->startTime;
            header('X-LitCal-Starttime: ' . $this->startTime);
            header('X-LitCal-Endtime: ' . $this->endTime);
            header('X-LitCal-Executiontime: ' . $executionTime);

            header("Etag: \"{$responseHash}\"");
            if (!empty( $_SERVER['HTTP_IF_NONE_MATCH'] ) && $_SERVER['HTTP_IF_NONE_MATCH'] === $responseHash) {
                header( $_SERVER[ "SERVER_PROTOCOL" ] . " 304 Not Modified" );
                header('Content-Length: 0');
                header('X-LitCal-Generated: ClientCache');
            } else {
                header('X-LitCal-Generated: ServerCache');
                echo $response;
            }
            die();
        } else {
            $this->dieIfBeforeMinYear();
            $this->prepareL10N();
            $this->calculateUniversalCalendar();

            if( $this->LitSettings->NationalCalendar !== null && $this->NationalData !== null ) {
                $this->applyNationalCalendar();
            }

            // :SATURDAY_MEMORIAL_BVM
            $this->calculateSaturdayMemorialBVM();

            if( $this->LitSettings->DiocesanCalendar !== null && $this->DiocesanData !== null ) {
                $this->applyDiocesanCalendar();
            }

            $this->Cal->setCyclesVigilsSeasons();

            if ( $this->LitSettings->CalendarType === CalendarType::LITURGICAL ) {
                // Save the state of the current Calendar calculation
                $this->Cal->sortFestivities();
                $CalBackup = clone( $this->Cal );
                $Messages  = $this->Messages;
                $this->Messages = [];

                // let's calculate the calendar for the previous year
                $this->LitSettings->Year--;
                $this->Cal           = new FestivityCollection( $this->LitSettings );

                $this->calculateUniversalCalendar();

                if( $this->LitSettings->NationalCalendar !== null && $this->NationalData !== null ) {
                    $this->applyNationalCalendar();
                }

                // :SATURDAY_MEMORIAL_BVM
                $this->calculateSaturdayMemorialBVM();

                if( $this->LitSettings->DiocesanCalendar !== null && $this->DiocesanData !== null ) {
                    $this->applyDiocesanCalendar();
                }

                $this->Cal->setCyclesVigilsSeasons();
                $this->Cal->sortFestivities();

                $this->Cal->purgeDataBeforeAdvent();
                $CalBackup->purgeDataAdventChristmas();

                // Now we have to combine the two
                // the backup (which represents the main portion) should be appended to the calendar that was just generated
                $this->Cal->mergeFestivityCollection( $CalBackup );

                // let's reset the year back to the original request before outputting results
                $this->LitSettings->Year++;
                // and append the backed up messages
                array_push( $this->Messages, ...$Messages );
                $this->generateResponse();
            }
            else {
                $this->Cal->sortFestivities();
                $this->generateResponse();
            }
        }
    }

}
