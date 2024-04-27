<?php

class APICore {

    private array $AllowedOrigins;
    private array $AllowedReferers;
    private array $AllowedAcceptHeaders;
    private array $AllowedRequestMethods;
    private array $AllowedRequestContentTypes;
    private array $RequestHeaders               = [];
    private ?string $JsonEncodedRequestHeaders  = null;
    private ?string $RequestContentType         = null;
    private ?string $ResponseContentType        = null;

    private static array $onlyUsefulHeaders = [
        "Accept", "Accept-Language", "X-Requested-With", "Host"
    ];

    public function __construct(){
        $this->AllowedOrigins                   = [ "*" ];
        $this->AllowedReferers                  = [ "*" ];
        $this->AllowedAcceptHeaders             = AcceptHeader::$values;
        $this->AllowedRequestMethods            = RequestMethod::$values;
        $this->AllowedRequestContentTypes       = RequestContentType::$values;
        
        foreach( getallheaders() as $header => $value ) {
            if( in_array( $header, self::$onlyUsefulHeaders ) ) {
                $this->RequestHeaders[$header] = $value;
            }
        }
        $this->JsonEncodedRequestHeaders       = json_encode( $this->RequestHeaders );
        if( isset( $_SERVER[ 'CONTENT_TYPE' ] ) ) {
            $this->RequestContentType = $_SERVER[ 'CONTENT_TYPE' ];
        }
    }

    private function setAllowedOriginHeader() {
        if( count( $this->AllowedOrigins ) === 1 && $this->AllowedOrigins[ 0 ] === "*" ) {
            header( 'Access-Control-Allow-Origin: *' );
        }
        elseif( $this->isAllowedOrigin() ) {
            header( 'Access-Control-Allow-Origin: ' . $this->RequestHeaders[ "Origin" ] );
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
                header( "Access-Control-Allow-Methods: " . implode(',', $this->AllowedRequestMethods) );
            if ( isset( $_SERVER[ 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' ] ) )
                header( "Access-Control-Allow-Headers: {$_SERVER[ 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' ]}" );
        }
    }

    private function validateRequestContentType() {
        if( isset( $_SERVER[ 'CONTENT_TYPE' ] ) && $_SERVER[ 'CONTENT_TYPE' ] !== '' && !in_array( explode( ';', $_SERVER[ 'CONTENT_TYPE' ] )[ 0 ], $this->AllowedRequestContentTypes ) ){
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 415 Unsupported Media Type", true, 415 );
            die( '{"error":"You seem to be forming a strange kind of request? Allowed Content Types are '.implode( ' and ', $this->AllowedRequestContentTypes ).', but your Content Type was '.$_SERVER[ 'CONTENT_TYPE' ].'"}' );
        }
    }

    private function sendHeaderNotAcceptable() : void {
        header( $_SERVER[ "SERVER_PROTOCOL" ]." 406 Not Acceptable", true, 406 );
        $errorMessage = '{"error":"You are requesting a content type which this API cannot produce. Allowed Accept headers are ';
        $errorMessage .= implode( ' and ', $this->AllowedAcceptHeaders );
        $errorMessage .= ', but you have issued an request with an Accept header of ' . $this->RequestHeaders[ "Accept" ] . '"}';
        die( $errorMessage );
    }

    public function validateAcceptHeader( bool $beLaxAboutIt ) {

        if( $this->hasAcceptHeader() ) {
            if( $this->isAllowedAcceptHeader() ) {
                $this->ResponseContentType = explode( ',', $this->RequestHeaders[ "Accept" ] )[0];
            } else {
                if( $beLaxAboutIt ) {
                    //Requests from browser windows using the address bar will probably have an Accept header of text/html
                    //In order to not be too drastic, let's treat text/html as though it were application/json for GET and POST requests only
                    $acceptHeaders = explode( ",", $this->RequestHeaders[ "Accept" ] );
                    if( in_array( 'text/html', $acceptHeaders ) || in_array( 'text/plain', $acceptHeaders ) || in_array( '*/*', $acceptHeaders ) ) {
                        $this->ResponseContentType = AcceptHeader::JSON;
                    } else {
                        $this->sendHeaderNotAcceptable();
                    }
                } else {
                    $this->sendHeaderNotAcceptable();
                }
            }
        } else {
            $this->ResponseContentType = $this->AllowedAcceptHeaders[ 0 ];
        }

    }

    public function setAllowedOrigins( array $origins ) : void {
        $this->AllowedOrigins = $origins;
    }

    public function setAllowedReferers( array $referers ) : void {
        $this->AllowedReferers = $referers;
    }

    public function setAllowedAcceptHeaders( array $acceptHeaders ) : void {
        $this->AllowedAcceptHeaders = array_values( array_intersect( AcceptHeader::$values, $acceptHeaders ) );
    }

    public function setAllowedRequestMethods( array $requestMethods ) : void {
        $this->AllowedRequestMethods = array_values( array_intersect( RequestMethod::$values, $requestMethods ) );
    }

    public function setAllowedRequestContentTypes( array $requestContentTypes ) : void {
        $this->AllowedRequestContentTypes = array_values( array_intersect( RequestContentType::$values, $requestContentTypes ) );
    }

    public function setResponseContentType( string $responseContentType ) : void {
        $this->ResponseContentType = $responseContentType;
    }

    public function setResponseContentTypeHeader() : void {
        header( 'Cache-Control: must-revalidate, max-age=259200' ); //cache for 1 month
        header( "Content-Type: {$this->ResponseContentType}; charset=utf-8" );
    }

    public function getAllowedAcceptHeaders() : array {
        return $this->AllowedAcceptHeaders;
    }

    public function getAllowedRequestContentTypes() : array {
        return $this->AllowedRequestContentTypes;
    }

    public function getAcceptHeader() : string {
        return $this->RequestHeaders[ "Accept" ];
    }

    public function getIdxAcceptHeaderInAllowed() : int|string|false {
        return array_search( $this->RequestHeaders[ "Accept" ], $this->AllowedAcceptHeaders );
    }

    public function hasAcceptHeader() : bool {
        return isset( $this->RequestHeaders[ "Accept" ] );
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
        return in_array( explode( ',', $this->RequestHeaders[ "Accept" ] )[0], $this->AllowedAcceptHeaders );
    }

    public function isAllowedOrigin() : bool {
        return isset( $this->RequestHeaders[ "Origin" ] ) && in_array( $this->RequestHeaders[ "Origin" ], $this->AllowedOrigins );
    }

    public function isAllowedReferer() : bool {
        return in_array( $_SERVER["HTTP_REFERER"], $this->AllowedReferers );
    }

    public function getAllowedRequestMethods() : array {
        return $this->AllowedRequestMethods;
    }

    public function getRequestMethod() : string {
        return strtoupper( $_SERVER[ 'REQUEST_METHOD' ] );
    }

    public function getRequestHeaders() : array {
        return $this->RequestHeaders;
    }

    public function getJsonEncodedRequestHeaders() : string {
        return $this->JsonEncodedRequestHeaders;
    }

    public function getRequestContentType() : ?string {
        return $this->RequestContentType;
    }

    public function getAllowedReferers() : array {
        return $this->AllowedReferers;
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
