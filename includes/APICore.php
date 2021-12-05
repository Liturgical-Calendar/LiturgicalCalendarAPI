<?php

class APICore {

    private array $ALLOWED_ORIGINS;
    private array $ALLOWED_REFERERS;
    private array $ALLOWED_ACCEPT_HEADERS;
    private array $ALLOWED_REQUEST_METHODS;
    private array $ALLOWED_REQUEST_CONTENT_TYPES;
    private array $REQUEST_HEADERS;
    private ?string $JSON_ENCODED_REQUEST_HEADERS   = null;
    private ?string $REQUEST_CONTENT_TYPE           = null;
    private ?string $RESPONSE_CONTENT_TYPE          = null;

    public function __construct(){
        $this->ALLOWED_ORIGINS                      = [ "*" ];
        $this->ALLOWED_REFERERS                     = [ "*" ];
        $this->ALLOWED_ACCEPT_HEADERS               = ACCEPT_HEADER::$values;
        $this->ALLOWED_REQUEST_METHODS              = REQUEST_METHOD::$values;
        $this->ALLOWED_REQUEST_CONTENT_TYPES        = REQUEST_CONTENT_TYPE::$values;
        $this->REQUEST_HEADERS                      = getallheaders();
        $this->JSON_ENCODED_REQUEST_HEADERS         = json_encode( $this->REQUEST_HEADERS );
        if( isset( $_SERVER[ 'CONTENT_TYPE' ] ) ) {
            $this->REQUEST_CONTENT_TYPE = $_SERVER[ 'CONTENT_TYPE' ];
        }
    }

    private function setAllowedOriginHeader() {
        if( count( $this->ALLOWED_ORIGINS ) === 1 && $this->ALLOWED_ORIGINS[ 0 ] === "*" ) {
            header( 'Access-Control-Allow-Origin: *' );
        }
        elseif( $this->isAllowedOrigin() ) {
            header( 'Access-Control-Allow-Origin: ' . $this->REQUEST_HEADERS[ "Origin" ] );
        }
        else {
            header( "Access-Control-Allow-Origin: {$_SERVER['HTTP_HOST']}" );
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

    private function sendHeaderNotAcceptable() : void {
        header( $_SERVER[ "SERVER_PROTOCOL" ]." 406 Not Acceptable", true, 406 );
        $errorMessage = '{"error":"You are requesting a content type which this API cannot produce. Allowed Accept headers are ';
        $errorMessage .= implode( ' and ', $this->ALLOWED_ACCEPT_HEADERS );
        $errorMessage .= ', but you have issued an request with an Accept header of ' . $this->REQUEST_HEADERS[ "Accept" ] . '"}';
        die( $errorMessage );
    }

    public function validateAcceptHeader( bool $beLaxAboutIt ) {

        if( $this->hasAcceptHeader() ) {
            if( $this->isAllowedAcceptHeader() ) {
                $this->RESPONSE_CONTENT_TYPE = explode( ',', $this->REQUEST_HEADERS[ "Accept" ] )[0];
            } else {
                if( $beLaxAboutIt ) {
                    //Requests from browser windows using the address bar will probably have an Accept header of text/html
                    //In order to not be too drastic, let's treat text/html as though it were application/json for GET and POST requests only
                    $acceptHeaders = explode( ",", $this->REQUEST_HEADERS[ "Accept" ] );
                    if( in_array( 'text/html', $acceptHeaders ) || in_array( 'text/plain', $acceptHeaders ) || in_array( '*/*', $acceptHeaders ) ) {
                        $this->RESPONSE_CONTENT_TYPE = ACCEPT_HEADER::JSON;
                    } else {
                        $this->sendHeaderNotAcceptable();
                    }
                } else {
                    $this->sendHeaderNotAcceptable();
                }
            }
        } else {
            $this->RESPONSE_CONTENT_TYPE = $this->ALLOWED_ACCEPT_HEADERS[ 0 ];
        }

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

    public function setResponseContentType( string $responseContentType ) : void {
        $this->RESPONSE_CONTENT_TYPE = $responseContentType;
    }

    public function setResponseContentTypeHeader() : void {
        header( "Content-Type: {$this->RESPONSE_CONTENT_TYPE}; charset=utf-8" );
    }

    public function getAllowedAcceptHeaders() : array {
        return $this->ALLOWED_ACCEPT_HEADERS;
    }

    public function getAllowedRequestContentTypes() : array {
        return $this->ALLOWED_REQUEST_CONTENT_TYPES;
    }

    public function getAcceptHeader() : string {
        return $this->REQUEST_HEADERS[ "Accept" ];
    }

    public function getIdxAcceptHeaderInAllowed() : int|string|false {
        return array_search( $this->REQUEST_HEADERS[ "Accept" ], $this->ALLOWED_ACCEPT_HEADERS );
    }

    public function hasAcceptHeader() : bool {
        return isset( $this->REQUEST_HEADERS[ "Accept" ] );
    }

    public function isAjaxRequest() : bool {
        return ( !isset($_SERVER['HTTP_X_REQUESTED_WITH'] ) || empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) || strtolower( $_SERVER['HTTP_X_REQUESTED_WITH'] ) != 'xmlhttprequest' ) === false;
    }

    public function enforceAjaxRequest() : void {
        if( false === $this->isAjaxRequest() ) {
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 418 I'm a teapot", true, 418 );
            $errorMessage = '{"error":"Request was not made via AJAX. When using Request Method ' . strtoupper( $_SERVER[ 'REQUEST_METHOD' ] ) . ', only AJAX requests from authorized Origins and Referers are processable."}';
            die( $errorMessage );
        }
    }

    public function isAllowedAcceptHeader() : bool {
        return in_array( explode( ',', $this->REQUEST_HEADERS[ "Accept" ] )[0], $this->ALLOWED_ACCEPT_HEADERS );
    }

    public function isAllowedOrigin() : bool {
        return isset( $this->REQUEST_HEADERS[ "Origin" ] ) && in_array( $this->REQUEST_HEADERS[ "Origin" ], $this->ALLOWED_ORIGINS );
    }

    public function isAllowedReferer() : bool {
        return in_array( $_SERVER["HTTP_REFERER"], $this->ALLOWED_REFERERS );
    }

    public function getAllowedRequestMethods() : array {
        return $this->ALLOWED_REQUEST_METHODS;
    }

    public function getRequestMethod() : string {
        return strtoupper( $_SERVER[ 'REQUEST_METHOD' ] );
    }

    public function getRequestHeaders() : array {
        return $this->REQUEST_HEADERS;
    }

    public function getJsonEncodedRequestHeaders() : string {
        return $this->JSON_ENCODED_REQUEST_HEADERS;
    }

    public function getRequestContentType() : ?string {
        return $this->REQUEST_CONTENT_TYPE;
    }

    public function getAllowedReferers() : array {
        return $this->ALLOWED_REFERERS;
    }

    public function enforceReferer() : void {
        if( false === $this->isAllowedReferer() ) {
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 401 Unauthorized", true, 401 );
            $errorMessage = '{"error":"Request is coming from unauthorized referer ' . $_SERVER["HTTP_REFERER"] . '. Only AJAX requests from authorized Origins and Referers are processable."}';
            die( $errorMessage );
        }
    }

    public function retrieveRequestParamsFromJsonBody() : object {

        $json = file_get_contents( 'php://input' );
        $data = json_decode( $json );
        if( "" === $json ){
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad Request", true, 400 );
            die( '{"error":"No JSON data received in the request"' );
        } else if ( json_last_error() !== JSON_ERROR_NONE ) {
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad Request", true, 400 );
            die( '{"error":"Malformed JSON data received in the request: <' . $json . '>, ' . json_last_error_msg() . '"}' );
        }
        return $data;

    }

    public function Init() {
        $this->setAllowedOriginHeader();
        $this->setAccessControlAllowMethods();
        $this->validateRequestContentType();
    }


}