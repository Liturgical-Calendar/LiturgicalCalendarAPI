<?php

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotAcceptableException;
use LiturgicalCalendar\Api\Http\Exception\UnsupportedMediaTypeException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Exception\YamlException;
use LiturgicalCalendar\Api\Http\Negotiator;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItem;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadataMap;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Exception\DumpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

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

    /** @var string[] */
    protected array $requestPathParams;

    /**
     * When true, the server will send Access-Control-Allow-Credentials: true
     * and will not use wildcard for Access-Control-Allow-Origin.
     * Required for cross-origin requests that include cookies.
     */
    protected bool $allowCredentials = false;

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
     * @param string[] $origins The array of allowed origins.
     */
    public function setAllowedOrigins(array $origins): static
    {
        $this->allowedOrigins = $origins;

        return $this;
    }

    /**
     * Sets the allowed referers for API access control.
     *
     * This function updates the list of referers that are permitted to access
     * resources on the server. This can be used for additional access control
     * beyond CORS (e.g., API key restrictions based on referring page).
     *
     * Note: Referer validation is not part of CORS; it's a separate access control mechanism.
     *
     * @param string[] $referers An array of allowed referer URLs.
     */
    public function setAllowedReferers(array $referers): static
    {
        $this->allowedReferers = $referers;

        return $this;
    }

    /**
     * Sets the allowed Accept headers for content negotiation.
     *
     * This function updates the list of Accept header values (MIME types) that the
     * endpoint supports for response content. Used to determine which response
     * formats (e.g., JSON, YAML, XML) the endpoint can produce.
     *
     * Note: This is for content negotiation, not CORS.
     *
     * @param AcceptHeader[] $acceptHeaders An array of allowed accept headers.
     */
    public function setAllowedAcceptHeaders(array $acceptHeaders): static
    {
        $this->allowedAcceptHeaders = array_values(array_filter(
            AcceptHeader::cases(),
            function (AcceptHeader $case) use ($acceptHeaders): bool {
                return in_array($case, $acceptHeaders, true);
            }
        ));

        return $this;
    }

    /**
     * Sets the allowed request methods, determining whether the endpoint will be readonly or read/write capable.
     *
     * Restricts the list of HTTP methods that incoming requests are permitted to use
     * to access resources on the server.
     *
     * @param RequestMethod[] $requestMethods An array of allowed request methods.
     */
    public function setAllowedRequestMethods(array $requestMethods): static
    {
        $this->allowedRequestMethods = array_values(array_filter(
            RequestMethod::cases(),
            function (RequestMethod $case) use ($requestMethods): bool {
                return in_array($case, $requestMethods, true);
            }
        ));

        return $this;
    }

    /**
     * Sets the allowed request Content-Type headers for request body validation.
     *
     * This function updates the list of Content-Type values that the endpoint accepts
     * for request bodies. Used to validate that incoming requests with bodies (POST, PUT, PATCH)
     * use a supported content format (e.g., JSON, YAML, form data).
     *
     * Note: This is for request body validation, not CORS.
     *
     * @param RequestContentType[] $requestContentTypes An array of allowed request content types.
     */
    public function setAllowedRequestContentTypes(array $requestContentTypes): static
    {
        $this->allowedRequestContentTypes = array_values(array_filter(
            RequestContentType::cases(),
            function (RequestContentType $case) use ($requestContentTypes): bool {
                return in_array($case, $requestContentTypes, true);
            }
        ));

        return $this;
    }

    /**
     * Enable credentials support for cross-origin requests.
     *
     * When enabled, the server will send Access-Control-Allow-Credentials: true
     * and will echo the requesting origin (not use wildcard) in Access-Control-Allow-Origin.
     * Required for cross-origin requests that include cookies.
     *
     * @param bool $allow Whether to allow credentials
     */
    public function setAllowCredentials(bool $allow = true): static
    {
        $this->allowCredentials = $allow;
        return $this;
    }

    /**
     * Sets the Access-Control-Allow-Origin header for non-preflight requests.
     *
     * Note: CORS preflight OPTIONS requests are handled separately by handlePreflightRequest().
     * This method is called for regular requests (GET, POST, PUT, PATCH, DELETE, etc.)
     * to set the appropriate CORS origin header.
     *
     * If the only allowed origin is '*', the header is set to allow all origins
     * (unless credentials are enabled, in which case the specific origin is echoed).
     * If the request origin is in the list of allowed origins,
     *   the header is set to allow that specific origin.
     * Otherwise, the header is set to allow only the server's domain (only same domain requests allowed).
     */
    protected function setAccessControlAllowOriginHeader(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $originHeader = $request->getHeaderLine('Origin');

        if ($originHeader !== '') {
            $allowedOrigin = null;

            // If setAllowedOrigins was never called, the default value is to allow all origins,
            // so as to allow for CORS requests from any origin
            if (count($this->allowedOrigins) === 1 && $this->allowedOrigins[0] === '*') {
                // When credentials are enabled, we can't use wildcard - must echo the specific origin
                $allowedOrigin = $this->allowCredentials ? $originHeader : '*';
            } elseif ($this->isAllowedOrigin($originHeader)) {
                // If instead the allowed origins were explicitly set,
                // then check if the request origin is in the list of allowed origins
                $allowedOrigin = $originHeader;
            } else {
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

                $allowedOrigin = $server_request_scheme . '://localhost' . $serverPort;
                if (isset($_SERVER['SERVER_NAME']) && is_string($_SERVER['SERVER_NAME'])) {
                    $allowedOrigin = $server_request_scheme . '://' . $_SERVER['SERVER_NAME'] . $serverPort;
                } elseif (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
                    // on localhost this should already include the port in typical setups / default PHP internal server
                    $allowedOrigin = $server_request_scheme . '://' . $_SERVER['HTTP_HOST'];
                } elseif (isset($_SERVER['SERVER_ADDR']) && is_string($_SERVER['SERVER_ADDR'])) {
                    $allowedOrigin = $server_request_scheme . '://' . $_SERVER['SERVER_ADDR'] . $serverPort;
                }
            }

            $response = $response->withHeader('Access-Control-Allow-Origin', $allowedOrigin);

            // Add credentials header when credentials are enabled (required for cross-origin cookie requests)
            if ($this->allowCredentials) {
                $response = $this->setAccessControlAllowCredentialsHeader($response);
            }

            return $response;
        }

        return $response;
    }

    /**
     * Sets the Access-Control-Allow-Methods header for CORS preflight responses.
     *
     * This is a helper method called by handlePreflightRequest(). It sets the header
     * only if the request includes an Access-Control-Request-Method header.
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
     * Sets the Access-Control-Allow-Headers header for CORS preflight responses.
     *
     * This is a helper method called by handlePreflightRequest(). It sets the header
     * only if the request includes an Access-Control-Request-Headers header, echoing
     * back only the subset of requested headers that are allowed.
     */
    private function setAccessControlAllowHeadersHeader(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $headersHeader = $request->getHeaderLine('Access-Control-Request-Headers');
        if ($headersHeader !== '') {
            // Echo back only the subset of requested headers we allow; avoid forbidden headers per Fetch/CORS.
            $allowed   = ['Accept', 'Accept-Language', 'Content-Type', 'Authorization', 'X-Requested-With'];
            $requested = array_values(array_filter(array_map('trim', explode(',', $headersHeader))));
            // Case-insensitive intersection while preserving canonical casing and de-duplicating
            $canonicalByLc = array_combine(array_map('strtolower', $allowed), $allowed);
            $approvedAssoc = [];
            foreach ($requested as $header) {
                $lc = strtolower($header);
                if (isset($canonicalByLc[$lc])) {
                    $approvedAssoc[$canonicalByLc[$lc]] = true; // preserve casing, dedupe
                }
            }
            if ($approvedAssoc === []) {
                // No approved non-safelisted headers; omit ACAH entirely
                return $response;
            }
            return $response->withHeader('Access-Control-Allow-Headers', implode(',', array_keys($approvedAssoc)));
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
    protected function setAccessControlAllowCredentialsHeader(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Access-Control-Allow-Credentials', 'true');
    }

    /**
     * Sets the Access-Control-Max-Age header for CORS preflight caching.
     */
    private function setAccessControlMaxAgeHeader(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Access-Control-Max-Age', '86400'); // cache for 1 day
    }

    /**
     * Handles CORS preflight OPTIONS requests and regular OPTIONS requests.
     *
     * For CORS preflight requests (OPTIONS with Origin and Access-Control-Request-Method headers),
     * this method returns a 204 No Content response with appropriate CORS headers:
     * - Access-Control-Allow-Origin
     * - Access-Control-Allow-Methods
     * - Access-Control-Allow-Headers
     * - Access-Control-Max-Age
     * - Vary headers for cache optimization
     *
     * For regular OPTIONS requests, it returns a 200 OK with an Allow header listing
     * the HTTP methods supported by the endpoint.
     */
    protected function handlePreflightRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // if method == OPTIONS and "Origin" in headers and "Access-Control-Request-Method" in headers:
        //   # This is a CORS preflight request
        // if method == OPTIONS and not ("Origin" in headers or "Access-Control-Request-Method" in headers):
        //   # This is a normal OPTIONS request
        $isCorsRequest = ( $request->getMethod() === 'OPTIONS' && $request->getHeaderLine('Origin') !== '' && $request->getHeaderLine('Access-Control-Request-Method') !== '' );

        $response = $response->withStatus(StatusCode::OK->value, StatusCode::OK->reason());
        if ($isCorsRequest) {
            // Preflight should not carry a body
            $response = $response->withStatus(StatusCode::NO_CONTENT->value, StatusCode::NO_CONTENT->reason());
            $response = $this->setAccessControlAllowOriginHeader($request, $response);
            // Optimize cache hit-rates by only adding Vary: Origin when Access-Control-Allow-Origin is not "*"
            if ($response->getHeaderLine('Access-Control-Allow-Origin') !== '*') {
                $response = $response->withAddedHeader('Vary', 'Origin');
            }
            $response = $response->withAddedHeader('Vary', 'Access-Control-Request-Method')
                                 ->withAddedHeader('Vary', 'Access-Control-Request-Headers');
            $response = $this->setAccessControlAllowMethodsHeader($request, $response);
            $response = $this->setAccessControlAllowHeadersHeader($request, $response);
            // Note: credentials header is handled in setAccessControlAllowOriginHeader when $allowCredentials is true
            $response = $this->setAccessControlMaxAgeHeader($response);
        } else {
            $response = $response->withHeader('Allow', implode(',', array_column($this->allowedRequestMethods, 'value')));
        }
        return $response;
    }

    /**
     * Checks if the request Origin is allowed based on the list of allowed Origins.
     *
     * This function returns true if the request Origin is allowed, false otherwise.
     * Note: Wildcard ('*') handling is done in setAccessControlAllowOriginHeader()
     * before this method is called.
     *
     * @return bool True if the request Origin is allowed, false otherwise.
     */
    protected function isAllowedOrigin(string $origin): bool
    {
        return $origin !== '' && in_array($origin, $this->allowedOrigins, true);
    }

    /**
     * Checks if the request Referer is allowed based on the list of allowed Referers.
     *
     * This function returns true if the request Referer is allowed, false otherwise.
     * Unlike isAllowedOrigin(), this method handles wildcard ('*') internally since
     * referer validation is strict-matching-only by design (no CORS header echoing).
     *
     * Note: This method is currently unused but provided for future use cases
     * where referer validation may be needed (e.g., API key restrictions).
     *
     * @return bool True if the request Referer is allowed, false otherwise.
     */
    protected function isAllowedReferer(): bool
    {
        // If wildcard is set, all referers are allowed
        if (count($this->allowedReferers) === 1 && $this->allowedReferers[0] === '*') {
            return true;
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        return $referer !== '' && in_array($referer, $this->allowedReferers, true);
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
     * @param AcceptabilityLevel $acceptabilityLevel The acceptability level of the Accept header.
     * @return string The best pick mime type
     * @throws NotAcceptableException If the Accept header is empty or a value that the endpoint has not declared as acceptable,
     *                                or if the acceptability level is `STRICT`.
     */
    protected function validateAcceptHeader(ServerRequestInterface $request, AcceptabilityLevel $acceptabilityLevel): string
    {
        $acceptHeaderValues = [];
        $acceptHeader       = $request->getHeaderLine('Accept');
        if ($acceptHeader === '') {
            if ($acceptabilityLevel === AcceptabilityLevel::STRICT) {
                throw new NotAcceptableException();
            }
            // If the accept header is empty and the acceptability level is not STRICT,
            //   set the first Content-Type header allowed by the current handler on the response
            return $this->allowedAcceptHeaders[0]->value;
        }

        // If the accept header is not empty, negotiate the best pick
        $mime               = Negotiator::pickMediaType($request, array_column($this->allowedAcceptHeaders, 'value'));
        $acceptHeaderValues = Negotiator::getAcceptValues();

        if ($mime !== null) {
            return $mime;
        } else {
            // Requests from browser windows using the address bar will probably have an Accept header of `text/html`.
            // In order to not be too drastic, let's treat `text/html` as though it were `application/json` for GET and POST requests only,
            //   even though this should have already been taken care of by the Negotiator which intelligently detects browser requests.
            if (
                $acceptabilityLevel === AcceptabilityLevel::LAX
                && ( in_array('text/html', $acceptHeaderValues) || in_array('text/plain', $acceptHeaderValues) || in_array('*/*', $acceptHeaderValues) )
            ) {
                return $this->allowedAcceptHeaders[0]->value;
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
    protected function validateRequestContentType(ServerRequestInterface $request, bool $required = false): void
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if ($required && $contentType === '') {
            throw new UnsupportedMediaTypeException();
        }
        if ($required && !in_array($contentType, array_column($this->allowedRequestContentTypes, 'value'))) {
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
        /** @var array<string,scalar|null> $filteredQueryParams */
        $filteredQueryParams = array_filter(
            $request->getQueryParams(),
            fn($value): bool => ( is_scalar($value) || $value === null )
        );
        return $filteredQueryParams;
    }


    /**
     * Parse the request body according to the request Content-Type,
     * and return the parsed parameters with only scalar values.
     *
     * @return array<string,scalar|null>
     */
    protected function parseBodyParams(ServerRequestInterface $request, bool $required = false): ?array
    {
        $this->validateRequestContentType($request, $required);

        // We parse the body according to the request Content-Type
        $mime = RequestContentType::tryFrom($request->getHeaderLine('Content-Type')) ?? null;
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
                $parsedBody = json_decode($rawBodyContents, true, 512, JSON_THROW_ON_ERROR);
                break;
            case RequestContentType::YAML:
                try {
                    $parsedBody = Yaml::parse($rawBodyContents);
                } catch (ParseException $e) {
                    throw new YamlException($e->getMessage(), StatusCode::UNPROCESSABLE_CONTENT->value, $e);
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
        if (false === is_array($parsedBody) || empty($parsedBody)) {
            if ($required) {
                throw new ValidationException();
            } else {
                // silently discard the parsed body content
                return null;
            }
        }

        /** @var array<string,scalar|null> $parsedBodyWithOnlyScalarValues */
        $parsedBodyWithOnlyScalarValues = array_filter(
            $parsedBody,
            function ($value): bool {
                return ( is_scalar($value) || $value === null );
            }
        );
        return $parsedBodyWithOnlyScalarValues;
    }

    /**
     * Parse the request body according to the request Content-Type,
     * and return the parsed payload.
     *
     * @param bool $assoc If true, the payload will be returned as an associative array, otherwise as a `\stdClass` object
     *
     * @return array<string|int,mixed>|\stdClass
     */
    protected function parseBodyPayload(ServerRequestInterface $request, bool $assoc = true): array|\stdClass
    {
        $this->validateRequestContentType($request, true);

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
                if ($assoc) {
                    if (false === is_array($parsedBody) || empty($parsedBody)) {
                        throw new ValidationException('Invalid body content received in the request: expected an associative array');
                    }
                    /** @var array<string|int,mixed> $parsedBody */
                } else {
                    if (is_array($parsedBody)) {
                        if (empty($parsedBody) || !( $parsedBody[0] instanceof \stdClass )) {
                            throw new ValidationException('Invalid body content received in the request: expected an array of objects');
                        }
                        /** @var list<\stdClass> $parsedBody */
                    } elseif (!( $parsedBody instanceof \stdClass )) {
                        throw new ValidationException('Invalid body content received in the request: expected an object');
                    }
                    /** @var list<\stdClass>|\stdClass $parsedBody */
                }
                break;
            case RequestContentType::YAML:
                try {
                    $parsedBody = Yaml::parse($rawBodyContents);
                    if (false === is_array($parsedBody) || empty($parsedBody)) {
                        throw new YamlException('Invalid body content received in the request: expected an array', StatusCode::UNPROCESSABLE_CONTENT->value);
                    }
                    /** @var array<string|int,mixed> $parsedBody */
                    if (false === $assoc) {
                        $encodedJson = json_encode($parsedBody, JSON_THROW_ON_ERROR);
                        $parsedBody  = json_decode($encodedJson, false, 512, JSON_THROW_ON_ERROR);
                        if (is_array($parsedBody)) {
                            if (empty($parsedBody) || !( $parsedBody[0] instanceof \stdClass )) {
                                throw new YamlException('Invalid body content received in the request: expected an array of objects', StatusCode::UNPROCESSABLE_CONTENT->value);
                            }
                            /** @var list<\stdClass> $parsedBody */
                        } elseif (!( $parsedBody instanceof \stdClass )) {
                            throw new YamlException('Invalid body content received in the request: expected an object', StatusCode::UNPROCESSABLE_CONTENT->value);
                        }
                        /** @var list<\stdClass>|\stdClass $parsedBody */
                    }
                } catch (ParseException | \JsonException $e) {
                    throw new YamlException($e->getMessage(), StatusCode::UNPROCESSABLE_CONTENT->value, $e);
                }
                break;
            default:
                throw new UnsupportedMediaTypeException();
        }

        /** @var array<string|int,mixed>|\stdClass $parsedBody */
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
    protected function encodeResponseBody(ResponseInterface $response, array|\stdClass|MissalMetadataMap|DecreeItem $responseBody, StatusCode $statusCode = StatusCode::OK): ResponseInterface
    {
        $contentType = AcceptHeader::from($response->getHeaderLine('Content-Type'));
        switch ($contentType) {
            case AcceptHeader::JSON:
                $encodedResponse = json_encode($responseBody, JSON_THROW_ON_ERROR);
                break;
            case AcceptHeader::YAML:
                // In order to emit YAML, we need to recast the response body as an array
                // So first we encode the object as JSON
                $jsonEncodedResponse = json_encode($responseBody, JSON_THROW_ON_ERROR);
                $recodedResponse     = json_decode($jsonEncodedResponse, true, 512, JSON_THROW_ON_ERROR);

                // Then we attempt to encode the array as YAML
                try {
                    $encodedResponse = Yaml::dump($recodedResponse, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
                } catch (DumpException $e) {
                    throw new YamlException($e->getMessage(), StatusCode::UNPROCESSABLE_CONTENT->value, $e);
                }
                break;
            default:
                $encodedResponse = json_encode($responseBody, JSON_THROW_ON_ERROR);
        }
        return $response
            ->withStatus($statusCode->value, $statusCode->reason())
            ->withBody(Stream::create($encodedResponse));
    }

    /**
     * Factory method for instantiating a Response object with minimum state.
     */
    protected static function initResponse(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(
            StatusCode::PROCESSING->value,   // uncertain status,
            [],                              // no headers,
            null,                            // no body;
            $request->getProtocolVersion(),  // and we always respond with the same HTTP protocol version used in the request.
            StatusCode::PROCESSING->reason() // The corresponding 'reason' that accompanies the HTTP Status code
        );
    }
}
