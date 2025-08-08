<?php

namespace LiturgicalCalendar\Api\Models\Decrees;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\RegionalData\UrlLangMap;

final class DecreeItemSetPropertyGradeMetadata extends DecreeEventMetadata
{
    public readonly string $property;

    private function __construct(int $since_year, string $url, ?UrlLangMap $url_lang_map)
    {
        parent::__construct($since_year, CalEventAction::SetProperty, $url, $url_lang_map);
        $this->property = 'grade';
    }

    /**
     * Creates an instance of LitCalItemSetPropertyGradeMetadata from an object containing the required properties.
     *
     * The stdClass object must have the following properties:
     * - since_year (int): The year since when the metadata is applied.
     * - property (string): The property to be set. Must have a value of 'grade'.
     * - url (string|null): The URL associated with the metadata. It will be sanitized.
     *
     * Optional property:
     * - url_lang_map (object): Maps ISO 639-1 language codes to Vatican website language codes.
     *
     * @param \stdClass&object{since_year:int,url:string,url_lang_map?:\stdClass&object<string,string>} $data The stdClass object containing the properties of the class.
     * @return static The newly created instance(s).
     * @throws \ValueError if the required properties are not present in the stdClass object or if the properties have invalid types.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (
            false === property_exists($data, 'since_year')
            || false === property_exists($data, 'url')
            || false === property_exists($data, 'property')
            || $data->property !== 'grade'
        ) {
            throw new \ValueError('`since_year` and `property` parameters are required for an `action` of `setProperty`, and `property` must have a value of `grade`');
        }

        $url_lang_map = null;
        if (property_exists($data, 'url_lang_map')) {
            $url_lang_map = UrlLangMap::fromObject($data->url_lang_map);
        }

        return new static(
            $data->since_year,
            $data->url,
            $url_lang_map
        );
    }

    /**
     * Creates an instance of the class from an associative array.
     *
     * The array must have the following keys:
     * - since_year (int): The year since when the metadata is applied.
     * - url (string): The URL of the Decree.
     * - property (string): The property to be set. Must have a value of 'grade'.
     *
     * Optional keys:
     * - url_lang_map (array): Maps ISO 639-1 language codes to Vatican website language codes.
     *
     * @param array{since_year:int,url:string,url_lang_map?:array<string,string>} $data
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
        if (
            false === array_key_exists('since_year', $data)
            || false === array_key_exists('url', $data)
            || false === array_key_exists('property', $data)
            || $data['property'] !== 'grade'
        ) {
            throw new \ValueError('`since_year` and `property` parameters are required for an `action` of `setProperty`, and `property` must have a value of `grade`');
        }

        $url_lang_map = null;
        if (array_key_exists('url_lang_map', $data)) {
            $url_lang_map = UrlLangMap::fromArray($data['url_lang_map']);
        }

        return new static(
            $data['since_year'],
            $data['url'],
            $url_lang_map
        );
    }
}
