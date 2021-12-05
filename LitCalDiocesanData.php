<?php
error_reporting( E_ALL );
ini_set( 'display_errors', 1 );

include_once( 'includes/enums/AcceptHeader.php' );
include_once( 'includes/enums/RequestMethod.php' );
include_once( 'includes/enums/RequestContentType.php' );
include_once( 'includes/enums/ReturnType.php' );

$allowedOrigins = [
    "https://johnromanodorazio.com",
    "https://www.johnromanodorazio.com",
    "https://litcal.johnromanodorazio.com",
    "https://litcal-staging.johnromanodorazio.com"
];

$LitCalDiocesanData = new LitCalDiocesanData();

$LitCalDiocesanData->setAllowedOrigins( $allowedOrigins );
$LitCalDiocesanData->setAllowedReferers( array_map( function($el){ return $el . "/"; }, $allowedOrigins ) );

$LitCalDiocesanData->setAllowedAcceptHeaders( [ ACCEPT_HEADER::JSON ] );
$LitCalDiocesanData->setAllowedRequestContentTypes( [ REQUEST_CONTENT_TYPE::JSON, REQUEST_CONTENT_TYPE::FORMDATA ] );
$LitCalDiocesanData->Init();

class LitCalDiocesanData {

    private array $ALLOWED_ORIGINS;
    private array $ALLOWED_REFERERS;
    private array $ALLOWED_ACCEPT_HEADERS;
    private array $ALLOWED_REQUEST_METHODS;
    private array $ALLOWED_REQUEST_CONTENT_TYPES;
    private array $REQUEST_HEADERS;
    private object $DATA;
    private object $RESPONSE;
    private ?stdClass $GeneralIndex                 = null;

    private string $jsonEncodedRequestHeaders       = "";
    private string $responseContentType             = ACCEPT_HEADER::JSON;

    public function __construct(){
        $this->ALLOWED_ORIGINS                      = [ "*" ];
        $this->ALLOWED_REFERERS                     = [ "*" ];
        $this->ALLOWED_ACCEPT_HEADERS               = ACCEPT_HEADER::$values;
        $this->ALLOWED_RETURN_TYPES                 = RETURN_TYPE::$values;
        $this->ALLOWED_REQUEST_METHODS              = REQUEST_METHOD::$values;
        $this->ALLOWED_REQUEST_CONTENT_TYPES        = REQUEST_CONTENT_TYPE::$values;
        $this->REQUEST_HEADERS                      = getallheaders();
        $this->jsonEncodedRequestHeaders            = json_encode( $this->REQUEST_HEADERS );
        $this->RESPONSE                             = new stdClass();
        $this->RESPONSE->requestHeadersReceived     = $this->jsonEncodedRequestHeaders;
    }

    private function setAllowedOriginHeader() {
        if( count( $this->ALLOWED_ORIGINS ) === 1 && $this->ALLOWED_ORIGINS[ 0 ] === "*" ) {
            header( 'Access-Control-Allow-Origin: *' );
        }
        elseif( isset( $this->REQUEST_HEADERS[ "Origin" ] ) && in_array( $this->REQUEST_HEADERS[ "Origin" ], $this->ALLOWED_ORIGINS ) ) {
            header( 'Access-Control-Allow-Origin: ' . $this->REQUEST_HEADERS[ "Origin" ] );
        }
        else {
            header( 'Access-Control-Allow-Origin: https://www.vatican.va' );
        }
        header( 'Access-Control-Allow-Credentials: true' );
        header( 'Access-Control-Max-Age: 86400' );    // cache for 1 day
    }

    private function setAccessControlAllowMethods() {
        if ( isset( $_SERVER[ 'REQUEST_METHOD' ] ) ) {
            if ( isset( $_SERVER[ 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' ] ) )
                header( "Access-Control-Allow-Methods: " . implode(',', $this->ALLOWED_REQUEST_METHODS) );
            if ( isset( $_SERVER[ 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' ] ) )
                header( "Access-Control-Allow-Headers: {$_SERVER[ 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' ]}" );
        }
    }

    private function validateRequestContentType() {
        if( isset( $_SERVER[ 'CONTENT_TYPE' ] ) && $_SERVER[ 'CONTENT_TYPE' ] !== '' && !in_array( explode( ';', $_SERVER[ 'CONTENT_TYPE' ] )[ 0 ], $this->ALLOWED_REQUEST_CONTENT_TYPES ) ){
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 415 Unsupported Media Type", true, 415 );
            die( '{"error":"You seem to be forming a strange kind of request? Allowed Content Types are '.implode( ' and ', $this->ALLOWED_REQUEST_CONTENT_TYPES ).', but your Content Type was '.$_SERVER[ 'CONTENT_TYPE' ].'"}' );
        }
    }

    private function validateAcceptHeader( bool $beLaxAboutIt ) {
        if( isset( $this->REQUEST_HEADERS[ "Accept" ] ) ) {
            if( in_array( explode( ',', $this->REQUEST_HEADERS[ "Accept" ] )[0], $this->ALLOWED_ACCEPT_HEADERS ) ) {
                $this->responseContentType = $this->REQUEST_HEADERS[ "Accept" ];
            } else {
                if( $beLaxAboutIt ) {
                    //Requests from browser windows using the address bar will probably have an Accept header of text/html
                    //In order to not be too drastic, let's treat text/html as though it were application/json for GET and POST requests only
                    $acceptHeaders = explode( ",", $this->REQUEST_HEADERS[ "Accept" ] );
                    if( in_array( 'text/html', $acceptHeaders ) || in_array( 'text/plain', $acceptHeaders ) || in_array( '*/*', $acceptHeaders ) ) {
                        $this->responseContentType = ACCEPT_HEADER::JSON;
                    } else {
                        header( $_SERVER[ "SERVER_PROTOCOL" ]." 406 Not Acceptable", true, 406 );
                        $errorMessage = '{"error":"You are requesting a content type which this API cannot produce. Allowed Accept headers are ';
                        $errorMessage .= implode( ' and ', $this->ALLOWED_ACCEPT_HEADERS );
                        $errorMessage .= ', but you have issued an request with an Accept header of ' . $this->REQUEST_HEADERS[ "Accept" ] . '"}';
                        die( $errorMessage );
                    }
                } else {
                    header( $_SERVER[ "SERVER_PROTOCOL" ]." 406 Not Acceptable", true, 406 );
                    $errorMessage = '{"error":"You are requesting a content type which this API cannot produce. Allowed Accept headers are ';
                    $errorMessage .= implode( ' and ', $this->ALLOWED_ACCEPT_HEADERS );
                    $errorMessage .= ', but you have issued an request with an Accept header of ' . $this->REQUEST_HEADERS[ "Accept" ] . '"}';
                    die( $errorMessage );
                }
            }
        } else {
            $this->responseContentType = $this->ALLOWED_ACCEPT_HEADERS[ 0 ];
        }
    }

    private function validateReferer() {
        return in_array( $_SERVER["HTTP_REFERER"], $this->ALLOWED_REFERERS );
    }

    private function retrieveRequestParamsFromJsonBody() : void {

        $json = file_get_contents( 'php://input' );
        $data = json_decode( $json );
        if( "" === $json ){
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad Request", true, 400 );
            die( '{"error":"No JSON data received in the request"' );
        } else if ( json_last_error() !== JSON_ERROR_NONE ) {
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad Request", true, 400 );
            die( '{"error":"Malformed JSON data received in the request: <' . $json . '>, ' . json_last_error_msg() . '"}' );
        } else {
            $this->DATA = $data;
        }

    }

    private static function requestIsAjax() : bool {
        return (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest' ) === false;
    }

    private function requestContentTypeIsJson() : bool {
        return ( isset( $_SERVER[ 'CONTENT_TYPE' ] ) && $_SERVER[ 'CONTENT_TYPE' ] === 'application/json' );
    }

    public function setAllowedOrigins( array $origins ) : void {
        $this->ALLOWED_ORIGINS = $origins;
    }

    public function setAllowedReferers( array $referers ) : void {
        $this->ALLOWED_REFERERS = $referers;
    }

    public function setAllowedAcceptHeaders( array $acceptHeaders ) : void {
        $this->ALLOWED_ACCEPT_HEADERS = array_values( array_intersect( ACCEPT_HEADER::$values, $acceptHeaders ) );
    }

    public function setAllowedRequestMethods( array $requestMethods ) : void {
        $this->ALLOWED_REQUEST_METHODS = array_values( array_intersect( REQUEST_METHOD::$values, $requestMethods ) );
    }

    public function setAllowedRequestContentTypes( array $requestContentTypes ) : void {
        $this->ALLOWED_REQUEST_CONTENT_TYPES = array_values( array_intersect( REQUEST_CONTENT_TYPE::$values, $requestContentTypes ) );
    }

    private function handleRequestedMethod() {

        switch( strtoupper( $_SERVER[ "REQUEST_METHOD" ] ) ) {
            case 'GET':
                $this->validateAcceptHeader( true );
                if( $this->requestContentTypeIsJson() ) {
                    $this->retrieveRequestParamsFromJsonBody();
                } else {
                    $this->DATA = (object)$_GET;
                }
                $this->retrieveDiocesanCalendar();
                break;
            case 'POST':
                $this->validateAcceptHeader( true );
                if( $this->requestContentTypeIsJson() ) {
                    $this->retrieveRequestParamsFromJsonBody();
                } else{
                    $this->DATA = (object)$_POST;
                }
                $this->retrieveDiocesanCalendar();
                break;
            case 'PUT':
            case 'PATCH':
                $this->validateAcceptHeader( false );
                if( false === self::requestIsAjax() ) {
                    header( $_SERVER[ "SERVER_PROTOCOL" ]." 418 I'm a teapot", true, 418 );
                    $errorMessage = '{"error":"Request was not made via AJAX. When using Request Method ' . strtoupper( $_SERVER[ 'REQUEST_METHOD' ] ) . ', only AJAX requests from authorized Origins and Referers are processable."}';
                    die( $errorMessage );
                }
                if( false === $this->validateReferer() ) {
                    header( $_SERVER[ "SERVER_PROTOCOL" ]." 401 Unauthorized", true, 401 );
                    $errorMessage = '{"error":"Request is coming from unauthorized referer ' . $_SERVER["HTTP_REFERER"] . '. Only AJAX requests from authorized Origins and Referers are processable."}';
                    die( $errorMessage );
                }
                if( $this->requestContentTypeIsJson() ) {
                    $this->retrieveRequestParamsFromJsonBody();
                    $this->writeDiocesanCalendar();
                } else{
                    header( $_SERVER[ "SERVER_PROTOCOL" ]." 415 Unsupported Media Type", true, 415 );
                    die( '{"error":"You seem to be forming a strange kind of request? Allowed Content Types are '.implode( ' and ', $this->ALLOWED_REQUEST_CONTENT_TYPES ).', but your Content Type was '.$_SERVER[ 'CONTENT_TYPE' ].'"}' );
                }
                break;
            case 'DELETE':
                $this->validateAcceptHeader( false );
                if( false === self::requestIsAjax() ) {
                    header( $_SERVER[ "SERVER_PROTOCOL" ]." 418 I'm a teapot", true, 418 );
                    $errorMessage = '{"error":"Request was not made via AJAX. When using Request Method ' . strtoupper( $_SERVER[ 'REQUEST_METHOD' ] ) . ', only AJAX requests from authorized Origins and Referers are processable."}';
                    die( $errorMessage );
                }
                if( false === $this->validateReferer() ) {
                    header( $_SERVER[ "SERVER_PROTOCOL" ]." 401 Unauthorized", true, 401 );
                    $errorMessage = '{"error":"Request is coming from unauthorized referer ' . $_SERVER["HTTP_REFERER"] . '. Only AJAX requests from authorized Origins and Referers are processable."}';
                    die( $errorMessage );
                }
                if( $this->requestContentTypeIsJson() ) {
                    $this->retrieveRequestParamsFromJsonBody();
                    $this->deleteDiocesanCalendar();
                } else{
                    header( $_SERVER[ "SERVER_PROTOCOL" ]." 415 Unsupported Media Type", true, 415 );
                    die( '{"error":"You seem to be forming a strange kind of request? Allowed Content Types are '.implode( ' and ', $this->ALLOWED_REQUEST_CONTENT_TYPES ).', but your Content Type was '.$_SERVER[ 'CONTENT_TYPE' ].'"}' );
                }
                break;
            case 'OPTIONS':
                //continue;
                break;
            default:
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 405 Method Not Allowed", true, 405 );
                $errorMessage = '{"error":"You seem to be forming a strange kind of request? Allowed Request Methods are ';
                $errorMessage .= implode( ' and ', $this->ALLOWED_REQUEST_METHODS );
                $errorMessage .= ', but your Request Method was ' . strtoupper( $_SERVER[ 'REQUEST_METHOD' ] ) . '"}';
                die( $errorMessage );
        }
    }

    private function setReponseContentTypeHeader() {
        header( "Content-Type: {$this->responseContentType}; charset=utf-8" );
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

        file_put_contents( "nations/index.json", json_encode( $this->GeneralIndex ) . PHP_EOL );
    }

    private function retrieveDiocesanCalendar() {
        if( property_exists( $this->DATA, 'key' ) ) {
            $key = $this->DATA->key;
            $calendarPath = $this->GeneralIndex->$key->path;
            if( file_exists( $calendarPath ) ) {
                echo file_get_contents( $calendarPath );
                die();
            }
        }
    }

    private function writeDiocesanCalendar() {
        if( !property_exists( $this->DATA, 'calendar' ) || !property_exists( $this->DATA, 'diocese' ) || !property_exists( $this->DATA, 'nation' ) ) {
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad request", true, 400 );
            die( '{"error":"Required parameters were not received"}' );
        } else {
            $this->RESPONSE->Nation = strip_tags( $this->DATA->nation );
            $this->RESPONSE->Diocese = strip_tags( $this->DATA->diocese );
            $CalData = json_decode( $this->DATA->calendar );
            if( json_last_error() !== JSON_ERROR_NONE ) {
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad request", true, 400 );
                die( '{"error":"Malformed data received in <calendar> parameters"}' );
            }
            $this->RESPONSE->Calendar = json_encode( $CalData );
            if( property_exists( $this->DATA, 'group' ) ) {
                $this->RESPONSE->Group = strip_tags( $this->DATA->group );
            }
            $path = "nations/{$this->RESPONSE->Nation}";
            if( !file_exists( $path ) ){
                mkdir( $path, 0755, true );
            }

            file_put_contents( $path . "/{$this->RESPONSE->Diocese}.json", $this->RESPONSE->Calendar . PHP_EOL );

            $this->createOrUpdateIndex( $path );
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 201 Created", true, 201 );
            die( '{"success":"Diocesan calendar created or updated for diocese \"'. $this->RESPONSE->Diocese .'\""}' );

        }
    }

    private function deleteDiocesanCalendar() {
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

            $this->createOrUpdateIndex( $path, true );
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 200 OK", true, 200 );
            die( '{"success":"Diocesan calendar deleted for diocese \"'. $this->RESPONSE->Diocese .'\""}' );

        }
    }

    /**
    if( property_exists( $this->GeneralIndex, $this->LITSETTINGS->DIOCESAN ) ){
        $diocesanDataFile = $this->GeneralIndex->{$this->LITSETTINGS->DIOCESAN}->path;
        $this->LITSETTINGS->NATIONAL = $this->GeneralIndex->{$this->LITSETTINGS->DIOCESAN}->nation;
        if( file_exists( $diocesanDataFile ) ){
            $this->DiocesanData = json_decode( file_get_contents( $diocesanDataFile ) );
        }
    }
     */
    public function Init() {

        $this->setAllowedOriginHeader();
        $this->setAccessControlAllowMethods();
        $this->validateRequestContentType();
        $this->setReponseContentTypeHeader();
        $this->loadIndex();
        $this->handleRequestedMethod();

    }

}