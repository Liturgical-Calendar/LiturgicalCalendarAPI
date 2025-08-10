<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\LiturgicalEventMetadata;
use LiturgicalCalendar\Api\Models\RegionalData\UrlLangMap;

final class LitCalItemMakePatronMetadata extends LiturgicalEventMetadata
{
    public readonly CalEventAction $action;

    public readonly ?string $url;

    public readonly ?UrlLangMap $url_lang_map;

    private function __construct(int $since_year, ?int $until_year, ?string $url, ?UrlLangMap $url_lang_map)
    {
        parent::__construct($since_year, $until_year ?? null);

        $this->action       = CalEventAction::MakePatron;
        $this->url          = $url;
        $this->url_lang_map = $url_lang_map;
    }

    /**
     * Returns an HTML string representing the decree source,
     * with a link to the original decree document.
     *
     * If the decree URL contains a language placeholder, it is replaced with the
     * best language code available from the language map.
     *
     * @param string $lang The ISO 639-1 language code
     *
     * @return string The HTML string representing the decree source
     */
    public function getUrl(string $lang): string
    {
        if (
            null !== $this->url_lang_map
            && null !== $this->url
            && str_contains($this->url, '%s')
        ) {
            $vaticanLangCode = $this->url_lang_map->getBestLangFromMap($lang);
            $url             = sprintf($this->url, $vaticanLangCode);
        } else {
            $url = $this->url;
        }
        return '<a href="' . $url . '" target="_blank">' . _('Decree of the Congregation for Divine Worship') . '</a>';
    }

    /**
     * Creates a new instance from an associative array.
     *
     * @param \stdClass&object{since_year:int,url?:string,url_lang_map?:\stdClass&object<string,string>,until_year?:int} $data The data to use to create the new instance.
     *
     * @throws \ValueError If `metadata.since_year` parameter is missing.
     * @throws \ValueError If `data.url` parameter is not a string or is not a valid url.
     * @return static The new instance(s).
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'since_year')) {
            throw new \ValueError('`metadata.since_year` parameter is required');
        }

        $url = null;
        if (property_exists($data, 'url')) {
            if (false === is_string($data->url)) {
                throw new \ValueError('`metadata.url` parameter must be a string');
            }
            $url = filter_var($data->url, FILTER_SANITIZE_URL);
            if (false === $url) {
                throw new \ValueError('`metadata.url` parameter is not a valid URL');
            }
            $url = htmlspecialchars($url, ENT_QUOTES);
        }

        $url_lang_map = null;
        if (isset($data->url_lang_map)) {
            $url_lang_map = UrlLangMap::fromObject($data->url_lang_map);
        }

        return new static(
            $data->since_year,
            $data->until_year ?? null,
            $url,
            $url_lang_map
        );
    }

    /**
     * Creates a new instance from an associative array.
     *
     * @param array{since_year:int,url?:string,url_lang_map?:array<string,string>,until_year?:int} $data The data to use to create the new instance.
     *                      Must have the following key:
     *                          - `since_year`: The year since when the liturgical event was added.
     *                      May have the following keys:
     *                          - `url`: The URL of the decree document.
     *                          - `url_lang_map`: The language map for the decree URL.
     *                          - `until_year`: The year until when the liturgical event was added.
     * @return static A new instance.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('since_year', $data)) {
            throw new \ValueError('`metadata.since_year` parameter is required');
        }

        $url = null;
        if (array_key_exists('url', $data)) {
            if (false === is_string($data['url'])) {
                throw new \ValueError('`metadata.url` parameter must be a string');
            }
            $url = filter_var($data['url'], FILTER_SANITIZE_URL);
            if (false === $url) {
                throw new \ValueError('`metadata.url` parameter is not a valid URL');
            }
            $url = htmlspecialchars($url, ENT_QUOTES);
        }

        $url_lang_map = null;
        if (array_key_exists('url_lang_map', $data)) {
            $url_lang_map = UrlLangMap::fromArray($data['url_lang_map']);
        }

        return new static(
            $data['since_year'],
            $data['until_year'] ?? null,
            $url,
            $url_lang_map
        );
    }
}
