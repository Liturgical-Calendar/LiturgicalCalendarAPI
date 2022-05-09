<?php
error_reporting( E_ALL );
ini_set( 'display_errors', 1 );

include_once( 'includes/enums/AcceptHeader.php' );
include_once( 'includes/enums/RequestMethod.php' );
include_once( 'includes/enums/RequestContentType.php' );
include_once( 'includes/enums/ReturnType.php' );
include_once( 'includes/APICore.php' );

if( file_exists("allowedOrigins.php") ) {
    include_once( 'allowedOrigins.php' );
}

$allowedOrigins = [
    "https://johnromanodorazio.com",
    "https://www.johnromanodorazio.com",
    "https://litcal.johnromanodorazio.com",
    "https://litcal-staging.johnromanodorazio.com",
    "https://litcal.org",
    "https://www.litcal.org"
];

if( defined('ALLOWED_ORIGINS') && is_array( ALLOWED_ORIGINS ) ) {
    $allowedOrigins = array_merge( $allowedOrigins, ALLOWED_ORIGINS );
}

$LitCalNationalData = new LitCalNationalData();

$LitCalNationalData->APICore->setAllowedOrigins( $allowedOrigins );
$LitCalNationalData->APICore->setAllowedReferers( array_map( function($el){ return $el . "/"; }, $allowedOrigins ) );

$LitCalNationalData->APICore->setAllowedAcceptHeaders( [ AcceptHeader::JSON ] );
$LitCalNationalData->APICore->setAllowedRequestContentTypes( [ RequestContentType::JSON, RequestContentType::FORMDATA ] );
$LitCalNationalData->Init();

class LitCalNationalData {

    private object $DATA;
    private object $RESPONSE;

    public APICore $APICore;

    public function __construct(){
        $this->APICore                              = new APICore();
        $this->RESPONSE                             = new stdClass();
        $this->RESPONSE->requestHeadersReceived     = $this->APICore->getJsonEncodedRequestHeaders();
    }

    private function handleGetPostRequests( array $REQUEST ) {
        $this->APICore->validateAcceptHeader( true );
        if( $this->APICore->getRequestContentType() === 'application/json' ) {
            $this->DATA = $this->APICore->retrieveRequestParamsFromJsonBody();
        } else {
            $this->DATA = (object)$REQUEST;
        }
        $this->retrieveNationalCalendar();
    }

    private function handlePutPatchDeleteRequests( string $requestMethod ) {
        $this->APICore->validateAcceptHeader( false );
        $this->APICore->enforceAjaxRequest();
        $this->APICore->enforceReferer();
        if( $this->APICore->getRequestContentType() === 'application/json' ) {
            $this->DATA = $this->APICore->retrieveRequestParamsFromJsonBody();
            if( RequestMethod::PUT === $requestMethod ) {
                $this->writeNationalCalendar();
            } elseif( RequestMethod::DELETE === $requestMethod ) {
                $this->deleteNationalCalendar();
            }
        } else{
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 415 Unsupported Media Type", true, 415 );
            die( '{"error":"You seem to be forming a strange kind of request? Only \'application/json\' is allowed as the Content Type for the body of the Request when using Request Methods PUT, PATCH, or DELETE: the Content Type for the body of your Request was '.$_SERVER[ 'CONTENT_TYPE' ].' and you are using Request Method ' . $_SERVER[ 'REQUEST_METHOD' ] . '"}' );
        }
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
                //continue;
                break;
            default:
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 405 Method Not Allowed", true, 405 );
                $errorMessage = '{"error":"You seem to be forming a strange kind of request? Allowed Request Methods are ';
                $errorMessage .= implode( ' and ', $this->AllowedRequestMethods );
                $errorMessage .= ', but your Request Method was ' . strtoupper( $_SERVER[ 'REQUEST_METHOD' ] ) . '"}';
                die( $errorMessage );
        }
    }

    private function retrieveNationalCalendar() {
        if( property_exists( $this->DATA, 'category' ) ) {
            $category = $this->DATA->category; //nationalCalendar or widerRegionCalendar
            if( property_exists( $this->DATA, 'key' ) ) {
                $key = $this->DATA->key;
                if( $category === "widerRegionCalendar" ) {
                    $calendarDataFile = "nations/{$key}.json";
                }
                else if( $category === "nationalCalendar" ) {
                    $calendarDataFile = "nations/{$key}/{$key}.json";
                }
                if( file_exists( $calendarDataFile ) ) {
                    $response = json_decode( file_get_contents( $calendarDataFile ) );
                    $uKey = strtoupper( $key );
                    if( $category === "widerRegionCalendar" ) {
                        $response->isMultilingual = is_dir( "nations/{$uKey}" );
                        $locale = strtolower( $this->DATA->locale );
                        if( file_exists( "nations/{$uKey}/{$locale}.json" ) ) {
                            $localeData = json_decode( file_get_contents( "nations/{$uKey}/{$locale}.json" ) );
                            foreach( $response->LitCal as $idx => $el ) {
                                $response->LitCal[$idx]->Festivity->name = $localeData->{$response->LitCal[$idx]->Festivity->tag};
                            }
                        }
                    }
                    $responseStr = json_encode( $response );
                    echo $responseStr;
                    die();
                } else {
                    header( $_SERVER[ "SERVER_PROTOCOL" ]." 404 Not Found", true, 404 );
                    echo "{\"message\":\"file $calendarDataFile does not exist\"}";
                    die();
                }
            }
        }
    }

    private function writeNationalCalendar() {
        if( !property_exists( $this->DATA, 'LitCal' ) || !property_exists( $this->DATA, 'Metadata' ) || !property_exists( $this->DATA, 'Settings' ) ) {
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad request", true, 400 );
            die( '{"error":"Not all required parameters were received (LitCal, Metadata, Settings)"}' );
        } else {
            $region = $this->DATA->Metadata->Region;
            if( $region === 'UNITED STATES' ) {
                $region = 'USA';
            }
            $path = "nations/{$region}";
            if( !file_exists( $path ) ){
                mkdir( $path, 0755, true );
            }
            $data = json_encode( $this->DATA, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE );
            file_put_contents( $path . "/{$region}.json",  $data . PHP_EOL );
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 201 Created", true, 201 );
            die( '{"success":"National calendar created or updated for nation \"'. $this->DATA->Metadata->Region .'\""}' );
        }
    }

    private function deleteNationalCalendar() {
        if( !property_exists( $this->DATA, 'calendar' ) || !property_exists( $this->DATA, 'diocese' ) || !property_exists( $this->DATA, 'nation' ) ) {
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad request", true, 400 );
            die( '{"error":"Required parameters were not received"}' );
        } else {
            $this->RESPONSE->Nation = strip_tags( $this->DATA->nation );
            $this->RESPONSE->Diocese = strip_tags( $this->DATA->diocese );
            $path = "nations/{$this->RESPONSE->Nation}";
            if( file_exists( $path . "/{$this->RESPONSE->Diocese}.json" ) ){
                unlink($path . "/{$this->RESPONSE->Diocese}.json");
            }

            //$this->createOrUpdateIndex( $path, true );
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 200 OK", true, 200 );
            die( '{"success":"National calendar deleted for nation \"'. $this->RESPONSE->Diocese .'\""}' );

        }
    }

    public function Init() {
        $this->APICore->Init();
        $this->APICore->setResponseContentTypeHeader();
        $this->handleRequestedMethod();
    }

}
