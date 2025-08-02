<?php

namespace LiturgicalCalendar\Api\Paths;

use LiturgicalCalendar\Api\Core;
use LiturgicalCalendar\Api\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\StatusCode;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemCollection;
use LiturgicalCalendar\Api\Utilities;
use stdClass;

final class DecreesPath
{
    public static Core $Core;

    public static DecreeItemCollection $decreesIndex;

    /** @var array<string|int> */
    private static array $requestPathParts = [];

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

    private static function handlePathParams(): void
    {
        $numPathParts = count(self::$requestPathParts);
        if ($numPathParts > 0) {
            switch ($numPathParts) {
                case 1:
                    $decree = array_find(self::$decreesIndex->decreeItems, fn ($decree) => $decree->decree_id === self::$requestPathParts[0]);
                    if (null === $decree) {
                        $decreeIDs = array_column(self::$decreesIndex->decreeItems, 'decree_id');
                        $error     = 'No Decree of the Congregation for Divine Worship found corresponding to '
                            . self::$requestPathParts[0]
                            . ', valid values are found in the `decree_id` properties of the `litcal_decrees` collection: ' . implode(', ', $decreeIDs);
                        self::produceErrorResponse(StatusCode::NOT_FOUND, $error);
                    } else {
                        $response = json_encode($decree);
                        if ($response === false) {
                            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, 'Failed to encode decree to JSON');
                        } else {
                            self::produceResponse($response);
                        }
                    }
                    break;
                default:
                    self::produceErrorResponse(StatusCode::BAD_REQUEST, "Only one path parameter expected on the `/decrees` path, instead $numPathParts found");
            }
        }
    }

    public static function produceErrorResponse(int $statusCode, string $description): void
    {
        header($_SERVER['SERVER_PROTOCOL'] . StatusCode::toString($statusCode), true, $statusCode);
        $message         = new \stdClass();
        $message->status = 'ERROR';
        $statusMessage   = '';
        switch (self::$Core->getRequestMethod()) {
            case RequestMethod::PUT:
                $statusMessage = 'Resource not Created';
                break;
            case RequestMethod::PATCH:
                $statusMessage = 'Resource not Updated';
                break;
            case RequestMethod::DELETE:
                $statusMessage = 'Resource not Deleted';
                break;
            default:
                $statusMessage = 'Resource not found';
        }
        $message->response    = $statusCode === 404 ? 'Resource not Found' : $statusMessage;
        $message->description = $description;
        $response             = json_encode($message);
        if ($response === false) {
            $response = '{"status":"ERROR","response":"Internal Server Error","description":"Failed to encode error message to JSON"}';
        }
        switch (self::$Core->getResponseContentType()) {
            case AcceptHeader::YAML:
                $responseObj = json_decode($response, true);
                echo yaml_emit($responseObj, YAML_UTF8_ENCODING);
                break;
            case AcceptHeader::JSON:
            default:
                echo $response;
        }
        die();
    }

    private static function produceResponse(string $jsonEncodedResponse): void
    {
        if (in_array(self::$Core->getRequestMethod(), [RequestMethod::PUT, RequestMethod::PATCH])) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 201 Created', true, 201);
        }
        switch (self::$Core->getResponseContentType()) {
            case AcceptHeader::YAML:
                $responseObj = json_decode($jsonEncodedResponse, true);
                echo yaml_emit($responseObj, YAML_UTF8_ENCODING);
                break;
            case AcceptHeader::JSON:
            default:
                echo $jsonEncodedResponse;
        }
        die();
    }

    /**
     * Initializes the Decrees class.
     *
     * This method performs the following actions:
     * - Sets the request path parts if provided.
     * - Loads the decrees data from the decrees file and initializes the decrees index.
     * - Appends API path to each decree in the decrees index.
     * - Initializes the Core component.
     *
     * @param array<string|int> $requestPathParts The path parameters from the request.
     *
     */
    public static function init(array $requestPathParts = []): void
    {
        if (count($requestPathParts)) {
            self::$requestPathParts = $requestPathParts;
        }

        $locale = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']) : LitLocale::LATIN_PRIMARY_LANGUAGE;
        if (false === $locale) {
            $locale = LitLocale::LATIN_PRIMARY_LANGUAGE;
        } else {
            $locale = \Locale::getPrimaryLanguage($locale);
        }
        $decreesI18nFile = strtr(
            JsonData::DECREES_I18N_FILE,
            ['{locale}' => $locale]
        );

        $names   = Utilities::jsonFileToArray($decreesI18nFile);
        $decrees = Utilities::jsonFileToObject(JsonData::DECREES_FILE);
        if (false === is_array($decrees)) {
            throw new \Exception('We expected the Decrees data to be an array of Decree objects.');
        }
        if (array_filter(array_keys($names), 'is_string') !== array_keys($names)) {
            throw new \Exception('We expected all the keys of the array to be strings.');
        }
        if (array_filter($names, 'is_string') !== $names) {
            throw new \Exception('We expected all the values of the array to be strings.');
        }
        /** @var array<string,string> $names */
        DecreeItemCollection::setNames($decrees, $names);

        self::$decreesIndex = DecreeItemCollection::fromObject($decrees);
        self::$Core         = new Core();
    }

    public static function handleRequest(): void
    {
        self::$Core->init();
        if (self::$Core->getRequestMethod() === RequestMethod::GET) {
            self::$Core->validateAcceptHeader(true);
        } else {
            self::$Core->validateAcceptHeader(false);
        }
        self::$Core->setResponseContentTypeHeader();
        if (count(self::$requestPathParts) === 0) {
            $decreesIndex                 = new \stdClass();
            $decreesIndex->litcal_decrees = self::$decreesIndex->decreeItems;
            $response                     = json_encode($decreesIndex);
            if ($response === false) {
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, 'Failed to encode decrees index to JSON');
            } else {
                self::produceResponse($response);
            }
        }
        self::handlePathParams();
    }
}
