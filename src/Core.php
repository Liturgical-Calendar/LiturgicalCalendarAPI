<?php

namespace LiturgicalCalendar\Api;

use LiturgicalCalendar\Api\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\RequestContentType;

/**
 * Class Core
 *
 * This class serves as the central component of the Liturgical Calendar API.
 * It manages the initialization of allowed request parameters and headers,
 * processes incoming requests, and sets response content types.
 *
 * @package LiturgicalCalendar\Api
 */
class Core
{
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
        "Accept", "Accept-Language", "X-Requested-With", "Origin"
    ];

    /**
     * Initializes the Core object with default values for AllowedOrigins, AllowedReferers, AllowedAcceptHeaders,
     * AllowedRequestMethods, and AllowedRequestContentTypes.
     * Parses the useful headers from the request and stores them in RequestHeaders.
     * Encodes the RequestHeaders to JSON and stores them in JsonEncodedRequestHeaders.
     * Sets the RequestContentType based on the $_SERVER['CONTENT_TYPE'] value.
     */
    public function __construct()
    {
        $this->AllowedOrigins                   = [ "*" ];
        $this->AllowedReferers                  = [ "*" ];
        $this->AllowedAcceptHeaders             = AcceptHeader::$values;
        $this->AllowedRequestMethods            = RequestMethod::$values;
        $this->AllowedRequestContentTypes       = RequestContentType::$values;

        foreach (getallheaders() as $header => $value) {
            if (in_array($header, self::$onlyUsefulHeaders)) {
                $this->RequestHeaders[$header] = $value;
            }
        }
        $this->JsonEncodedRequestHeaders       = json_encode($this->RequestHeaders);
        if (isset($_SERVER[ 'CONTENT_TYPE' ])) {
            $this->RequestContentType = $_SERVER[ 'CONTENT_TYPE' ];
        }
    }

    /**
     * Sets the Access-Control-Allow-Origin header based on the allowed origins.
     *
     * If the only allowed origin is '*', the header is set to allow all origins.
     * If the request origin is in the list of allowed origins, the header is set
     * to allow that specific origin. Otherwise, the header is set to allow only
     * the server's domain. Additionally, this method sets the
     * Access-Control-Allow-Credentials header to true and caches the result for
     * one day by setting the Access-Control-Max-Age header to 86400 seconds.
     */
    private function setAllowedOriginHeader()
    {
        if (count($this->AllowedOrigins) === 1 && $this->AllowedOrigins[ 0 ] === "*") {
            header('Access-Control-Allow-Origin: *');
        } elseif ($this->isAllowedOrigin()) {
            header('Access-Control-Allow-Origin: ' . $this->RequestHeaders[ "Origin" ]);
        } else {
            header("Access-Control-Allow-Origin: {$_SERVER['SERVER_NAME']}");
        }
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');    // cache for 1 day
    }

    /**
     * Handles CORS preflight OPTIONS requests and sets the
     * Access-Control-Allow-Methods and Access-Control-Allow-Headers headers
     * if the request method is OPTIONS and the request has the
     * Access-Control-Request-Method and Access-Control-Request-Headers headers
     */
    private function setAccessControlAllowMethods()
    {
        if (isset($_SERVER[ 'REQUEST_METHOD' ])) {
            if (isset($_SERVER[ 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' ])) {
                header("Access-Control-Allow-Methods: " . implode(',', $this->AllowedRequestMethods));
            }
            if (isset($_SERVER[ 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' ])) {
                header("Access-Control-Allow-Headers: {$_SERVER[ 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' ]}");
            }
        }
    }

    /**
     * If the request's Content-Type header is not empty and is not in the list of
     * allowed content types, then it will send a 415 error response with a JSON
     * body with the following structure:
     * {
     *     "error": string
     * }
     * Where the value of "error" will be "You seem to be forming a strange kind of request? Allowed Content Types are [comma separated list of allowed content types], but your Content Type was [Content Type of the request]"
     */
    private function validateRequestContentType()
    {
        if (
            isset($_SERVER[ 'CONTENT_TYPE' ])
            && $_SERVER[ 'CONTENT_TYPE' ] !== ''
            && !in_array(
                explode(';', $_SERVER[ 'CONTENT_TYPE' ])[ 0 ],
                $this->AllowedRequestContentTypes
            )
        ) {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 415 Unsupported Media Type", true, 415);
            $response = new \stdClass();
            $response->error = "You seem to be forming a strange kind of request? Allowed Content Types are "
            . implode(' and ', $this->AllowedRequestContentTypes)
            . ', but your Content Type was '
            . $_SERVER[ 'CONTENT_TYPE' ];
            die(json_encode($response));
        }
    }

    /**
     * Sends a 406 Not Acceptable header and dies with a json encoded error message.
     * The error message is constructed from the list of allowed accept headers and the
     * value of the Accept header in the request.
     */
    private function sendHeaderNotAcceptable(): void
    {
        header($_SERVER[ "SERVER_PROTOCOL" ] . " 406 Not Acceptable", true, 406);
        $response = new \stdClass();
        $response->error = "You are requesting a content type which this API cannot produce. Allowed Accept headers are "
            . implode(' and ', $this->AllowedAcceptHeaders)
            . ', but you have issued an request with an Accept header of '
            . $this->RequestHeaders[ "Accept" ];
        die(json_encode($response));
    }

    /**
     * Checks whether the request has an Accept header and whether the value of that header is
     * in the list of content types that the API can produce. If the request did not provide an
     * Accept header, it will use the first value in the list of content types that the API can
     * produce. If the request did provide an Accept header, and the value of that header is not
     * in the list of content types that the API can produce, it will return a 406 Not Acceptable
     * error. If the request did provide an Accept header, and the value of that header is in the
     * list of content types that the API can produce, it will use that.
     *
     * If $beLaxAboutIt is true, it will treat requests with an Accept header of text/html as
     * though it were application/json for GET and POST requests only. This is because requests
     * from browser windows using the address bar will probably have an Accept header of text/html.
     * In order to not be too drastic, let's treat text/html as though it were application/json
     * for GET and POST requests only.
     *
     * @param bool $beLaxAboutIt
     */
    public function validateAcceptHeader(bool $beLaxAboutIt)
    {
        if ($this->hasAcceptHeader()) {
            if ($this->isAllowedAcceptHeader()) {
                $this->ResponseContentType = explode(',', $this->RequestHeaders[ "Accept" ])[0];
            } else {
                if ($beLaxAboutIt) {
                    //Requests from browser windows using the address bar will probably have an Accept header of text/html
                    //In order to not be too drastic, let's treat text/html as though it were application/json for GET and POST requests only
                    $acceptHeaders = explode(",", $this->RequestHeaders[ "Accept" ]);
                    if (in_array('text/html', $acceptHeaders) || in_array('text/plain', $acceptHeaders) || in_array('*/*', $acceptHeaders)) {
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

    /**
     * Sets the allowed origins for Cross-Origin Resource Sharing (CORS).
     *
     * This function updates the list of origins that are permitted to access
     * resources on the server. The allowed origins are used to determine which
     * Origin headers are permitted in CORS requests.
     *
     * @param array $origins An array of allowed origin URLs.
     */
    public function setAllowedOrigins(array $origins): void
    {
        $this->AllowedOrigins = $origins;
    }

    /**
     * Sets the allowed referers for Cross-Origin Resource Sharing (CORS).
     *
     * This function updates the list of referers that are permitted to access
     * resources on the server. The allowed referers are used to determine which
     * Referer headers are permitted in CORS requests.
     *
     * @param array $referers An array of allowed referer URLs.
     */
    public function setAllowedReferers(array $referers): void
    {
        $this->AllowedReferers = $referers;
    }

    /**
     * Sets the allowed accept headers for Cross-Origin Resource Sharing (CORS).
     *
     * This function updates the list of accept headers that are permitted to access
     * resources on the server. The allowed accept headers are used to determine which
     * Accept headers are permitted in CORS requests.
     *
     * @param array $acceptHeaders An array of allowed accept headers.
     */
    public function setAllowedAcceptHeaders(array $acceptHeaders): void
    {
        $this->AllowedAcceptHeaders = array_values(array_intersect(AcceptHeader::$values, $acceptHeaders));
    }

    /**
     * Sets the allowed request methods for Cross-Origin Resource Sharing (CORS).
     *
     * This function updates the list of request methods that are permitted to access
     * resources on the server. The allowed request methods are used to determine which
     * request methods are permitted in CORS requests.
     *
     * @param array $requestMethods An array of allowed request methods.
     */
    public function setAllowedRequestMethods(array $requestMethods): void
    {
        $this->AllowedRequestMethods = array_values(array_intersect(RequestMethod::$values, $requestMethods));
    }

    /**
     * Sets the allowed request content types for Cross-Origin Resource Sharing (CORS).
     *
     * This function updates the list of request content types that are permitted to access
     * resources on the server. The allowed request content types are used to determine which
     * request content types are permitted in CORS requests.
     *
     * @param array $requestContentTypes An array of allowed request content types.
     */
    public function setAllowedRequestContentTypes(array $requestContentTypes): void
    {
        $this->AllowedRequestContentTypes = array_values(array_intersect(RequestContentType::$values, $requestContentTypes));
    }

    /**
     * Get the response content type.
     *
     * @return ?string The response content type.
     */
    public function getResponseContentType(): ?string
    {
        return $this->ResponseContentType;
    }

    /**
     * Set the response content type.
     *
     * @param string $responseContentType The response content type.
     */
    public function setResponseContentType(string $responseContentType): void
    {
        $this->ResponseContentType = $responseContentType;
    }

    /**
     * Sets the response Content-Type header based on the value of $this->ResponseContentType.
     */
    public function setResponseContentTypeHeader(): void
    {
        header('Cache-Control: must-revalidate, max-age=259200'); //cache for 1 month
        header("Content-Type: {$this->ResponseContentType}; charset=utf-8");
    }

    /**
     * Gets the list of allowed Accept headers.
     *
     * @return array The list of allowed Accept headers.
     */
    public function getAllowedAcceptHeaders(): array
    {
        return $this->AllowedAcceptHeaders;
    }

    /**
     * Retrieves the list of allowed request content types for Cross-Origin Resource Sharing (CORS).
     *
     * This function returns an array of content types that are permitted in the request.
     *
     * @return array The list of allowed request content types.
     */
    public function getAllowedRequestContentTypes(): array
    {
        return $this->AllowedRequestContentTypes;
    }

    /**
     * Gets the value of the Accept header in the request.
     *
     * This function returns the value of the Accept header in the request as a string.
     *
     * @return string The value of the Accept header in the request.
     */
    public function getAcceptHeader(): string
    {
        return $this->RequestHeaders[ "Accept" ];
    }

    /**
     * Returns the index of the Accept header in the list of allowed Accept headers.
     * If the Accept header is not in the list of allowed Accept headers, it returns false.
     *
     * @return int|string|false The index of the Accept header in the list of allowed Accept headers, or false if not found.
     */
    public function getIdxAcceptHeaderInAllowed(): int|string|false
    {
        return array_search($this->RequestHeaders[ "Accept" ], $this->AllowedAcceptHeaders);
    }

    /**
     * Returns true if the request has an Accept header, or false if the request does not have an Accept header.
     *
     * @return bool True if the request has an Accept header, or false if the request does not have an Accept header.
     */
    public function hasAcceptHeader(): bool
    {
        return isset($this->RequestHeaders[ "Accept" ]);
    }

    /**
     * Checks whether the request is an AJAX request by inspecting the 'HTTP_X_REQUESTED_WITH' header.
     * Returns true if the request is an AJAX request, otherwise false.
     */
    public function isAjaxRequest(): bool
    {
        return false === (
            !isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            || empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest'
        );
    }

    /**
     * Enforces that the request is an AJAX request.
     *
     * If the request is not an AJAX request, it will return a 418 I'm a teapot error and die.
     *
     * This function is used to prevent unwanted requests to the API.
     *
     * @return void
     */
    public function enforceAjaxRequest(): void
    {
        if (false === $this->isAjaxRequest()) {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 418 I'm a teapot", true, 418);
            $errorMessage = '{"error":"Request was not made via AJAX. When using Request Method ' . strtoupper($_SERVER[ 'REQUEST_METHOD' ]) . ', only AJAX requests from authorized Origins and Referers are processable."}';
            die($errorMessage);
        }
    }

    /**
     * Checks if the Accept header in the request is allowed based on the list of allowed Accept headers.
     *
     * @return bool True if the Accept header is allowed, false otherwise.
     */
    public function isAllowedAcceptHeader(): bool
    {
        return in_array(explode(',', $this->RequestHeaders[ "Accept" ])[0], $this->AllowedAcceptHeaders);
    }

    /**
     * Checks if the request Origin is allowed based on the list of allowed Origins.
     *
     * This function returns true if the request Origin is allowed, false otherwise.
     *
     * @return bool True if the request Origin is allowed, false otherwise.
     */
    public function isAllowedOrigin(): bool
    {
        return isset($this->RequestHeaders[ "Origin" ]) && in_array($this->RequestHeaders[ "Origin" ], $this->AllowedOrigins);
    }

    /**
     * Checks if the request Referer is allowed based on the list of allowed Referers.
     *
     * This function returns true if the request Referer is allowed, false otherwise.
     *
     * @return bool True if the request Referer is allowed, false otherwise.
     */
    public function isAllowedReferer(): bool
    {
        return in_array($_SERVER["HTTP_REFERER"], $this->AllowedReferers);
    }

    /**
     * Gets the list of allowed request methods for Cross-Origin Resource Sharing (CORS).
     *
     * @return array The list of allowed request methods.
     */
    public function getAllowedRequestMethods(): array
    {
        return $this->AllowedRequestMethods;
    }

    /**
     * Retrieves the HTTP request method used to call the API.
     *
     * @return string The HTTP request method used to call the API.
     */
    public function getRequestMethod(): string
    {
        return strtoupper($_SERVER[ 'REQUEST_METHOD' ]);
    }

    /**
     * Returns the headers of the HTTP request used to call the API.
     *
     * This function returns the headers of the HTTP request used to call the API.
     *
     * @return array The headers of the HTTP request used to call the API.
     */
    public function getRequestHeaders(): array
    {
        return $this->RequestHeaders;
    }

    /**
     * Returns the headers of the HTTP request used to call the API, encoded as a JSON string.
     *
     * This function returns the headers of the HTTP request used to call the API, encoded as a JSON string.
     *
     * @return string The headers of the HTTP request used to call the API, encoded as a JSON string.
     */
    public function getJsonEncodedRequestHeaders(): string
    {
        return $this->JsonEncodedRequestHeaders;
    }

    /**
     * Returns the value of the Content-Type header in the request, or null if no Content-Type header was provided.
     *
     * @return ?string The value of the Content-Type header in the request, or null if no Content-Type header was provided.
     */
    public function getRequestContentType(): ?string
    {
        return $this->RequestContentType;
    }

    /**
     * Retrieves the list of allowed referers for Cross-Origin Resource Sharing (CORS).
     *
     * This function returns an array of referer URLs that are permitted to access
     * resources on the server. The allowed referers are used to determine which
     * Referer headers are permitted in CORS requests.
     *
     * @return array The list of allowed referer URLs.
     */
    public function getAllowedReferers(): array
    {
        return $this->AllowedReferers;
    }

    /**
     * Enforces that the request is coming from an allowed referer.
     *
     * If the request is not coming from an allowed referer, it will return a 401 Unauthorized error and die.
     *
     * This function is used to prevent unwanted requests to the API.
     *
     * @return void
     */
    public function enforceReferer(): void
    {
        if (false === $this->isAllowedReferer()) {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 401 Unauthorized", true, 401);
            $errorMessage = '{"error":"Request is coming from unauthorized referer ' . $_SERVER["HTTP_REFERER"] . '. Only AJAX requests from authorized Origins and Referers are processable."}';
            die($errorMessage);
        }
    }

    /**
     * Retrieves the request parameters from the body of the request, assuming it is a JSON encoded object.
     *
     * If the request body is empty, it will return a 400 Bad Request error and die.
     *
     * If the request body is not a valid JSON encoded object, it will return a 400 Bad Request error and die.
     *
     * @param bool $assoc Whether to return the object as an associative array or a stdClass object.
     *
     * @return object|array The request parameters, either as a stdClass object or an associative array.
     */
    public function retrieveRequestParamsFromJsonBody(bool $assoc = false): object|array
    {
        $rawData = file_get_contents('php://input');
        if ("" === $rawData) {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 400 Bad Request", true, 400);
            die('{"error":"No JSON data received in the request"}');
        }
        $data = json_decode($rawData, $assoc);
        if (json_last_error() !== JSON_ERROR_NONE) {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 400 Bad Request", true, 400);
            die('{"error":"Malformed JSON data received in the request: <' . $rawData . '>, ' . json_last_error_msg() . '"}');
        }
        return $data;
    }

    /**
     * Handles warnings by throwing an Exception.
     *
     * This function acts as a custom error handler that converts warnings into exceptions.
     * It is registered as a warning handler to maintain consistent error handling using exceptions.
     *
     * @param int $errno The level of the error raised.
     * @param string $errstr The error message.
     *
     * @throws \Exception Always throws an exception with the error message and level.
     */
    private static function warningHandler($errno, $errstr)
    {
        throw new \Exception($errstr, $errno);
    }

    /**
     * Retrieve the request parameters from the request body, expected to be a YAML encoded object.
     *
     * If the request body is empty, it will return a 400 Bad Request error and die.
     *
     * If the request body is not a valid YAML encoded object, it will return a 400 Bad Request error and die.
     *
     * @param bool $assoc Whether to return the object as an associative array or a stdClass object.
     *
     * @return object|array The request parameters, either as a stdClass object or an associative array.
     */
    public function retrieveRequestParamsFromYamlBody(bool $assoc = false): object|array
    {
        $rawData = file_get_contents('php://input');
        if ("" === $rawData) {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 400 Bad Request", true, 400);
            die('{"error":"No YAML data received in the request"}');
        }

        set_error_handler(array('self', 'warningHandler'), E_WARNING);
        try {
            $data = yaml_parse($rawData);
            if (false === $data) {
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 400 Bad Request", true, 400);
                $response = new \stdClass();
                $response->error = "Malformed YAML data received in the request";
                die(json_encode($response));
            } else {
                return $assoc ? $data : json_decode(json_encode($data));
            }
        } catch (\Exception $e) {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 400 Bad Request", true, 400);
            $response = new \stdClass();
            $response->status = "error";
            $response->message = "Malformed YAML data received in the request";
            $response->error = $e->getMessage();
            $response->line = $e->getLine();
            $response->code = $e->getCode();
            die(json_encode($response));
        }
    }

    /**
     * Initialize the API by setting the allowed origin header, the allowed methods
     * and validating the request content type.
     *
     * This function is expected to be called at the beginning of the API.
     */
    public function init()
    {
        $this->setAllowedOriginHeader();
        $this->setAccessControlAllowMethods();
        $this->validateRequestContentType();
    }
}
