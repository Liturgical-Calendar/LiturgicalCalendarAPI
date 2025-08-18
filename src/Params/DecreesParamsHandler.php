<?php

namespace LiturgicalCalendar\Api\Params;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Stream;

/**
 * Class DecreesParams
 *
 * This class handles the parameters for the Decrees API endpoint.
 * It validates and sets the locale parameter.
 *
 * @package LiturgicalCalendar\Api\Params
 */
class DecreesParamsHandler implements RequestHandlerInterface
{
    /**
     * @var array{locale?:string,payload?:\stdClass}
     */
    private array $params;
    public ResponseInterface $response;
    public ?string $Locale = null;
    public \stdClass $Payload;

    /**
     * Constructor for DecreesParams
     *
     * Initializes the DecreesParams object and sets its parameters.
     *
     * @param array{locale?:string} $params An associative array of parameter keys to values, where
     *                      'locale' is the key to set the language in which the Decrees should be retrieved.
     */
    public function __construct(ResponseInterface $response, array $params = [])
    {
        $this->response = $response;
        $this->params   = $params;
    }

    /**
     * Sets the parameters for the Decrees class using the provided associative array of values.
     *
     * The array keys can be any of the following:
     * - locale: the language in which the decrees should be retrieved.
     *
     * All parameters are optional, and default values will be used if they are not provided.
     * @param array{locale?:string,payload?:\stdClass} $params an associative array of parameter keys to values
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (count($this->params) === 0) {
            // If no parameters are provided, we can just return
            return $this->response;
        }

        foreach ($this->params as $key => $value) {
            switch ($key) {
                case 'locale':
                    $value = \Locale::canonicalize($value);
                    if (null === $value) {
                        $description = "Invalid locale `{$value}`";
                        return $this->response
                            ->withStatus(StatusCode::BAD_REQUEST->value, StatusCode::BAD_REQUEST->reason())
                            ->withBody(Stream::create($description));
                    }

                    if (LitLocale::isValid($value)) {
                        $this->Locale = \Locale::getPrimaryLanguage($value);
                    } else {
                        $description = "Invalid value `$value` for param `locale`, valid values are: la, la_VA, "
                            . implode(', ', LitLocale::$AllAvailableLocales);
                        return $this->response
                            ->withStatus(StatusCode::BAD_REQUEST->value, StatusCode::BAD_REQUEST->reason())
                            ->withBody(Stream::create($description));
                    }
                    break;
                case 'payload':
                    $this->Payload = $value;
                    break;
            }
        }

        return $this->response;
    }
}
