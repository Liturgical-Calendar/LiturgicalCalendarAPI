<?php

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemCollection;
use LiturgicalCalendar\Api\Params\DecreesParams;
use LiturgicalCalendar\Api\Utilities;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @phpstan-import-type DecreeItemFromObject from \LiturgicalCalendar\Api\Models\Decrees\DecreeItem
 */
final class DecreesHandler extends AbstractHandler
{
    public static DecreeItemCollection $decreesIndex;
    public DecreesParams $params;

    public function __construct(array $requestPathParams = [])
    {
        parent::__construct($requestPathParams);
    }

    /*
    private static function initRequestParams(): array
    {
        $data = [];
        if (in_array(self::$Core->getRequestMethod(), [RequestMethod::POST, RequestMethod::PUT, RequestMethod::PATCH])) {
            $payload = null;
            $required = in_array(self::$Core->getRequestMethod(), [RequestMethod::PUT, RequestMethod::PATCH]);
            switch (self::$Core->getRequestContentType()) {
                case RequestContentType::JSON:
                    $payload = self::$Core->readJsonBody($required);
                    break;
                case RequestContentType::YAML:
                    $payload = self::$Core->readYamlBody($required);
                    break;
                case RequestContentType::FORMDATA:
                    $payload = (object)$_POST;
                    break;
                default:
                    if (in_array(self::$Core->getRequestMethod(), [RequestMethod::PUT, RequestMethod::PATCH])) {
                        // the payload MUST be in the body of the request, either JSON encoded or YAML encoded
                        self::produceErrorResponse(StatusCode::BAD_REQUEST, "Decrees::initRequestParams: Expected payload in body of request, either JSON encoded, YAML encoded, or Form Data encoded");
                    }
            }
            if (self::$Core->getRequestMethod() === RequestMethod::POST) {
                if ($payload !== null && property_exists($payload, 'locale')) {
                    $data["LOCALE"] = $payload->locale;
                } else {
                    $data["LOCALE"] = LitLocale::LATIN;
                }
            } else {
                $data["PAYLOAD"] = $payload;
            }
        } elseif (self::$Core->getRequestMethod() === RequestMethod::GET) {
            $_GET = array_change_key_case($_GET, CASE_LOWER);
            if (isset($_GET['locale'])) {
                $data["LOCALE"] = $_GET['locale'];
            } else {
                $data["LOCALE"] = LitLocale::LATIN;
            }
        }
        return $data;
    }
    */

    /**
     * Handles the request for the Decrees endpoint.
     *
     * This function:
     *  - Validates the Accept header if the request method is GET.
     *  - Sets the response content type header.
     *  - Encodes the decrees index to JSON and outputs the response if the request path is empty.
     *  - Otherwise, handles the path parameters.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // We instantiate a Response object with minimum state
        $response = static::initResponse($request);

        $method = RequestMethod::from($request->getMethod());

        // OPTIONS method for CORS preflight requests is always allowed
        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        }

        // For all other request methods, validate that they are supported by the endpoint
        $this->validateRequestMethod($request);

        // First of all we validate that the Content-Type requested in the Accept header is supported by the endpoint:
        //   if set we negotiate the best Content-Type, if not set we default to the first supported by the current handler
        switch ($method) {
            case RequestMethod::GET:
                $mime = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
                break;
            default:
                $mime = $this->validateAcceptHeader($request, AcceptabilityLevel::INTERMEDIATE);
        }

        $response = $response->withHeader('Content-Type', $mime);

        // Initialize any parameters set in the request.
        // If there are any:
        //   - for a GET request method, we expect them to be set in the URL
        //   - for any other request methods, we expect them to be set in the body of the request
        // Considering that this endpoint is both read and write:
        //   - for POST requests we will never have a payload in the request body,
        //       only request parameters
        //   - for PUT and PATCH requests we will have a payload in the request body
        //   - for DELETE requests we will have neither payload nor request parameters, only path parameters

        /** @var array{locale?:string}|array{PAYLOAD:\stdClass} $params */
        $params = [];

        // Second of all, we check if an Accept-Language header was set in the request
        $acceptLanguageHeader = $request->getHeaderLine('Accept-Language');
        $locale               = '' !== $acceptLanguageHeader
            ? \Locale::acceptFromHttp($acceptLanguageHeader)
            : LitLocale::LATIN;
        if ($locale && LitLocale::isValid($locale)) {
            $params['locale'] = $locale;
        } else {
            $params['locale'] = LitLocale::LATIN;
        }

        if ($method === RequestMethod::GET) {
            $params = array_merge($params, $this->getScalarQueryParams($request));
        } elseif ($method === RequestMethod::POST) {
            $parsedBodyParams = $this->parseBodyParams($request, false);

            if (null !== $parsedBodyParams) {
                /** @var array<string,scalar|null> $params */
                $params = array_merge($params, $parsedBodyParams);
            }
        } elseif ($method === RequestMethod::PUT || $method === RequestMethod::PATCH) {
            $params['payload'] = $this->parseBodyPayload($request);
        }

        $this->params = new DecreesParams($params);

        switch ($method) {
            case RequestMethod::GET:
                // no break (intentional fallthrough)
            case RequestMethod::POST:
                return $this->handleGetRequest($response);
                // no break needed
            case RequestMethod::PUT:
                return $this->handlePutRequest($response);
            case RequestMethod::PATCH:
                return $this->handlePatchRequest($response);
            case RequestMethod::DELETE:
                return $this->handleDeleteRequest($response);
            default:
                throw new MethodNotAllowedException();
        }
    }

    private function handleGetRequest(ResponseInterface $response): ResponseInterface
    {
        $decreesI18nFile = strtr(
            JsonData::DECREES_I18N_FILE->path(),
            ['{locale}' => $this->params->Locale]
        );

        /** @var DecreeItemFromObject[] $decrees */
        $decrees = Utilities::jsonFileToObjectArray(JsonData::DECREES_FILE->path());
        $names   = Utilities::jsonFileToArray($decreesI18nFile);
        if (array_filter(array_keys($names), 'is_string') !== array_keys($names)) {
            $description = Stream::create("DecreesHandler: We expected all the keys of the i18n array from file {$decreesI18nFile} to be strings.");
            return $response
                ->withStatus(StatusCode::SERVICE_UNAVAILABLE->value, StatusCode::SERVICE_UNAVAILABLE->reason())
                ->withBody($description);
        }

        if (array_filter($names, 'is_string') !== $names) {
            $description = Stream::create("DecreesHandler: We expected all the values of the i18n array from file {$decreesI18nFile} to be strings.");
            return $response
                ->withStatus(StatusCode::SERVICE_UNAVAILABLE->value, StatusCode::SERVICE_UNAVAILABLE->reason())
                ->withBody($description);
        }

        /** @var array<string,string> $names */
        DecreeItemCollection::setNames($decrees, $names);

        self::$decreesIndex = DecreeItemCollection::fromObject($decrees);

        $countPathParams = count($this->requestPathParams);
        if ($countPathParams === 0) {
            $decreesIndex                 = new \stdClass();
            $decreesIndex->litcal_decrees = self::$decreesIndex->decreeItems;
            return $this->encodeResponseBody($response, $decreesIndex);
        } elseif ($countPathParams > 1) {
            throw new ValidationException('Only one path parameter expected on the `/decrees` path, instead ' . $countPathParams . ' found');
        } else {
            $decreeId = $this->requestPathParams[0];
            $decree   = array_find(self::$decreesIndex->decreeItems, fn ($decree) => $decree->decree_id === $decreeId);
            if (null === $decree) {
                $decreeIDs = array_column(self::$decreesIndex->decreeItems, 'decree_id');
                $error     = 'No Decree of the Congregation for Divine Worship found corresponding to '
                    . $decreeId
                    . ', valid values are found in the `decree_id` properties of the `litcal_decrees` collection: ' . implode(', ', $decreeIDs);
                throw new NotFoundException($error);
            }
            return $this->encodeResponseBody($response, $decree);
        }
    }

    private function handlePutRequest(ResponseInterface $response): ResponseInterface
    {
        // TODO: implement creation of a Decree resource
        return $response
            ->withStatus(StatusCode::METHOD_NOT_ALLOWED->value, StatusCode::METHOD_NOT_ALLOWED->reason());
    }

    private function handlePatchRequest(ResponseInterface $response): ResponseInterface
    {
        // TODO: implement updating of a Decree resource
        return $response
            ->withStatus(StatusCode::METHOD_NOT_ALLOWED->value, StatusCode::METHOD_NOT_ALLOWED->reason());
    }

    private function handleDeleteRequest(ResponseInterface $response): ResponseInterface
    {
        // TODO: implement deletion of a Decree resource
        return $response
            ->withStatus(StatusCode::METHOD_NOT_ALLOWED->value, StatusCode::METHOD_NOT_ALLOWED->reason());
    }
}
