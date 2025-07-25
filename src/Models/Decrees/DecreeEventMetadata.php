<?php

namespace LiturgicalCalendar\Api\Models\Decrees;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;
use LiturgicalCalendar\Api\Models\RegionalData\UrlLangMap;

abstract class DecreeEventMetadata extends AbstractJsonSrcData
{
    public readonly int $since_year;

    public readonly string $url;

    public readonly ?UrlLangMap $url_lang_map;

    public readonly CalEventAction $action;

    protected function __construct(int $since_year, CalEventAction $action, string $url, ?UrlLangMap $url_lang_map = null)
    {
        if ($since_year < 1800) {
            throw new \ValueError('$since_year parameter must represent a year from the 19th century or later');
        }

        $url = filter_var($url, FILTER_SANITIZE_URL);
        if (false === $url) {
            throw new \ValueError('`url` must be a valid URL');
        }
        $url = htmlspecialchars($url, ENT_QUOTES);

        $this->since_year   = $since_year;
        $this->action       = $action;
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
     * @return string The HTML string representing the decree source
     */
    public function getUrl(): string
    {
        $url = $this->url;
        if (null !== $this->url_lang_map && str_contains($this->url, '%s')) {
            $vaticanLangCode = $this->url_lang_map->getBestLangFromMap(LitLocale::$PRIMARY_LANGUAGE);
            $url             = sprintf($this->url, $vaticanLangCode);
        }
        return '<a href="' . $url . '" target="_blank">' . _('Decree of the Congregation for Divine Worship') . '</a>';
    }

    abstract protected static function fromArrayInternal(array $data): static;

    abstract protected static function fromObjectInternal(\stdClass $data): static;
}
