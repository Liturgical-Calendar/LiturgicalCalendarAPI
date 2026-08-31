<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * One rite's registry of missal editions.
 *
 * `RomanMissal` and `AmbrosianMissal` each hold their rite's ids, names, file paths and year
 * limits as static maps, and every existing call site reaches them statically. This interface
 * is the polymorphic face of those registries, so that code which must work for *whichever*
 * rite a request names — the metadata index, the handler — can resolve one through
 * {@see MissalCatalog::for()} instead of hardcoding `RomanMissal::`.
 *
 * Instance methods rather than statics: PHP cannot dispatch a static call polymorphically
 * through an interface, and the point here is precisely to choose the implementation at runtime.
 * Each implementation is a thin wrapper delegating to its own statics, which stay public so the
 * existing static call sites are untouched.
 */
interface MissalSource
{
    /** The rite whose missals this source registers. */
    public function rite(): Rite;

    /** @return string[] every missal id this rite declares */
    public function getMissalIds(): array;

    public function isValid(string $missalId): bool;

    public function getName(string $missalId): string;

    public function getSanctoraleFileName(string $missalId): string|false;

    public function getSanctoraleI18nFilePath(string $missalId): string|false;

    /** False when this missal ships no lectionary of its own; the caller falls back to the rite's. */
    public function getLectionaryFilePath(string $missalId): string|false;

    /** @return array{since_year:int,until_year?:int} */
    public function getYearLimits(string $missalId): array;

    /**
     * Whether this edition is a typical edition: the normative base from which regional missals
     * of the same rite are computed as deltas.
     *
     * Deliberately NOT `str_starts_with($id, 'EDITIO_TYPICA_')`, even though every id declared
     * today — Roman and Ambrosian alike — happens to carry that prefix. The prefix is a naming
     * convention, not a type: it is a coincidence that the Ambrosian typical edition was renamed
     * to share it (#953), and nothing stops a future national or diocesan delta from reusing the
     * same prefix, or a future typical edition from not carrying it. Each implementation answers
     * from its own declared list instead, which is the only thing actually asserting the type.
     */
    public function isEditioTypica(string $missalId): bool;

    /**
     * The region a missal's events are filed under: `VA` for a Roman typical edition, the nation
     * code for a Roman national edition, `AMBROSIAN` for the Ambrosian rite.
     */
    public function regionFor(string $missalId): string;

    /**
     * The `calendar` value every sanctorale row of this missal carries.
     *
     * `GENERAL ROMAN` for a Roman typical edition, the nation code for a Roman national edition,
     * `AMBROSIAN` for every Ambrosian edition — all 254 rows of the Ambrosian sanctorale already
     * say so on disk. A property of the source, deliberately NOT a rite conditional nested inside
     * the tier test: those are two independent questions, and a rite added later must have one
     * obvious place to answer this.
     */
    public function calendarLabelFor(string $missalId): string;
}
