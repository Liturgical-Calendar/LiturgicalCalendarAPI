<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData;

use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionId;
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionLabels;

final class WiderRegionMetadata extends AbstractJsonRepresentation
{
    /** @var string[] */
    public readonly array $locales;

    /** The region's id (#1018), already mapped from a legacy name when one was sent. */
    public string $wider_region;

    /** @var array<string, string> Display labels by language (WiderRegionLabels). */
    public readonly array $labels;

    /** Whether `wider_region` arrived as a legacy capitalised name. Not serialized. */
    public readonly bool $usedLegacyId;

    /**
     * @param string[]              $locales
     * @param array<string, string> $labels
     */
    private function __construct(array $locales, string $wider_region, array $labels, bool $usedLegacyId)
    {
        $this->locales      = $locales;
        $this->wider_region = $wider_region;
        $this->labels       = $labels;
        $this->usedLegacyId = $usedLegacyId;
    }

    /** @return array{locales: string[], wider_region: string, labels?: array<string, string>} */
    public function jsonSerialize(): array
    {
        $out = ['locales' => $this->locales, 'wider_region' => $this->wider_region];
        if ($this->labels !== []) {
            $out['labels'] = $this->labels;
        }

        return $out;
    }

    /**
     * @param array{locales:string[],wider_region:string,labels?:array<string,string>} $data
     * @throws \ValueError When a key is missing, the id is neither an id nor a legacy name, or a label is not allowed.
     * @throws \TypeError When `locales` is not a non-empty array or `wider_region` is not a string.
     */
    protected static function fromArrayInternal(array $data): static
    {
        return self::build($data['locales'] ?? null, $data['wider_region'] ?? null, $data['labels'] ?? null);
    }

    /**
     * @param \stdClass&object{locales:string[],wider_region:string,labels?:\stdClass} $data
     * @throws \ValueError|\TypeError As fromArrayInternal().
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return self::build($data->locales ?? null, $data->wider_region ?? null, $data->labels ?? null);
    }

    private static function build(mixed $locales, mixed $widerRegion, mixed $labels): static
    {
        if ($locales === null || $widerRegion === null) {
            throw new \ValueError('locales and wider_region parameters are required');
        }
        if (!is_array($locales) || count($locales) === 0) {
            throw new \TypeError('locales parameter must be an array and must not be empty');
        }
        if (!is_string($widerRegion)) {
            throw new \TypeError('wider_region parameter must be a string');
        }
        $normalized = WiderRegionId::normalize($widerRegion);
        if ($normalized === null) {
            throw new \ValueError('`metadata.wider_region` must be a wider region id such as `europe` or `german-language-area`');
        }
        /** @var list<string> $localeList */
        $localeList = array_values(array_filter($locales, 'is_string'));

        return new self($localeList, $normalized[0], WiderRegionLabels::validate($labels, $localeList), $normalized[1]);
    }
}
