<?php

namespace LiturgicalCalendar\Api\Models\Decrees;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\RegionalData\UrlLangMap;

final class DecreeItemCreateNewMetadata extends DecreeEventMetadata
{
    private function __construct(int $since_year, string $url, ?UrlLangMap $url_lang_map)
    {
        parent::__construct($since_year, CalEventAction::CreateNew, $url, $url_lang_map);
    }

    /**
     * Creates an instance from a StdClass object.
     *
     * @param \stdClass $data The StdClass object(s) to create an instance from.
     * It (they) must have the following properties:
     * - since_year (int): The year since when the liturgical event was added.
     * - url (string): The URL of the liturgical event.
     *
     * Optional properties:
     * - url_lang_map (object): Maps ISO 639-1 language codes to Vatican website language codes.
     *
     * @return static A new instance created from the given data.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'since_year') || false === property_exists($data, 'url')) {
            throw new \ValueError('`since_year` and `url` parameters are required');
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
     * Creates an instance from an associative array.
     *
     * The array must have the following key:
     * - since_year (int): The year from which the Decree is enforced.
     * - url (string): The URL of the decree.
     *
     * Optional keys:
     * - url_lang_map (array): Maps ISO 639-1 language codes to Vatican website language codes.
     *
     * @param array{since_year:int,url:string,url_lang_map?:array<string,string>} $data The associative array containing the properties of the class.
     * @return static A new instance of the class.
     */
    protected static function fromArrayInternal(array $data): static
    {

        if (false === array_key_exists('since_year', $data) || false === array_key_exists('url', $data)) {
            throw new \ValueError('`since_year` and `url` parameters are required');
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
