<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\RegionalData\LiturgicalEventMetadata;
use LiturgicalCalendar\Api\Models\RegionalData\UrlLangMap;

final class LitCalItemMakePatronMetadata extends LiturgicalEventMetadata
{
    public readonly CalEventAction $action;

    public readonly ?string $url;

    public readonly ?UrlLangMap $url_lang_map;

    public function __construct(\stdClass $metadata)
    {
        if (false === property_exists($metadata, 'since_year')) {
            throw new \ValueError('`metadata.since_year` parameter is required');
        }

        parent::__construct($metadata->since_year, $metadata->until_year ?? null);

        $this->action = CalEventAction::MakePatron;

        if (property_exists($metadata, 'url')) {
            if (false === is_string($metadata->url)) {
                throw new \ValueError('`metadata.url` parameter must be a string');
            }
            $url       = filter_var($metadata->url, FILTER_SANITIZE_URL);
            $url       = htmlspecialchars($url, ENT_QUOTES);
            $this->url = $url;
        } else {
            $this->url = null;
        }

        if (property_exists($metadata, 'url_lang_map')) {
            $this->url_lang_map = UrlLangMap::fromObject($metadata->url_lang_map);
        } else {
            $this->url_lang_map = null;
        }
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
        if (null !== $this->url_lang_map && str_contains($this->url, '%s')) {
            $vaticanLangCode = $this->url_lang_map->getBestLangFromMap($lang);
            $url             = sprintf($this->url, $vaticanLangCode);
        } else {
            $url = $this->url;
        }
        return '<a href="' . $url . '" target="_blank">' . _('Decree of the Congregation for Divine Worship') . '</a>';
    }
}
