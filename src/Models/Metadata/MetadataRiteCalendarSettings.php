<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Metadata;

use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\CorpusChristi;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;

/**
 * The calendar settings a *rite* fixes, announced on `/calendars` for every rite-level
 * calendar that fixes any (currently only the Ambrosian comune, `ambrosian_calendars[]`).
 *
 * **Why not {@see MetadataNationalCalendarSettings}?** The JSON shape is deliberately
 * identical — `epiphany`, `ascension`, `corpus_christi`, `eternal_high_priest`,
 * `holydays_of_obligation` — so clients parse all three calendar tiers (rite, national,
 * diocesan) with one code path. The PHP semantics are not: the national type seeds
 * `holydays_of_obligation` with the Roman Can. 1246 §1 set and merges a national calendar's
 * source-file overrides on top, which is exactly right for a Roman national calendar that
 * only declares its *differences*. A non-Roman rite does not share those `event_key`s at
 * all — the Ambrosian rite has `Circoncisione` where the Roman has `MaryMotherOfGod`, and
 * has no `CorpusChristi`/`StJoseph`/`StsPeterPaulAp` days of precept — so merging onto the
 * Roman seed would announce holy days that do not exist in the calendar being described.
 * A rite therefore declares its precept days in full, and this type stores them verbatim.
 *
 * @phpstan-type RiteCalendarSettingsObject \stdClass&object{
 *      epiphany:string,
 *      ascension:string,
 *      corpus_christi:string,
 *      eternal_high_priest:bool,
 *      holydays_of_obligation:\stdClass
 * }
 * @phpstan-type RiteCalendarSettingsArray array{
 *      epiphany:string,
 *      ascension:string,
 *      corpus_christi:string,
 *      eternal_high_priest:bool,
 *      holydays_of_obligation:array<string,bool>
 * }
 */
final class MetadataRiteCalendarSettings extends AbstractJsonRepresentation
{
    public Epiphany $epiphany;

    public Ascension $ascension;

    public CorpusChristi $corpus_christi;

    public bool $eternal_high_priest;

    /**
     * Declared in full by the rite, with no Roman seed merged in. See the class docblock.
     *
     * @var array<string,bool>
     */
    public array $holydays_of_obligation;

    /**
     * @param array<string,bool> $holydays_of_obligation `event_key` => whether it is observed as a day of precept
     */
    private function __construct(
        Epiphany $epiphany,
        Ascension $ascension,
        CorpusChristi $corpus_christi,
        bool $eternal_high_priest,
        array $holydays_of_obligation
    ) {
        $this->epiphany               = $epiphany;
        $this->ascension              = $ascension;
        $this->corpus_christi         = $corpus_christi;
        $this->eternal_high_priest    = $eternal_high_priest;
        $this->holydays_of_obligation = $holydays_of_obligation;
    }

    /**
     * Key order matches {@see MetadataNationalCalendarSettings::jsonSerialize()} so the three
     * calendar tiers serialize to the same shape.
     *
     * {@inheritDoc}
     *
     * @return array{epiphany:string,ascension:string,corpus_christi:string,eternal_high_priest:bool,holydays_of_obligation:array<string,bool>}
     */
    public function jsonSerialize(): array
    {
        return [
            'epiphany'               => $this->epiphany->value,
            'ascension'              => $this->ascension->value,
            'corpus_christi'         => $this->corpus_christi->value,
            'eternal_high_priest'    => $this->eternal_high_priest,
            'holydays_of_obligation' => $this->holydays_of_obligation
        ];
    }

    /**
     * @param RiteCalendarSettingsArray $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static(
            Epiphany::from($data['epiphany']),
            Ascension::from($data['ascension']),
            CorpusChristi::from($data['corpus_christi']),
            $data['eternal_high_priest'],
            self::validateHolyDaysOfObligation($data['holydays_of_obligation'] ?? [])
        );
    }

    /**
     * @param RiteCalendarSettingsObject $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            Epiphany::from($data->epiphany),
            Ascension::from($data->ascension),
            CorpusChristi::from($data->corpus_christi),
            $data->eternal_high_priest,
            self::validateHolyDaysOfObligation(property_exists($data, 'holydays_of_obligation') ? (array) $data->holydays_of_obligation : [])
        );
    }

    /**
     * @param array<array-key,mixed> $holydays_of_obligation
     * @return array<string,bool>
     */
    private static function validateHolyDaysOfObligation(array $holydays_of_obligation): array
    {
        /** @var array<string,bool> $validated */
        $validated = [];
        foreach ($holydays_of_obligation as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new \ValueError('Invalid key in holydays_of_obligation: expected a non-empty string');
            }
            if (!is_bool($value)) {
                throw new \ValueError('Invalid value for holydays_of_obligation[' . $key . ']: expected a boolean');
            }
            $validated[$key] = $value;
        }
        return $validated;
    }
}
