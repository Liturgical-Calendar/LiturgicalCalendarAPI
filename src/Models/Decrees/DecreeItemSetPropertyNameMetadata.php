<?php

namespace LiturgicalCalendar\Api\Models\Decrees;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\RegionalData\UrlLangMap;

/**
 * @phpstan-import-type UrlLangMapObject from DecreeItem
 * @phpstan-type DecreeItemSetPropertyNameMetadataObject \stdClass&object{action:'setProperty',since_year:int,until_year?:int,url?:string,reason?:string,property:'name',url_lang_map?:UrlLangMapObject}
 * @phpstan-type DecreeItemSetPropertyNameMetadataArray array{action:'setProperty',since_year:int,until_year?:int,url:string,reason?:string,property:'name',url_lang_map?:array<string,string>}
 */
final class DecreeItemSetPropertyNameMetadata extends DecreeEventMetadata
{
    public readonly string $property;

    private function __construct(int $since_year, string $url, ?UrlLangMap $url_lang_map)
    {
        parent::__construct($since_year, CalEventAction::SetProperty, $url, $url_lang_map);
        $this->property = 'name';
    }

    /**
     * Create a new instance of the class from an object containing the required properties.
     *
     * @param DecreeItemSetPropertyNameMetadataObject $data
     *     The object containing the required properties.
     *
     * @return static The newly created instance of the class.
     *
     * @throws \ValueError
     *     If the object does not contain the required properties.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (
            false === isset($data->since_year)
            || false === isset($data->url)
            || false === isset($data->property)
            || $data->property !== 'name'
        ) {
            throw new \ValueError('`since_year` and `property` parameters are required, and `property` must have a value of `name`');
        }

        $url_lang_map = null;
        if (isset($data->url_lang_map)) {
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
     * - property (string): The property to be set. Must have a value of 'name'.
     *
     * @param DecreeItemSetPropertyNameMetadataArray $data
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
            false === isset($data['since_year'])
            || false === isset($data['url'])
            || false === isset($data['property'])
            || $data['property'] !== 'name'
        ) {
            throw new \ValueError('`since_year` and `property` parameters are required, and `property` must have a value of `name`');
        }

        $url_lang_map = null;
        if (isset($data['url_lang_map'])) {
            $url_lang_map = UrlLangMap::fromArray($data['url_lang_map']);
        }

        return new static(
            $data['since_year'],
            $data['url'],
            $url_lang_map
        );
    }
}
