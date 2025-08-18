<?php

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Utilities;
use LiturgicalCalendar\Api\LatinUtils;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Params\EasterParams;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Nyholm\Psr7\Stream;

final class EasterHandler extends AbstractHandler
{
    public EasterParams $params;
    private static \IntlDateFormatter $dayOfTheWeekDayMonthYear;
    private static \IntlDateFormatter $dayMonthYear;
    private static \IntlDateFormatter $dayOfTheWeek;
    private static object $EasterDates;

    public function __construct()
    {
    }

    private function setLocale(): void
    {
        $localeArray = [
            $this->params->Locale . '.utf8',
            $this->params->Locale . '.UTF-8',
            $this->params->Locale,
            $this->params->baseLocale . '_' . strtoupper($this->params->baseLocale) . '.utf8',
            $this->params->baseLocale . '_' . strtoupper($this->params->baseLocale) . '.UTF-8',
            $this->params->baseLocale . '_' . strtoupper($this->params->baseLocale),
            $this->params->baseLocale . '.utf8',
            $this->params->baseLocale . '.UTF-8',
            $this->params->baseLocale
        ];
        setlocale(LC_ALL, $localeArray);

        $dayOfTheWeekDayMonthYear = \IntlDateFormatter::create(
            $this->params->Locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            'EEEE d MMMM yyyy'
        );
        $dayMonthYear             = \IntlDateFormatter::create(
            $this->params->Locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            'd MMMM yyyy'
        );
        $dayOfTheWeek             = \IntlDateFormatter::create(
            $this->params->Locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            'EEEE'
        );

        if (
            null === $dayOfTheWeekDayMonthYear
            || null === $dayMonthYear
            || null === $dayOfTheWeek
        ) {
            throw new \RuntimeException('"Fugit irreparabile tempus." — Virgil, Georgics, Book III');
        }

        self::$dayOfTheWeekDayMonthYear = $dayOfTheWeekDayMonthYear;
        self::$dayMonthYear             = $dayMonthYear;
        self::$dayOfTheWeek             = $dayOfTheWeek;
    }

    private function calculateEasterDates(): void
    {
        self::$EasterDates                = new \stdClass();
        self::$EasterDates->litcal_easter = [];
        $dateLastCoincidence              = null;
        $gregDateString                   = '';
        $julianDateString                 = '';
        $westernJulianDateString          = '';

        for ($i = 1583; $i <= 9999; $i++) {
            self::$EasterDates->litcal_easter[$i - 1583] = new \stdClass();
            $gregorian_easter                            = Utilities::calcGregEaster($i);
            $julian_easter                               = Utilities::calcJulianEaster($i);
            $western_julian_easter                       = Utilities::calcJulianEaster($i, true);
            $same_easter                                 = false;

            if ($gregorian_easter->format('l, F jS, Y') === $western_julian_easter->format('l, F jS, Y')) {
                $same_easter         = true;
                $dateLastCoincidence = $gregorian_easter;
            }

            switch ($this->params->baseLocale) {
                case LitLocale::LATIN_PRIMARY_LANGUAGE:
                    $month                   = (int) $gregorian_easter->format('n'); //n      = 1-January to 12-December
                    $monthLatin              = LatinUtils::LATIN_MONTHS[$month];
                    $gregDateString          = 'Dies Domini, ' . $gregorian_easter->format('j') . ' ' . $monthLatin . ' ' . $gregorian_easter->format('Y');
                    $month                   = (int) $julian_easter->format('n'); //n         = 1-January to 12-December
                    $monthLatin              = LatinUtils::LATIN_MONTHS[$month];
                    $julianDateString        = 'Dies Domini, ' . $julian_easter->format('j') . ' ' . $monthLatin . ' ' . $julian_easter->format('Y');
                    $month                   = (int) $western_julian_easter->format('n'); //n = 1-January to 12-December
                    $monthLatin              = LatinUtils::LATIN_MONTHS[$month];
                    $westernJulianDateString = 'Dies Domini, ' . $western_julian_easter->format('j') . ' ' . $monthLatin . ' ' . $western_julian_easter->format('Y');
                    break;
                case 'en':
                    $gregDateString          = $gregorian_easter->format('l, F jS, Y');
                    $julianDateString        = 'Sunday' . $julian_easter->format(', F jS, Y');
                    $westernJulianDateString = $western_julian_easter->format('l, F jS, Y');
                    break;
                default:
                    $gregDateString          = self::$dayOfTheWeekDayMonthYear->format($gregorian_easter->format('U'));
                    $julianDateString        = self::$dayOfTheWeek->format($gregorian_easter->format('U'))
                                                    . ', ' . self::$dayMonthYear->format($julian_easter->format('U'));
                    $westernJulianDateString = self::$dayOfTheWeekDayMonthYear->format($western_julian_easter->format('U'));
            }

            self::$EasterDates->litcal_easter[$i - 1583]->gregorianEaster         = (int) $gregorian_easter->format('U');
            self::$EasterDates->litcal_easter[$i - 1583]->julianEaster            = (int) $julian_easter->format('U');
            self::$EasterDates->litcal_easter[$i - 1583]->westernJulianEaster     = (int) $western_julian_easter->format('U');
            self::$EasterDates->litcal_easter[$i - 1583]->coinciding              = $same_easter;
            self::$EasterDates->litcal_easter[$i - 1583]->gregorianDateString     = $gregDateString;
            self::$EasterDates->litcal_easter[$i - 1583]->julianDateString        = $julianDateString;
            self::$EasterDates->litcal_easter[$i - 1583]->westernJulianDateString = $westernJulianDateString;
        }

        if (null === $dateLastCoincidence) {
            throw new \RuntimeException(
                'Although all events, even the most trifling, are disposed according to God’s plan, as we have shown,'
                . ' there is nothing to prevent some things from happening by chance or accident.'
                . ' An occurrence may be accidental or fortuitous with respect to a lower cause when an effect not intended is brought about,'
                . ' and yet not be accidental or fortuitous with respect to a higher cause, inasmuch as the effect does not take place apart from the latter’s intention.'
                . ' — St. Thomas Aquinas, Opuscula I Treatises Compendium Theologiæ, Book I On Faith, Chapter 137'
            );
        }
        self::$EasterDates->lastCoincidenceString = $dateLastCoincidence->format('l, F jS, Y');
        self::$EasterDates->lastCoincidence       = (int) $dateLastCoincidence->format('U');
    }

    /**
     * Initialize the EasterHandler process.
     *
     * This method performs the following steps:
     * 1. Enforces that the HTTP request method is allowed.
     * 2. Handles request parameters to set the appropriate locale.
     * 3. Serves cached Easter date data if available.
     * 4. Sets the locale for date formatting.
     * 5. Calculates the dates of Easter for the specified range.
     * 6. Produces a JSON response with the calculated data.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // We instantiate a Response object with minimum state
        $response = static::createResponse($request);

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

        $response           = $response->withHeader('Content-Type', $mime);
        $mimeWithoutCharset = explode(';', $mime)[0];
        $fileExtension      = strtolower(AcceptHeader::from($mimeWithoutCharset)->toReturnTypeParam()->value);

        // Initialize any parameters set in the request.
        // If there are any:
        //   - for a GET request method, we expect them to be set in the URL
        //   - for any other request methods, we expect them to be set in the body of the request
        // Considering that this endpoint is readonly:
        //   - for POST requests we will never have a payload in the request body,
        //       only request parameters

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
        }

        $this->params = new EasterParams($params);

        $cacheFile = 'engineCache/easter/' . $this->params->baseLocale . '.' . $fileExtension;
        if (file_exists($cacheFile)) {
            $bodyContents = Utilities::rawContentsFromFile($cacheFile);
            return $response
                ->withStatus(StatusCode::OK->value, StatusCode::OK->reason())
                ->withBody(Stream::create($bodyContents));
        }

        $this->setLocale();
        $this->calculateEasterDates();

        $response     = $this->encodeResponseBody($response, self::$EasterDates);
        $body         = $response->getBody();
        $contents     = $body->getContents();
        $responseHash = md5($contents);
        $response     = $response->withHeader('ETag', "\"{$responseHash}\"");

        if (!is_dir('engineCache/easter/')) {
            mkdir('engineCache/easter/', 0774, true);
        }

        if (false === file_put_contents($cacheFile, $contents)) {
            throw new ServiceUnavailableException('Failed to write cache file');
        }

        if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $responseHash) {
            return $response->withStatus(StatusCode::NOT_MODIFIED->value, StatusCode::NOT_MODIFIED->reason())
                            ->withHeader('Content-Length', '0');
        }

        return $response;
    }
}
