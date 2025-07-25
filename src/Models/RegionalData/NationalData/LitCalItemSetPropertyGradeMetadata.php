<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\LiturgicalEventMetadata;

final class LitCalItemSetPropertyGradeMetadata extends LiturgicalEventMetadata
{
    public readonly CalEventAction $action;

    public readonly string $property;

    public readonly ?string $url;

    private function __construct(int $since_year, ?int $until_year = null, ?string $url = null)
    {
        parent::__construct($since_year, $until_year ?? null);
        $this->action   = CalEventAction::SetProperty;
        $this->property = 'grade';
        $this->url      = $url;
    }

    /**
     * Creates an instance of LitCalItemSetPropertyGradeMetadata from an object containing the required properties.
     *
     * The stdClass object must have the following properties:
     * - since_year (int): The year since when the metadata is applied.
     * - property (string): The property to be set. Must have a value of 'grade'.
     *
     * Optional property:
     * - until_year (int|null): The year until when the metadata is applied.
     * - url (string|null): The URL associated with the metadata. It will be sanitized.
     *
     * @param \stdClass $data The stdClass object containing the properties of the class.
     * @return static The newly created instance.
     * @throws \ValueError if the required properties are not present in the stdClass object or if the properties have invalid types.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'since_year') || false === property_exists($data, 'property') || $data->property !== 'grade') {
            throw new \ValueError('`since_year` and `property` parameters are required for an `action` of `setProperty`, and `property` must have a value of `grade`');
        }

        $url = null;
        if (property_exists($data, 'url')) {
            if (false === is_string($data->url)) {
                throw new \ValueError('`url` must be a string');
            }
            $url = filter_var($data->url, FILTER_SANITIZE_URL);
            if (false === $url) {
                throw new \ValueError('`url` must be a valid URL');
            }
            $url = htmlspecialchars($url, ENT_QUOTES);
        }

        return new static(
            $data->since_year,
            $data->until_year ?? null,
            $url
        );
    }

    /**
     * Creates an instance of the class from an associative array.
     *
     * The array must have the following keys:
     * - since_year (int): The year since when the metadata is applied.
     * - property (string): The property to be set. Must have a value of 'grade'.
     *
     * Optional keys:
     * - until_year (int|null): The year until when the metadata is applied.
     * - url (string|null): The URL associated with the metadata. It will be sanitized.
     *
     * @param array{since_year:int,until_year?:int,url?:string} $data
     *     The associative array containing the properties of the class.
     *
     * @return static
     *     A new instance of the class.
     *
     * @throws \ValueError
     *     If the required keys are not present in the array or have invalid values.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('since_year', $data) || false === array_key_exists('property', $data) || $data['property'] !== 'grade') {
            throw new \ValueError('`since_year` and `property` parameters are required for an `action` of `setProperty`, and `property` must have a value of `grade`');
        }

        $url = null;
        if (array_key_exists('url', $data)) {
            if (false === is_string($data['url'])) {
                throw new \ValueError('`url` must be a string');
            }
            $url = filter_var($data['url'], FILTER_SANITIZE_URL);
            if (false === $url) {
                throw new \ValueError('`url` must be a valid URL');
            }
            $url = htmlspecialchars($url, ENT_QUOTES);
        }

        return new static(
            $data['since_year'],
            $data['until_year'] ?? null,
            $url
        );
    }
}
