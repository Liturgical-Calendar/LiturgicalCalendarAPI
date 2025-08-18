<?php

namespace LiturgicalCalendar\Api\Params;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;

/**
 * Class DecreesParams
 *
 * This class handles the parameters for the Decrees API endpoint.
 * It validates and sets the locale parameter.
 *
 * @package LiturgicalCalendar\Api\Params
 */
class DecreesParams implements ParamsInterface
{
    public ?string $Locale = null;
    public \stdClass $Payload;

    /**
     * Constructor for DecreesParams
     *
     * Initializes the DecreesParams object and sets its parameters.
     *
     * @param array{locale?:string,payload?:\stdClass} $params An associative array of parameter keys to values, where
     *                      'locale' is the key to set the language in which the Decrees should be retrieved.
     */
    public function __construct(array $params)
    {
        $this->setParams($params);
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
    public function setParams($params): void
    {
        if (count($params) === 0) {
            // If no parameters are provided, we can just return
            return;
        }

        foreach ($params as $key => $value) {
            switch ($key) {
                case 'locale':
                    $value = \Locale::canonicalize($value);
                    if (null === $value) {
                        $description = "Invalid locale `{$value}`";
                        throw new ValidationException($description);
                    }

                    if (LitLocale::isValid($value)) {
                        $this->Locale = \Locale::getPrimaryLanguage($value);
                    } else {
                        $description = "Invalid value `$value` for param `locale`, valid values are: la, la_VA, "
                            . implode(', ', LitLocale::$AllAvailableLocales);
                        throw new ValidationException($description);
                    }
                    break;
                case 'payload':
                    $this->Payload = $value;
                    break;
            }
        }
    }
}
