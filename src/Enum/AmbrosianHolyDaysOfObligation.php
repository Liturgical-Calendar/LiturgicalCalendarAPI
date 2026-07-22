<?php

namespace LiturgicalCalendar\Api\Enum;

/**
 * Default set of Ambrosian rite Holy Days of Obligation (`event_key` values).
 *
 * **Provisional / ordo-validation-pending (Plan 7 Task 6, Plan 9 follow-up):** unlike the Roman
 * `CalendarParams::$HolyDaysOfObligation` default (Can. 1246 §1 plus the Italian episcopal
 * conference concessions, keyed on Roman event keys such as `StJoseph`, `StsPeterPaulAp`, and
 * `CorpusChristi`), the Ambrosian rite does not share those keys and has its own liturgical
 * calendar of precepts (the Ambrosian `Circoncisione` on 1 January instead of `MaryMotherOfGod`,
 * no `CorpusChristi`/`StJoseph`/`StsPeterPaulAp` as Ambrosian precept days, plus the
 * Milan-specific `StAmbrose` and `DedicationDuomo` solemnities). This list is a reasonable
 * provisional set assembled from the Ambrosian Proprium de Tempore/Sanctis `event_key`s
 * (verified against `jsondata/sourcedata/missals/ambrosian/propriumdetempore/propriumdetempore.json`
 * and `.../propriumdesanctis_2024/propriumdesanctis.json`); it must be reconciled against an
 * authoritative Ambrosian ordo before this becomes a source of truth for production HDoO display.
 *
 * Every Sunday is also a holy day of obligation in the Ambrosian rite (as in the Roman rite);
 * that rule is applied separately in
 * `LiturgicalEventCollection::setAmbrosianHolyDaysOfObligation()` and is not part of this list.
 */
final class AmbrosianHolyDaysOfObligation
{
    /**
     * @var array<string,bool>
     */
    public const array DEFAULT = [
        'Christmas'            => true,
        'Circoncisione'        => true,
        'Epiphany'             => true,
        'Ascension'            => true,
        'Pentecost'            => true,
        'ImmaculateConception' => true,
        'Assumption'           => true,
        'AllSaints'            => true,
        'StAmbrose'            => true,
        'DedicationDuomo'      => true,
    ];
}
