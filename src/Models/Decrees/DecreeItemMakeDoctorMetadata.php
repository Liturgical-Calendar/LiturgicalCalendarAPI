<?php

namespace LiturgicalCalendar\Api\Models\Decrees;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\RegionalData\UrlLangMap;

final class DecreeItemMakeDoctorMetadata extends DecreeEventMetadata
{
    private function __construct(int $since_year, string $url, ?UrlLangMap $url_lang_map)
    {
        parent::__construct($since_year, CalEventAction::MakeDoctor, $url, $url_lang_map);
    }


    /**
     * Creates a new instance from an associative array.
     *
     * @param \stdClass&object{since_year:int,url?:string,url_lang_map?:\stdClass&object<string,string>} $data The data to use to create the new instance.
     * @return static The new instance.
     * @throws \ValueError If `metadata.since_year` or `metadata.url` parameters are missing.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'since_year') || false === property_exists($data, 'url')) {
            throw new \ValueError('`metadata.since_year` and `metadata.url` parameters are required');
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
     * Creates a new instance from an associative array.
     *
     * @param array{since_year:int,url?:string,url_lang_map?:array<string,string>} $data The data to use to create the new instance.
     *                      Must have the following key:
     *                          - `since_year`: The year from which the Decree is enforced.
     *                          - `url`: The URL of the decree document.
     *                      May have the following keys:
     *                          - `url_lang_map`: Maps ISO 639-1 language codes to Vatican website language codes.
     * @return static A new instance.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('since_year', $data) || false === array_key_exists('url', $data)) {
            throw new \ValueError('`metadata.since_year` and `metadata.url` parameters are required');
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
