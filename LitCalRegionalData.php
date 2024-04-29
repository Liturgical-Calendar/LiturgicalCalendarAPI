<?php

include_once( 'includes/enums/AcceptHeader.php' );
include_once( 'includes/enums/LitSchema.php' );
include_once( 'includes/enums/RequestMethod.php' );
include_once( 'includes/enums/RequestContentType.php' );
include_once( 'includes/enums/ReturnType.php' );
include_once( 'includes/APICore.php' );
include_once( 'vendor/autoload.php' );

use Swaggest\JsonSchema\Schema;

if( file_exists("allowedOrigins.php") ) {
    include_once( 'allowedOrigins.php' );
}

$allowedOrigins = [
    "https://johnromanodorazio.com",
    "https://www.johnromanodorazio.com",
    "https://litcal.johnromanodorazio.com",
    "https://litcal-staging.johnromanodorazio.com"
];

if( defined('ALLOWED_ORIGINS') && is_array( ALLOWED_ORIGINS ) ) {
    $allowedOrigins = array_merge( $allowedOrigins, ALLOWED_ORIGINS );
}

$LitCalRegionalData = new LitCalRegionalData();

$LitCalRegionalData->APICore->setAllowedOrigins( $allowedOrigins );
$LitCalRegionalData->APICore->setAllowedReferers( array_map( function($el){ return $el . "/"; }, $allowedOrigins ) );

$LitCalRegionalData->APICore->setAllowedAcceptHeaders( [ AcceptHeader::JSON ] );
$LitCalRegionalData->APICore->setAllowedRequestContentTypes( [ RequestContentType::JSON, RequestContentType::FORMDATA ] );
$LitCalRegionalData->Init();

class LitCalRegionalData {

    private object $DATA;
    private object $RESPONSE;
    //The General Index is currently only used for diocesan calendars
    private ?stdClass $GeneralIndex                 = null;
    private array $AllowedRequestMethods = [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ];

    public APICore $APICore;

    public function __construct(){
        $this->APICore                              = new APICore();
        $this->RESPONSE                             = new stdClass();
        $this->RESPONSE->requestHeadersReceived     = $this->APICore->getJsonEncodedRequestHeaders();
    }

    private function handleRequestedMethod() {
        switch( strtoupper( $_SERVER[ "REQUEST_METHOD" ] ) ) {
            case RequestMethod::GET:
                $this->handleGetPostRequests( $_GET );
                break;
            case RequestMethod::POST:
                $this->handleGetPostRequests( $_POST );
                break;
            case RequestMethod::PUT:
            case RequestMethod::PATCH:
                $this->handlePutPatchDeleteRequests( RequestMethod::PUT );
                break;
            case RequestMethod::DELETE:
                $this->handlePutPatchDeleteRequests( RequestMethod::DELETE );
                break;
            case RequestMethod::OPTIONS:
                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                    header("Access-Control-Allow-Methods: " . implode( ', ', $this->AllowedRequestMethods ));
                }
                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
                    header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
                }
                continue;
        default:
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 405 Method Not Allowed", true, 405 );
                $errorMessage = '{"error":"You seem to be forming a strange kind of request? Allowed Request Methods are ';
                $errorMessage .= implode( ' and ', $this->AllowedRequestMethods );
                $errorMessage .= ', but your Request Method was ' . strtoupper( $_SERVER[ 'REQUEST_METHOD' ] ) . '"}';
                die( $errorMessage );
        }
    }

    private function handleGetPostRequests( array $REQUEST ) {

        $this->APICore->validateAcceptHeader( true );
        if( $this->APICore->getRequestContentType() === 'application/json' ) {
            $this->DATA = $this->APICore->retrieveRequestParamsFromJsonBody();
        } else {
            $this->DATA = (object)$REQUEST;
        }
        $this->retrieveRegionalCalendar();
    }

    private function handlePutPatchDeleteRequests( string $requestMethod ) {
        $this->APICore->validateAcceptHeader( false );
        $this->APICore->enforceAjaxRequest();
        $this->APICore->enforceReferer();
        if( $this->APICore->getRequestContentType() === 'application/json' ) {
            $this->DATA = $this->APICore->retrieveRequestParamsFromJsonBody();
            if( RequestMethod::PUT === $requestMethod ) {
                $this->writeRegionalCalendar();
            } elseif( RequestMethod::DELETE === $requestMethod ) {
                $this->deleteRegionalCalendar();
            }
        } else{
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 415 Unsupported Media Type", true, 415 );
            die( '{"error":"You seem to be forming a strange kind of request? Only \'application/json\' is allowed as the Content Type for the body of the Request when using Request Methods PUT, PATCH, or DELETE: the Content Type for the body of your Request was '.$_SERVER[ 'CONTENT_TYPE' ].' and you are using Request Method ' . $_SERVER[ 'REQUEST_METHOD' ] . '"}' );
        }
    }

    private function retrieveRegionalCalendar() {
        if( property_exists( $this->DATA, 'category' ) && property_exists( $this->DATA, 'key' ) ) {
            $category = $this->DATA->category;
            $key = $this->DATA->key;
            switch( $category ) {
                case "diocesanCalendar":
                    $calendarDataFile = $this->GeneralIndex->$key->path;
                    break;
                case "widerRegionCalendar":
                    $calendarDataFile = "nations/{$key}.json";
                    break;
                case "nationalCalendar":
                    $calendarDataFile = "nations/{$key}/{$key}.json";
                    break;
            }

            if( file_exists( $calendarDataFile ) ) {
                if( $category === "diocesanCalendar" ) {
                    echo file_get_contents( $calendarDataFile );
                    die();
                } else {
                    $this->RESPONSE = json_decode( file_get_contents( $calendarDataFile ) );
                    $uKey = strtoupper( $key );
                    if( $category === "widerRegionCalendar" ) {
                        $this->RESPONSE->isMultilingual = is_dir( "nations/{$uKey}" );
                        $locale = strtolower( $this->DATA->locale );
                        if( file_exists( "nations/{$uKey}/{$locale}.json" ) ) {
                            $localeData = json_decode( file_get_contents( "nations/{$uKey}/{$locale}.json" ) );
                            foreach( $this->RESPONSE->LitCal as $idx => $el ) {
                                $this->RESPONSE->LitCal[$idx]->Festivity->name = $localeData->{$this->RESPONSE->LitCal[$idx]->Festivity->tag};
                            }
                        }
                    }
                    echo json_encode( $this->RESPONSE );
                    die();
                }
            } else {
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 404 Not Found", true, 404 );
                echo "{\"message\":\"file $calendarDataFile does not exist\"}";
                die();
            }
        }
    }

    private function writeRegionalCalendar() {
        if( property_exists( $this->DATA, 'LitCal' ) && property_exists( $this->DATA, 'Metadata' ) && property_exists( $this->DATA, 'Settings' ) ) {
            $region = $this->DATA->Metadata->Region;
            if( $region === 'UNITED STATES' ) {
                $region = 'USA';
            }
            $path = "nations/{$region}";
            if( !file_exists( $path ) ) {
                mkdir( $path, 0755, true );
            }

            $test = $this->validateDataAgainstSchema( $this->DATA, LitSchema::NATIONAL );
            if( $test === true ) {
                $data = json_encode( $this->DATA, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE );
                file_put_contents( $path . "/{$region}.json",  $data . PHP_EOL );
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 201 Created", true, 201 );
                die( '{"success":"National calendar created or updated for nation \"'. $this->DATA->Metadata->Region .'\""}' );
            } else {
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 422 Unprocessable Entity", true, 422 );
                die( json_encode( $test ) );
            }

        }
        else if ( property_exists( $this->DATA, 'LitCal' ) && property_exists( $this->DATA, 'Metadata' ) && property_exists( $this->DATA, 'NationalCalendars' ) ) {
            $this->DATA->Metadata->WiderRegion = ucfirst( strtolower( $this->DATA->Metadata->WiderRegion ) );
            $widerRegion = strtoupper( $this->DATA->Metadata->WiderRegion );
            if( $this->DATA->Metadata->IsMultilingual === true ) {
                $path = "nations/{$widerRegion}";
                if( !file_exists( $path ) ) {
                    mkdir( $path, 0755, true );
                }
                $translationJSON = new stdClass();
                foreach( $this->DATA->LitCal as $CalEvent ) {
                    $translationJSON->{ $CalEvent->Festivity->tag } = '';
                }
                if( count( $this->DATA->Metadata->Languages ) > 0 ) {
                    foreach( $this->DATA->Metadata->Languages as $iso ) {
                        if( !file_exists( "nations/{$widerRegion}/{$iso}.json" ) ) {
                            file_put_contents( "nations/{$widerRegion}/{$iso}.json", json_encode( $translationJSON, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE ) );
                        }
                    }
                }
            }

            $test = $this->validateDataAgainstSchema( $this->DATA, LitSchema::WIDERREGION );
            if( $test === true ) {
                $data = json_encode( $this->DATA, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE );
                file_put_contents( "nations/{$this->DATA->Metadata->WiderRegion}.json",  $data . PHP_EOL );
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 201 Created", true, 201 );
                die( '{"success":"Wider region calendar created or updated for region \"'. $this->DATA->Metadata->WiderRegion .'\""}' );
            } else {
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 422 Unprocessable Entity", true, 422 );
                die( json_encode( $test ) );
            }

        }
        else if ( property_exists( $this->DATA, 'LitCal' ) && property_exists( $this->DATA, 'Diocese' ) && property_exists( $this->DATA, 'Nation' ) ) {
            $this->RESPONSE->Nation = strip_tags( $this->DATA->Nation );
            $this->RESPONSE->Diocese = strip_tags( $this->DATA->Diocese );
            $CalData = json_decode( $this->DATA->LitCal );
            if( json_last_error() !== JSON_ERROR_NONE ) {
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad request", true, 400 );
                die( '{"error":"Malformed data received in <LitCal> parameters"}' );
            }
            if( property_exists( $this->DATA, 'Overrides' ) ) {
                $CalData->Overrides = $this->DATA->Overrides;
            }
            $this->RESPONSE->Calendar = json_encode( $CalData, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE );
            if( property_exists( $this->DATA, 'group' ) ) {
                $this->RESPONSE->Group = strip_tags( $this->DATA->group );
            }
            $path = "nations/{$this->RESPONSE->Nation}";
            if( !file_exists( $path ) ){
                mkdir( $path, 0755, true );
            }

            $test = $this->validateDataAgainstSchema( $CalData, LitSchema::DIOCESAN );
            if( $test === true ) {
                file_put_contents( $path . "/{$this->RESPONSE->Diocese}.json", $this->RESPONSE->Calendar . PHP_EOL );
            } else {
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 422 Unprocessable Entity", true, 422 );
                die( json_encode( $test ) );
            }

            $this->createOrUpdateIndex( $path );
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 201 Created", true, 201 );
            die( '{"success":"Diocesan calendar created or updated for diocese \"'. $this->RESPONSE->Diocese .'\""}' );

        }
        else {
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad request", true, 400 );
            die( '{"error":"Not all required parameters were received (LitCal, Metadata, Settings|NationalCalendars OR LitCal, diocese, nation)"}' );
        }
    }

    private function deleteRegionalCalendar() {
        if( !property_exists( $this->DATA, 'LitCal' ) || !property_exists( $this->DATA, 'Diocese' ) || !property_exists( $this->DATA, 'Nation' ) ) {
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad request", true, 400 );
            die( '{"error":"Required parameters were not received"}' );
        } else {
            $this->RESPONSE->Nation = strip_tags( $this->DATA->Nation );
            $this->RESPONSE->Diocese = strip_tags( $this->DATA->Diocese );
            $path = "nations/{$this->RESPONSE->Nation}";
            if( file_exists( $path . "/{$this->RESPONSE->Diocese}.json" ) ){
                unlink($path . "/{$this->RESPONSE->Diocese}.json");
            }

            $this->createOrUpdateIndex( $path, true );
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 200 OK", true, 200 );
            die( '{"success":"Diocesan calendar deleted for nation \"'. $this->RESPONSE->Diocese .'\""}' );

        }
    }

    private function loadIndex() {
        if( file_exists( "nations/index.json" ) ){
            $this->GeneralIndex = json_decode( file_get_contents( "nations/index.json" ) );
        }
    }

    private function createOrUpdateIndex( string $path, bool $delete = false ) {
        if( null === $this->GeneralIndex ){
            $this->GeneralIndex = new stdClass();
        }
        $key = strtoupper(preg_replace("/[^a-zA-Z]/","",$this->RESPONSE->Diocese));

        if( $delete ) {
            if( property_exists( $this->GeneralIndex, $key ) ) {
                unset( $this->GeneralIndex->$key );
            }
        } else {
            if( !property_exists( $this->GeneralIndex, $key ) ){
                $this->GeneralIndex->$key = new stdClass();
            }
            $this->GeneralIndex->$key->path = $path . "/{$this->RESPONSE->Diocese}.json";
            $this->GeneralIndex->$key->nation = $this->RESPONSE->Nation;
            $this->GeneralIndex->$key->diocese = $this->RESPONSE->Diocese;
            if(property_exists($this->RESPONSE,'Group')){
                $this->GeneralIndex->$key->group = $this->RESPONSE->Group;
            }
        }

        $test = $this->validateDataAgainstSchema( $this->GeneralIndex, LitSchema::INDEX );
        if( $test === true ) {
            file_put_contents( "nations/index.json", json_encode( $this->GeneralIndex, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE ) . PHP_EOL );
        } else {
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 422 Unprocessable Entity", true, 422 );
            die( json_encode( $test ) );
        }

    }

    private function validateDataAgainstSchema( object $data, string $schemaUrl ) : bool {
        $result = new stdClass();
        $schema = Schema::import( $schemaUrl );
        try {
            $validation = $schema->in($data);
            return true;
        } catch (Exception $e) {
            $result->error = LitSchema::ERROR_MESSAGES[ $schemaUrl ] . PHP_EOL . $e->getMessage();
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 422 Unprocessable Entity", true, 422 );
            die( json_encode( $result ) );
        }
    }

    public function Init() {
        $this->APICore->Init();
        $this->APICore->setResponseContentTypeHeader();
        $this->loadIndex();
        $this->handleRequestedMethod();
    }

}
