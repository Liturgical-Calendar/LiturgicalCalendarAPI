<?php

namespace LiturgicalCalendar\Api\Models\Decrees;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;
use LiturgicalCalendar\Api\Models\RegionalData\UrlLangMap;

abstract class DecreeEventMetadata extends AbstractJsonRepresentation
{
    public readonly int $since_year;

    public readonly string $url;

    public readonly ?UrlLangMap $url_lang_map;

    /**
     * Explicit per-language URLs, overriding the `url` + `url_lang_map` template for the
     * languages they name. Sparse: a language absent here still resolves via the template.
     */
    public readonly ?UrlsLangs $urls_langs;

    public readonly CalEventAction $action;

    protected function __construct(int $since_year, CalEventAction $action, string $url, ?UrlLangMap $url_lang_map = null, ?UrlsLangs $urls_langs = null)
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
        $this->urls_langs   = $urls_langs;
    }

    /**
     * Returns an HTML string representing the decree source,
     * with a link to the original decree document.
     *
     * Resolution order for the current language:
     *  1. an explicit `urls_langs` override, used verbatim;
     *  2. otherwise, the `url` template with the `%s` placeholder replaced by the best
     *     language token from `url_lang_map`;
     *  3. otherwise, the `url` as given.
     *
     * The override wins because it exists precisely for the languages the template cannot
     * express — a Vatican document whose path or filename differs from every other language.
     *
     * @return string The HTML string representing the decree source
     */
    public function getUrl(): string
    {
        $url      = $this->url;
        $override = $this->urls_langs?->get(LitLocale::$PRIMARY_LANGUAGE);
        if (null !== $override) {
            $url = $override;
        } elseif (null !== $this->url_lang_map && str_contains($this->url, '%s')) {
            $vaticanLangCode = $this->url_lang_map->getBestLangFromMap(LitLocale::$PRIMARY_LANGUAGE);
            $url             = sprintf($this->url, $vaticanLangCode);
        }

        // Escape the final value (i.e. after any sprintf interpolation) at the point where it becomes markup.
        // `double_encode: false` keeps this idempotent with the escaping already applied in the constructor.
        $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8', false);

        return '<a href="' . $url . '" target="_blank">' . _('Decree of the Dicastery for Divine Worship and the Discipline of the Sacraments') . '</a>';
    }

    /**
     * Returns an associative array representing the object.
     *
     * @return array<string,string|int|array<string,string>> The associative array containing the properties of the object.
     */
    public function jsonSerialize(): array
    {
        $returnArray = [
            'since_year' => $this->since_year,
            'action'     => $this->action->value,
            'url'        => $this->url
        ];
        if (null !== $this->url_lang_map && !empty($this->url_lang_map->url_lang_map)) {
            $returnArray['url_lang_map'] = $this->url_lang_map->url_lang_map;
        }
        // Exposed, unlike before: an override is not derivable from url + url_lang_map,
        // so a client that builds per-language links needs to be told about it.
        if (null !== $this->urls_langs && !empty($this->urls_langs->urls_langs)) {
            $returnArray['urls_langs'] = $this->urls_langs->urls_langs;
        }
        return $returnArray;
    }

    abstract protected static function fromArrayInternal(array $data): static;

    abstract protected static function fromObjectInternal(\stdClass $data): static;
}
