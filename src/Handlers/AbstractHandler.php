<?php

namespace LiturgicalCalendar\Api\Handlers;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\ImplementationException;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotAcceptableException;
use LiturgicalCalendar\Api\Http\Exception\UnsupportedMediaTypeException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Exception\YamlException;
use LiturgicalCalendar\Api\Http\Negotiator;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItem;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadataMap;

abstract class AbstractHandler implements RequestHandlerInterface
{
    /** @var RequestMethod[] */
    protected array $allowedRequestMethods;

    /** @var string[] */
    protected array $allowedOrigins;

    /** @var string[] */
    protected array $allowedReferers;

    /** @var AcceptHeader[] */
    protected array $allowedAcceptHeaders;

    /** @var RequestContentType[] */
    protected array $allowedRequestContentTypes;

    /** @var array<string|int> */
    protected array $requestPathParams;

    abstract public function handle(ServerRequestInterface $request): ResponseInterface;


    /**
     * @param string[] $requestPathParams
     */
    public function __construct(array $requestPathParams = [])
    {
        $this->requestPathParams = $requestPathParams;

        // We set very open default values, that should be explicitly set in the child classes
        $this->allowedOrigins             = [ '*' ];
        $this->allowedReferers            = [ '*' ];
        $this->allowedAcceptHeaders       = AcceptHeader::cases();
        $this->allowedRequestMethods      = RequestMethod::cases();
        $this->allowedRequestContentTypes = RequestContentType::cases();
    }

    /**
     * Set the allowed origins to restrict access to the API.
     *
     * This function can read the allowed origins from a file that defines the
     * ALLOWED_ORIGINS constant as an array of strings. If the file is not
     * provided, the function will use the provided array of origins.
     *
     * If the file is provided, the function will merge the provided array of
     * origins with the ones defined in the file. If the provided array is null,
     * the function will use the array from the file.
     *
     * @param string[] $origins The array of allowed origins.
     * @return void
     */
    public function setAllowedOrigins(array $origins): static
    {
        $this->allowedOrigins = $origins;

        return $this;
    }

    /**
     * @param string $originsFile The path to the file that defines the allowed origins.
     */
    public function setAllowedOriginsFromFile(string $originsFile): static
    {
        if (!file_exists($originsFile)) {
            $projectFolder = __DIR__;
            $level         = 0;
            while (true) {
                if (file_exists($projectFolder . DIRECTORY_SEPARATOR . $originsFile)) {
                    $originsFile = $projectFolder . DIRECTORY_SEPARATOR . $originsFile;
                    break;
                }

                // Don't look more than 4 levels up
                if ($level > 4) {
                    $originsFile = '';
                    break;
                }

                $parentDir = dirname($projectFolder);
                if ($parentDir === $projectFolder) { // reached the system root!
                    $originsFile = '';
                    break;
                }

                $projectFolder = $parentDir;
            }
        }

        if ('' === $originsFile) {
            throw new \Exception("Allowed origins file '{$originsFile}' not found.");
        }

        $originsFileContents = file($originsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (false === $originsFileContents) {
            throw new \Exception("Allowed origins file '{$originsFile}' could not be read.");
        }

        $originsFileStringContents = array_map(
            fn($v): string => strval($v),
            $originsFileContents
        );

        $trimmedOriginsFileContents = array_values(array_map(
            fn($v): string => trim($v),
            $originsFileStringContents
        ));

        $this->setAllowedOrigins($trimmedOriginsFileContents);

        return $this;
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
    public function setAllowedReferers(array $referers): static
    {
        $this->allowedReferers = $referers;

        return $this;
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
    public function setAllowedAcceptHeaders(array $acceptHeaders): static
    {
        $this->allowedAcceptHeaders = array_values(array_filter(
            AcceptHeader::cases(),
            fn(AcceptHeader $case) => in_array($case, $acceptHeaders, true)
        ));

        return $this;
    }

    /**
     * Sets the allowed request methods, determining whether the endpoint will be readonly or read/write capable.
     *
     * Restrict the list of request methods that an incoming client `HTTP Rrequest` is permitted to use
     * to access resources on the server.
     *
     * @param RequestMethod[] $requestMethods An array of allowed request methods.
     */
    public function setAllowedRequestMethods(array $requestMethods): static
    {
        $this->allowedRequestMethods = array_values(array_filter(
            RequestMethod::cases(),
            fn(RequestMethod $case) => in_array($case, $requestMethods, true)
        ));

        return $this;
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
    public function setAllowedRequestContentTypes(array $requestContentTypes): static
    {
        $this->allowedRequestContentTypes = array_values(array_filter(
            RequestContentType::cases(),
            fn(RequestContentType $case) => in_array($case, $requestContentTypes, true)
        ));

        return $this;
    }

    /**
     * Handles CORS preflight OPTIONS requests
     * and sets the Access-Control-Allow-Origin header
     * based on the allowed origins set by the endpoint.
     *
     * If the only allowed origin is '*', the header is set to allow all origins.
     * If the request origin is in the list of allowed origins,
     *   the header is set to allow that specific origin.
     * Otherwise, the header is set to allow only the server's domain (only same domain requests allowed).
     */
    private function setAccessControlAllowOriginHeader(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $originHeader = $request->getHeaderLine('Origin');

        if ($originHeader !== '') {
            // If setAllowedOrigins was never called, the default value is to allow all origins,
            // so as to allow for CORS requests from any origin
            if (count($this->allowedOrigins) === 1 && $this->allowedOrigins[0] === '*') {
                return $response->withHeader('Access-Control-Allow-Origin', '*');
            }

            // If instead the allowed origins were explicitly set,
            // then check if the request origin is in the list of allowed origins
            if ($this->isAllowedOrigin($originHeader)) {
                return $response->withHeader('Access-Control-Allow-Origin', $originHeader);
            }

            // If the request origin is not in the list of allowed origins,
            // then we allow only the server's domain (same domain requests)
            if (
                ( isset($_SERVER['REQUEST_SCHEME']) && !empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] == 'https' )
                || ( isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' )
                || ( isset($_SERVER['SERVER_PORT']) && !empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '443' )
            ) {
                $server_request_scheme = 'https';
            } else {
                $server_request_scheme = 'http';
            }

            $serverPort = isset($_SERVER['SERVER_PORT']) && is_string($_SERVER['SERVER_PORT']) && !in_array($_SERVER['SERVER_PORT'], ['80', '443'])
                ? ':' . $_SERVER['SERVER_PORT']
                : '';

            $origin = $server_request_scheme . '://localhost' . $serverPort;
            if (isset($_SERVER['SERVER_NAME']) && is_string($_SERVER['SERVER_NAME'])) {
                $origin = $server_request_scheme . '://' . $_SERVER['SERVER_NAME'] . $serverPort;
            } elseif (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
                // on localhost this should already include the port in typical setups / default PHP internal server
                $origin = $server_request_scheme . '://' . $_SERVER['HTTP_HOST'];
            } elseif (isset($_SERVER['SERVER_ADDR']) && is_string($_SERVER['SERVER_ADDR'])) {
                $origin = $server_request_scheme . '://' . $_SERVER['SERVER_ADDR'] . $serverPort;
            }

            return $response->withHeader('Access-Control-Allow-Origin', $origin);
        }

        return $response;
    }

    /**
     * Handles CORS preflight OPTIONS requests
     * and sets the Access-Control-Allow-Methods header,
     * if the request has the Access-Control-Request-Method header set.
     */
    private function setAccessControlAllowMethodsHeader(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $methodHeader = $request->getHeaderLine('Access-Control-Request-Method');
        if ($methodHeader !== '') {
            return $response->withHeader('Access-Control-Allow-Methods', implode(',', array_column($this->allowedRequestMethods, 'value')));
        }
        return $response;
    }

    /**
     * Handles CORS preflight OPTIONS requests
     * and sets the Access-Control-Allow-Headers header,
     * if the request has the Access-Control-Request-Headers header set.
     */
    private function setAccessControlAllowHeadersHeader(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $headersHeader = $request->getHeaderLine('Access-Control-Request-Headers');
        if ($headersHeader !== '') {
            return $response->withHeader('Access-Control-Allow-Headers', $headersHeader);
        }
        return $response;
    }

    /**
     * By emitting this header the server indicates that it allows credentials to be included in cross-origin HTTP requests.
     *
     * `true` is the only valid value for this header and is case-sensitive.
     * If you don't need credentials, omit this header entirely rather than setting its value to `false`.
     *
     * @link https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Access-Control-Allow-Credentials
     */
    private function setAccessControlAllowCredentialsHeader(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Access-Control-Allow-Credentials', 'true');
    }

    private function setAccessControlMaxAgeHeader(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Access-Control-Max-Age', '86400'); // cache for 1 day
    }

    protected function handlePreflightRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response = $response->withStatus(StatusCode::OK->value, StatusCode::OK->reason());
        $response = $this->setAccessControlAllowOriginHeader($request, $response);
        $response = $this->setAccessControlAllowMethodsHeader($request, $response);
        $response = $this->setAccessControlAllowHeadersHeader($request, $response);
        // Since in the current implementation of the API we do not request credentials for any requests, we should omit this header.
        // $response = $this->setAccessControlAllowCredentialsHeader($response);
        $response = $this->setAccessControlMaxAgeHeader($response);
        return $response;
    }

    /**
     * Checks if the request Origin is allowed based on the list of allowed Origins.
     *
     * This function returns true if the request Origin is allowed, false otherwise.
     *
     * @return bool True if the request Origin is allowed, false otherwise.
     */
    protected function isAllowedOrigin(string $origin): bool
    {
        return $origin !== '' && in_array($origin, $this->allowedOrigins);
    }

    /**
     * Checks if the request Referer is allowed based on the list of allowed Referers.
     *
     * This function returns true if the request Referer is allowed, false otherwise.
     *
     * @return bool True if the request Referer is allowed, false otherwise.
     */
    protected function isAllowedReferer(): bool
    {
        return in_array($_SERVER['HTTP_REFERER'], $this->allowedReferers);
    }


    /**
     * Validates the request HTTP method against the list of allowed HTTP methods.
     *
     * This function checks if the request HTTP method is among the values that the API endpoint declares as acceptable.
     * If the HTTP method is not allowed, a `MethodNotAllowedException` will be thrown.
     *
     * @throws MethodNotAllowedException If the request HTTP method is not allowed.
     */
    protected function validateRequestMethod(ServerRequestInterface $request): void
    {
        if (!in_array($request->getMethod(), array_column($this->allowedRequestMethods, 'value'))) {
            throw new MethodNotAllowedException();
        }
    }


    /**
     * Validates the request Accept header against the list of allowed Accept headers.
     *
     * This function checks if the request Accept header is among the values that the API endpoint declares as acceptable.
     * If the Accept header is empty or a value that the endpoint has not declared as acceptable,
     * the endpoint will either return a `Response` with the first permissible Content-Type (usually `application/json`)
     * or throw a `NotAcceptableException`, depending on the value of the `$acceptabilityLevel` parameter.
     *
     * @param ServerRequestInterface $request The request object.
     * @param ResponseInterface $response The response object.
     * @param AcceptabilityLevel $acceptabilityLevel The acceptability level of the Accept header.
     * @return ResponseInterface The response object with the Content-Type header set to the first permissible Accept header value.
     * @throws NotAcceptableException If the Accept header is empty or a value that the endpoint has not declared as acceptable,
     *                                or if the acceptability level is `STRICT`.
     */
    protected function validateAcceptHeader(ServerRequestInterface $request, ResponseInterface $response, AcceptabilityLevel $acceptabilityLevel): ResponseInterface
    {
        $acceptHeaderValues = [];
        $acceptHeader       = $request->getHeaderLine('Accept');
        if ($acceptHeader === '') {
            if ($acceptabilityLevel === AcceptabilityLevel::STRICT) {
                throw new NotAcceptableException();
            }
            return $response->withHeader('Content-Type', $this->allowedAcceptHeaders[0]);
        }

        $mime               = Negotiator::pickMediaType($request, array_column($this->allowedAcceptHeaders, 'value'));
        $acceptHeaderValues = Negotiator::getAcceptValues();

        if ($mime !== null) {
            return $response->withHeader('Content-Type', $mime);
        } else {
            // Requests from browser windows using the address bar will probably have an Accept header of `text/html`.
            // In order to not be too drastic, let's treat `text/html` as though it were `application/json` for GET and POST requests only,
            //   even though this should have already been taken care of by the Negotiator which intelligently detects browser requests.
            if (
                $acceptabilityLevel === AcceptabilityLevel::LAX
                && ( in_array('text/html', $acceptHeaderValues) || in_array('text/plain', $acceptHeaderValues) || in_array('*/*', $acceptHeaderValues) )
            ) {
                return $response->withHeader('Content-Type', $this->allowedAcceptHeaders[0]->value);
            }
        }

        // Catch all for all of the failed cases
        throw new NotAcceptableException();
    }

    /**
     * Validates the request Content-Type header against the list of allowed Content-Types.
     *
     * This function throws an `UnsupportedMediaTypeException` if the request Content-Type header is not
     * among the list of allowed Content-Types.
     *
     * @throws UnsupportedMediaTypeException When the request Content-Type header is not among the list of allowed Content-Types.
     */
    protected function validateRequestContentType(ServerRequestInterface $request): void
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if (!in_array($contentType, array_column($this->allowedRequestContentTypes, 'value'))) {
            throw new UnsupportedMediaTypeException();
        }
    }

    /**
     * Get only scalar-valued query parameters from the request.
     *
     * @param ServerRequestInterface $request
     * @return array<string, scalar|null> Filtered query parameters
     */
    protected function getScalarQueryParams(ServerRequestInterface $request): array
    {
        return array_filter(
            $request->getQueryParams(),
            fn($value) => is_scalar($value) || $value === null
        );
    }

    /**
     * Handles warnings by throwing an \ErrorException.
     *
     * This function acts as a custom error handler that converts warnings into `\ErrorException` exceptions.
     * It is registered as a warning handler to maintain consistent error handling using exceptions.
     * Used by the {@see \LiturgicalCalendar\Api\Handlers\AbstractHandler::parseBodyParams()},
     * {@see \LiturgicalCalendar\Api\Handlers\AbstractHandler::parseBodyPayload()},
     * and {@see \LiturgicalCalendar\Api\Handlers\AbstractHandler::encodeResponseBody()} methods
     * to handle warnings from the `yaml_parse` and `yaml_emit` functions.
     *
     * @param int $errno The level of the error raised.
     * @param string $errstr The error message.
     *
     * @throws \ErrorException Always throws an exception with the error message and level.
     */
    protected static function warningHandler(int $errno, string $errstr, string $errfile, int $errline): never
    {
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    /**
     * Parse the request body according to the request Content-Type,
     * and return the parsed parameters with only scalar values.
     *
     * @return array<string,scalar|null>
     */
    protected function parseBodyParams(ServerRequestInterface $request, bool $required = false): ?array
    {
        $this->validateRequestContentType($request);

        // We parse the body according to the request Content-Type
        $mime = RequestContentType::from($request->getHeaderLine('Content-Type')) ?? null;
        if ($mime === null) {
            if ($required) {
                throw new UnsupportedMediaTypeException();
            } else {
                // silently discard a request with an unsupported Content-Type
                return null;
            }
        }

        $rawBodyContents = $request->getBody()->getContents();
        if ('' === $rawBodyContents) {
            if ($required) {
                throw new ValidationException('Empty body content received in the request');
            } else {
                // silently discard an empty body content
                return null;
            }
        }

        switch ($mime) {
            case RequestContentType::JSON:
                $parsedBody = json_decode($rawBodyContents, true);
                break;
            case RequestContentType::YAML:
                if (!extension_loaded('yaml')) {
                    throw new ImplementationException('YAML extension not loaded');
                }

                set_error_handler([self::class, 'warningHandler'], E_WARNING);
                try {
                    $parsedBody = yaml_parse($rawBodyContents);
                } catch (\ErrorException $e) {
                    throw new YamlException($e->getMessage(), StatusCode::UNPROCESSABLE_CONTENT->value, $e);
                } finally {
                    restore_error_handler();
                }
                break;
            case RequestContentType::FORMDATA:
                // no break (intentional fallthrough)
            case RequestContentType::MULTIPART:
                $parsedBody = $request->getParsedBody();
                break;
            default:
                return null;
        }

        // We don't expect a single scalar value, only an array of scalar values,
        // so we discard a pure scalar value
        if (is_scalar($parsedBody) || $parsedBody === null) {
            if ($required) {
                throw new UnsupportedMediaTypeException();
            } else {
                // silently discard the parsed body content
                return null;
            }
        }
        /** @var array<string,scalar|null> $parsedBodyWithOnlyScalarValues */
        $parsedBodyWithOnlyScalarValues = array_filter($parsedBody, fn($value) => is_scalar($value) || $value === null);
        return $parsedBodyWithOnlyScalarValues;
    }

    /**
     * Parse the request body according to the request Content-Type,
     * and return the parsed payload.
     *
     * @param bool $assoc If true, the payload will be returned as an associative array, otherwise as a `\stdClass` object
     *
     * @return array<string|int,mixed>|\stdClass|null
     */
    protected function parseBodyPayload(ServerRequestInterface $request, bool $assoc = true): array|\stdClass|null
    {
        $this->validateRequestContentType($request);

        // We parse the body according to the request Content-Type
        $mime = RequestContentType::from($request->getHeaderLine('Content-Type')) ?? null;
        if ($mime === null) {
            throw new UnsupportedMediaTypeException();
        }

        $rawBodyContents = $request->getBody()->getContents();
        if ('' === $rawBodyContents) {
            throw new ValidationException('Empty body content received in the request');
        }

        switch ($mime) {
            case RequestContentType::JSON:
                $parsedBody = json_decode($rawBodyContents, $assoc, 512, JSON_THROW_ON_ERROR);
                break;
            case RequestContentType::YAML:
                if (!extension_loaded('yaml')) {
                    throw new ImplementationException('YAML extension not loaded');
                }

                set_error_handler([self::class, 'warningHandler'], E_WARNING);
                try {
                    $parsedBody = yaml_parse($rawBodyContents);
                    if (false === $assoc) {
                        $parsedBody = json_decode(json_encode($parsedBody));
                    }
                } catch (\ErrorException $e) {
                    throw new YamlException($e->getMessage(), StatusCode::UNPROCESSABLE_CONTENT->value, $e);
                } finally {
                    restore_error_handler();
                }
                break;
            case RequestContentType::FORMDATA:
                // no break (intentional fallthrough)
            case RequestContentType::MULTIPART:
                $parsedBody = $request->getParsedBody();
                break;
            default:
                return null;
        }

        // We don't expect a single scalar value, but either an array or an object
        if (is_scalar($parsedBody) || $parsedBody === null) {
            throw new ValidationException('Invalid body content received in the request: expected an array or an object but found a scalar value or null');
        }

        return $parsedBody;
    }

    /**
     * Encodes the given response body as the specified content type and returns a new ResponseInterface with the
     * encoded body.
     *
     * @param ResponseInterface $response The response to which the body is to be encoded.
     * @param array<string|int,mixed>|\stdClass|MissalMetadataMap|DecreeItem $responseBody The response body to be encoded.
     * @return ResponseInterface The response with the encoded body.
     * @throws \JsonException If there is an error encoding the response body as JSON.
     * @throws YamlException If there is an error encoding the response body as YAML.
     */
    protected function encodeResponseBody(ResponseInterface $response, array|\stdClass|MissalMetadataMap|DecreeItem $responseBody): ResponseInterface
    {
        $contentType = AcceptHeader::from($response->getHeaderLine('Content-Type'));
        switch ($contentType) {
            case AcceptHeader::JSON:
                $encodedResponse = json_encode($responseBody, JSON_THROW_ON_ERROR);
                break;
            case AcceptHeader::YAML:
                if (!extension_loaded('yaml')) {
                    throw new ImplementationException('YAML extension not loaded');
                }
                // In order to emit YAML, we need to recast the response body as an array
                // So first we encode the object as JSON
                $jsonEncodedResponse = json_encode($responseBody, JSON_THROW_ON_ERROR);
                $recodedResponse     = json_decode($jsonEncodedResponse, true, 512, JSON_THROW_ON_ERROR);

                // Then we attempt to encode the array as YAML
                set_error_handler([static::class, 'warningHandler'], E_WARNING);
                try {
                    $encodedResponse = yaml_emit($recodedResponse, YAML_UTF8_ENCODING);
                } catch (\ErrorException $e) {
                    throw new YamlException($e->getMessage(), StatusCode::UNPROCESSABLE_CONTENT->value, $e);
                } finally {
                    restore_error_handler();
                }
                break;
            default:
                $encodedResponse = json_encode($responseBody, JSON_THROW_ON_ERROR);
        }
        return $response
            ->withStatus(StatusCode::OK->value, StatusCode::OK->reason())
            ->withBody(Stream::create($encodedResponse));
    }
}
