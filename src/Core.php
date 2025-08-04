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
    /** @var string[] */
    private array $AllowedOrigins;
    /** @var string[] */
    private array $AllowedReferers;
    /** @var AcceptHeader[] */
    private array $AllowedAcceptHeaders;
    /** @var RequestMethod[] */
    private array $AllowedRequestMethods;
    /** @var RequestContentType[] */
    private array $AllowedRequestContentTypes;
    /** @var string[] */
    private array $RequestHeaders                   = [];
    private ?RequestContentType $RequestContentType = null;
    private ?AcceptHeader $ResponseContentType      = null;
    private const ONLY_USEFUL_HEADERS               = [
        'Accept',
        'Accept-Language',
        'X-Requested-With', 'Origin'
    ];
    private readonly string|false $JsonEncodedRequestHeaders;

    /**
     * Initializes the Core object with default values for AllowedOrigins, AllowedReferers, AllowedAcceptHeaders,
     * AllowedRequestMethods, and AllowedRequestContentTypes.
     * Parses the useful headers from the request and stores them in RequestHeaders.
     * Encodes the RequestHeaders to JSON and stores them in JsonEncodedRequestHeaders.
     * Sets the RequestContentType based on the $_SERVER['CONTENT_TYPE'] value.
     */
    public function __construct()
    {
        $this->AllowedOrigins             = [ '*' ];
        $this->AllowedReferers            = [ '*' ];
        $this->AllowedAcceptHeaders       = AcceptHeader::cases();
        $this->AllowedRequestMethods      = RequestMethod::cases();
        $this->AllowedRequestContentTypes = RequestContentType::cases();

        foreach (getallheaders() as $header => $value) {
            if (in_array($header, self::ONLY_USEFUL_HEADERS)) {
                $this->RequestHeaders[$header] = $value;
            }
        }
        $this->JsonEncodedRequestHeaders = json_encode($this->RequestHeaders);
        if (
            isset($_SERVER['CONTENT_TYPE'])
            && in_array($_SERVER['CONTENT_TYPE'], array_column($this->AllowedRequestContentTypes, 'value'))
        ) {
            $this->RequestContentType = RequestContentType::from($_SERVER['CONTENT_TYPE']);
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
    private function setAllowedOriginHeader(): void
    {
        if (count($this->AllowedOrigins) === 1 && $this->AllowedOrigins[0] === '*') {
            header('Access-Control-Allow-Origin: *');
        } elseif ($this->isAllowedOrigin()) {
            header('Access-Control-Allow-Origin: ' . $this->RequestHeaders['Origin']);
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
    private function setAccessControlAllowMethods(): void
    {
        if (isset($_SERVER['REQUEST_METHOD'])) {
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                header('Access-Control-Allow-Methods: ' . implode(',', array_column($this->AllowedRequestMethods, 'value')));
            }
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
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
    private function validateRequestContentType(): void
    {
        $allowedRequestContentTypeValues = array_column($this->AllowedRequestContentTypes, 'value');
        if (
            isset($_SERVER['CONTENT_TYPE'])
            && $_SERVER['CONTENT_TYPE'] !== ''
            && !in_array(
                explode(';', $_SERVER['CONTENT_TYPE'])[0],
                $allowedRequestContentTypeValues
            )
        ) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 415 Unsupported Media Type', true, 415);
            $response        = new \stdClass();
            $response->error = 'You seem to be forming a strange kind of request? Allowed Content Types are '
            . implode(' and ', $allowedRequestContentTypeValues)
            . ', but your Content Type was '
            . $_SERVER['CONTENT_TYPE'];
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
        header($_SERVER['SERVER_PROTOCOL'] . ' 406 Not Acceptable', true, 406);
        $response        = new \stdClass();
        $response->error = 'You are requesting a content type which this API cannot produce. Allowed Accept headers are '
            . implode(' and ', array_column($this->AllowedAcceptHeaders, 'value'))
            . ', but you have issued a request with an Accept header of '
            . $this->RequestHeaders['Accept'];
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
    public function validateAcceptHeader(bool $beLaxAboutIt): void
    {
        if ($this->hasAcceptHeader()) {
            $receivedAcceptHeader = $this->getAcceptHeader();
            $acceptHeaders        = explode(',', $receivedAcceptHeader);
            $firstAcceptHeader    = $acceptHeaders[0];
            if ($this->isAllowedAcceptHeader()) {
                $this->ResponseContentType = AcceptHeader::from($firstAcceptHeader);
            } else {
                if ($beLaxAboutIt) {
                    //Requests from browser windows using the address bar will probably have an Accept header of text/html
                    //In order to not be too drastic, let's treat text/html as though it were application/json for GET and POST requests only
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
            $this->ResponseContentType = $this->AllowedAcceptHeaders[0];
        }
    }

    /**
     * Sets the allowed origins for Cross-Origin Resource Sharing (CORS).
     *
     * This function updates the list of origins that are permitted to access
     * resources on the server. The allowed origins are used to determine which
     * Origin headers are permitted in CORS requests.
     *
     * @param string[] $origins An array of allowed origin URLs.
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
     * @param string[] $referers An array of allowed referer URLs.
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
     * @param AcceptHeader[] $acceptHeaders An array of allowed accept headers.
     */
    public function setAllowedAcceptHeaders(array $acceptHeaders): void
    {
        $this->AllowedAcceptHeaders = array_values(array_filter(
            AcceptHeader::cases(),
            fn(AcceptHeader $case) => in_array($case, $acceptHeaders, true)
        ));
    }

    /**
     * Sets the allowed request methods for Cross-Origin Resource Sharing (CORS).
     *
     * This function updates the list of request methods that are permitted to access
     * resources on the server. The allowed request methods are used to determine which
     * request methods are permitted in CORS requests.
     *
     * @param RequestMethod[] $requestMethods An array of allowed request methods.
     */
    public function setAllowedRequestMethods(array $requestMethods): void
    {
        $this->AllowedRequestMethods = array_values(array_filter(
            RequestMethod::cases(),
            fn(RequestMethod $case) => in_array($case, $requestMethods, true)
        ));
    }

    /**
     * Sets the allowed request content types for Cross-Origin Resource Sharing (CORS).
     *
     * This function updates the list of request content types that are permitted to access
     * resources on the server. The allowed request content types are used to determine which
     * request content types are permitted in CORS requests.
     *
     * @param RequestContentType[] $requestContentTypes An array of allowed request content types.
     */
    public function setAllowedRequestContentTypes(array $requestContentTypes): void
    {
        $this->AllowedRequestContentTypes = array_values(array_filter(
            RequestContentType::cases(),
            fn(RequestContentType $case) => in_array($case, $requestContentTypes, true)
        ));
    }

    /**
     * Get the response content type.
     *
     * @return ?AcceptHeader The response content type.
     */
    public function getResponseContentType(): ?AcceptHeader
    {
        return $this->ResponseContentType;
    }

    /**
     * Set the response content type.
     *
     * @param AcceptHeader $responseContentType The response content type.
     */
    public function setResponseContentType(AcceptHeader $responseContentType): void
    {
        $this->ResponseContentType = $responseContentType;
    }

    /**
     * Sets the response Content-Type header based on the value of $this->ResponseContentType.
     */
    public function setResponseContentTypeHeader(): void
    {
        if (null === $this->ResponseContentType) {
            die(__METHOD__ . ': Cannot set the Content Type header for the response, ResponseContentType is null, on line ' . __LINE__);
        }
        header('Cache-Control: must-revalidate, max-age=259200'); //cache for 1 month
        header("Content-Type: {$this->ResponseContentType->value}; charset=utf-8");
    }

    /**
     * Gets the list of allowed Accept headers.
     *
     * @return AcceptHeader[] The list of allowed Accept headers.
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
     * @return RequestContentType[] The list of allowed request content types.
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
        return $this->RequestHeaders['Accept'];
    }

    /**
     * Returns the index of the Accept header in the list of allowed Accept headers.
     * If the Accept header is not in the list of allowed Accept headers, it returns false.
     *
     * @return int|string|false The index of the Accept header in the list of allowed Accept headers, or false if not found.
     */
    public function getIdxAcceptHeaderInAllowed(): int|string|false
    {
        $allowedAcceptHeaderValues = array_column($this->AllowedAcceptHeaders, 'value');
        return array_search($this->RequestHeaders['Accept'], $allowedAcceptHeaderValues, true);
    }

    /**
     * Returns true if the request has an Accept header, or false if the request does not have an Accept header.
     *
     * @return bool True if the request has an Accept header, or false if the request does not have an Accept header.
     */
    public function hasAcceptHeader(): bool
    {
        return isset($this->RequestHeaders['Accept']);
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
            header($_SERVER['SERVER_PROTOCOL'] . " 418 I'm a teapot", true, 418);
            $errorMessage = '{"error":"Request was not made via AJAX. When using Request Method ' . strtoupper($_SERVER['REQUEST_METHOD']) . ', only AJAX requests from authorized Origins and Referers are processable."}';
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
        $allowedAcceptHeaderValues = array_column($this->AllowedAcceptHeaders, 'value');
        $firstAcceptHeader         = explode(',', $this->RequestHeaders['Accept'])[0];
        return in_array($firstAcceptHeader, $allowedAcceptHeaderValues);
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
        return isset($this->RequestHeaders['Origin']) && in_array($this->RequestHeaders['Origin'], $this->AllowedOrigins);
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
        return in_array($_SERVER['HTTP_REFERER'], $this->AllowedReferers);
    }

    /**
     * Gets the list of allowed request methods for Cross-Origin Resource Sharing (CORS).
     *
     * @return RequestMethod[] The list of allowed request methods.
     */
    public function getAllowedRequestMethods(): array
    {
        return $this->AllowedRequestMethods;
    }

    /**
     * Retrieves the HTTP request method used to call the API.
     *
     * @return RequestMethod The HTTP request method used to call the API.
     */
    public function getRequestMethod(): RequestMethod
    {
        return RequestMethod::from(strtoupper($_SERVER['REQUEST_METHOD']));
    }

    /**
     * Returns the headers of the HTTP request used to call the API.
     *
     * This function returns the headers of the HTTP request used to call the API.
     *
     * @return string[] The headers of the HTTP request used to call the API.
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
    public function getJsonEncodedRequestHeaders(): string|false
    {
        return $this->JsonEncodedRequestHeaders;
    }

    /**
     * Returns the value of the Content-Type header in the request, or null if no Content-Type header was provided.
     *
     * @return ?RequestContentType The value of the Content-Type header in the request, or null if no Content-Type header was provided.
     */
    public function getRequestContentType(): ?RequestContentType
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
     * @return string[] The list of allowed referer URLs.
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
            header($_SERVER['SERVER_PROTOCOL'] . ' 401 Unauthorized', true, 401);
            $errorMessage = '{"error":"Request is coming from unauthorized referer ' . $_SERVER['HTTP_REFERER'] . '. Only AJAX requests from authorized Origins and Referers are processable."}';
            die($errorMessage);
        }
    }

    /**
     * Retrieves the request parameters from the body of the request, assuming it is a JSON encoded object.
     *
     * If the request body is empty and $required is true, it will return a 400 Bad Request error and die.
     *
     * If the request body is not empty but is not a valid JSON encoded object, it will return a 400 Bad Request error and die.
     *
     * @param bool $required Whether the request body is required or not.
     * @param bool $assoc Whether to return the object as an associative array or a stdClass object.
     *
     * @return object|array<string,mixed>|null The request parameters, either as a stdClass object or an associative array, or null if the request body was not required and is empty.
     */
    public function readJsonBody(bool $required = false, bool $assoc = false): object|array|null
    {
        $data    = null;
        $rawData = file_get_contents('php://input');
        if (( false === $rawData || '' === $rawData ) && $required) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request', true, 400);
            die('{"error":"No JSON data received in the request"}');
        }
        if (false !== $rawData) {
            $data = json_decode($rawData, $assoc);
            if (json_last_error() !== JSON_ERROR_NONE) {
                header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request', true, 400);
                die('{"error":"Malformed JSON data received in the request: <' . $rawData . '>, ' . json_last_error_msg() . '"}');
            }
        }
        return $data;
    }

    /**
     * Handles warnings by throwing an Exception.
     *
     * This function acts as a custom error handler that converts warnings into exceptions.
     * It is registered as a warning handler to maintain consistent error handling using exceptions.
     * Used by {@see \LiturgicalCalendar\Api\Core::readYamlBody()} method to handle warnings from the yaml_parse function.
     *
     * @param int $errno The level of the error raised.
     * @param string $errstr The error message.
     *
     * @throws \ErrorException Always throws an exception with the error message and level.
     */
    private static function warningHandler(int $errno, string $errstr, string $errfile, int $errline): never
    {
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    /**
     * Retrieve the request parameters from the request body, expected to be a YAML encoded object.
     *
     * If the request body is empty and $required is true, it will return a 400 Bad Request error and die.
     *
     * If the request body is not empty but is not a valid YAML encoded object, it will return a 400 Bad Request error and die.
     *
     * @param bool $required Whether the request body is required or not.
     * @param bool $assoc Whether to return the object as an associative array or a stdClass object.
     *
     * @return object|array<string,mixed>|null The request parameters, either as a stdClass object or an associative array, or null if the request body was not required and is empty.
     */
    public function readYamlBody(bool $required = false, bool $assoc = false): object|array|null
    {
        $rawData = file_get_contents('php://input');

        if (false !== $rawData) {
            if ('' === $rawData && $required) {
                header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request', true, 400);
                die('{"error":"No YAML data received in the request"}');
            }

            if ('' !== $rawData) {
                set_error_handler([self::class, 'warningHandler'], E_WARNING);

                // @phpstan-ignore deadCode.unreachable
                try {
                    $data = yaml_parse($rawData);
                    // since we are converting the E_WARNING to an exception, and an Exception is thrown when the result is false,
                    // and we are catching the Exception, then we can assume that when no Exception is thrown,
                    // the $data variable is not false
                    if ($assoc) {
                        /** @var array<string,string> $data */
                        return $data;
                    } else {
                        /** @var object|array<mixed> $data */
                        $jsonData = json_encode($data);
                        if (false === $jsonData || json_last_error() !== JSON_ERROR_NONE) {
                            header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request', true, 400);
                            die('{"error":"Malformed YAML data received in the request: <' . $rawData . '>, ' . json_last_error_msg() . '"}');
                        }
                        return json_decode($jsonData);
                    }
                } catch (\ErrorException $e) {
                    header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request', true, 400);
                    $response          = new \stdClass();
                    $response->status  = 'error';
                    $response->message = 'Malformed YAML data received in the request';
                    $response->error   = $e->getMessage();
                    $response->line    = $e->getLine();
                    $response->code    = $e->getCode();
                    die(json_encode($response));
                } finally {
                    restore_error_handler();
                }
            } else {
                return null;
            }
        } else {
            throw new \RuntimeException('The request body is empty.');
        }
    }

    /**
     * Initialize the API by setting the allowed origin header, the allowed methods
     * and validating the request content type.
     *
     * This function is expected to be called at the beginning of the API.
     */
    public function init(): void
    {
        $this->setAllowedOriginHeader();
        $this->setAccessControlAllowMethods();
        $this->validateRequestContentType();
    }
}
