<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models;

use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitMassVariousNeeds;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;

/**
 * The derived fields of a liturgical event model, and the one implementation of how each is
 * computed from the properties it describes.
 *
 * Shared by the two models that carry these fields — {@see \LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent}
 * (`/calendar`) and {@see \LiturgicalCalendar\Api\Models\EventsPath\LiturgicalEventAbstract}
 * (`/events`) — which previously held a hand-copied version each. That duplication is what let the
 * two endpoints disagree about the same event (#872): a fix applied to one copy left the other
 * describing a `common` it no longer carried.
 *
 * Constructors call {@see self::deriveAllFields()}; every post-construction write to a source
 * property calls {@see self::rederiveDependentsOf()} with the property name. Both go through the
 * same `derive*()` methods, so "what the field would have been if the event had been constructed
 * this way" and "what the field is after a write" cannot drift apart.
 *
 * The using class supplies the source properties (`$color`, `$grade`, `$grade_display`, `$common`)
 * and a `setLocale()` that assigns {@see self::$locale}.
 */
trait DerivesLiturgicalFieldsTrait
{
    /** @var string[] The localized colour names. Derived from `$color`. */
    protected array $color_lcl;

    /** The localized grade label. Derived from `$grade`. */
    protected string $grade_lcl;

    /** The abbreviated localized grade label. Derived from `$grade`. */
    protected string $grade_abbr;

    /** The localized Common (or Proper) description. Derived from `$common`. */
    protected string $common_lcl;

    /**
     * The locale every derivation here reads.
     *
     * Static, and per using class (a trait's static properties are not shared between the classes
     * that use it), matching the models' previous behaviour. It is the ONE locale a derived field
     * is computed from: before #872 the `/calendar` model's constructor used this while
     * `LiturgicalEventCollection` re-derived from `CalendarParams->Locale`, which is the
     * un-normalized REQUEST locale (`la_VA` where this holds `la`, `it` where this holds `it_IT`).
     * Those agree today only because the localization helpers branch on `LitLocale::isLatin()` and
     * otherwise defer to process-global gettext — an accident, not a guarantee.
     */
    protected static string $locale = LitLocale::LATIN_PRIMARY_LANGUAGE;

    /**
     * Populates every derived field from the source properties as they currently stand.
     *
     * For constructors: assign the source properties (including any explicit `$grade_display`
     * override), then call this once.
     */
    protected function deriveAllFields(): void
    {
        foreach (DerivedField::sourceProperties() as $sourceProperty) {
            $this->rederiveDependentsOf($sourceProperty);
        }
    }

    /**
     * Re-derives every field that depends on `$sourceProperty`, after that property has been written.
     *
     * Safe to call for any property name: one with no dependents re-derives nothing, so a caller
     * that writes properties generically (`LiturgicalEventCollection::setProperty()`, which writes
     * by reflection) can call this after every write without special-casing which properties matter.
     */
    public function rederiveDependentsOf(string $sourceProperty): void
    {
        foreach (DerivedField::dependentsOf($sourceProperty) as $derivedField) {
            $this->rederive($derivedField);
        }
    }

    /**
     * Dispatches one derived field to its derivation.
     *
     * No `default` arm, deliberately: the `match` is exhaustive over a closed enum, so PHPStan
     * proves it complete today and re-flags it (`match.unhandled`) the moment a case is added
     * without a derivation — which is the whole point of routing derivations through an enum
     * rather than through an `if/elseif` chain that can simply be left one branch short.
     */
    private function rederive(DerivedField $derivedField): void
    {
        match ($derivedField) {
            DerivedField::COLOR_LCL     => $this->deriveColorLcl(),
            DerivedField::GRADE_LCL     => $this->deriveGradeLcl(),
            DerivedField::GRADE_ABBR    => $this->deriveGradeAbbr(),
            DerivedField::GRADE_DISPLAY => $this->deriveGradeDisplay(),
            DerivedField::COMMON_LCL    => $this->deriveCommonLcl(),
        };
    }

    private function deriveColorLcl(): void
    {
        $this->color_lcl = array_map(
            function (LitColor $item): string {
                return $item->i18n(self::$locale);
            },
            $this->color
        );
    }

    private function deriveGradeLcl(): void
    {
        $this->grade_lcl = $this->grade->i18n(self::$locale, false, false);
    }

    private function deriveGradeAbbr(): void
    {
        $this->grade_abbr = $this->grade->i18n(self::$locale, false, true);
    }

    /**
     * `grade_display` is only PARTLY derived, so this deliberately does less than the other
     * derivations: it can clear the field, never repopulate it.
     *
     * The value is an authored display override (`CalendarHandler` writes `''` for `AllSouls` and
     * the localized FEAST label for `DedicationLateran`) which nothing can recompute from the
     * grade. The one rule the constructor couples to the grade is that a HIGHER_SOLEMNITY carries
     * no displayable grade of its own, so it clears the field; every other grade leaves the
     * caller's value untouched. A grade write mirrors exactly that, which is the precedence decided
     * in #872: an explicit override survives an ordinary grade change, and a promotion to
     * HIGHER_SOLEMNITY still clears it.
     *
     * Note the asymmetry: moving AWAY from HIGHER_SOLEMNITY leaves the `''` in place rather than
     * restoring a display value there is no source for. That is intentional, not an oversight.
     */
    private function deriveGradeDisplay(): void
    {
        if ($this->grade === LitGrade::HIGHER_SOLEMNITY) {
            $this->grade_display = '';
        }
    }

    /**
     * Mirrors the three shapes `$common` can hold: a `LitCommons` collection, one or more
     * `LitMassVariousNeeds` cases (joined with the localized "or" glue), or — for a value that is
     * neither, which the declared types make unreachable — the `'???'` marker the models have
     * always used rather than a plausible-looking empty string.
     */
    private function deriveCommonLcl(): void
    {
        $common = $this->common;

        if ($common instanceof LitCommons) {
            $this->common_lcl = $common->fullTranslate(self::$locale);
            return;
        }

        if (count($common) > 0 && $common[0] instanceof LitMassVariousNeeds) {
            /** @var LitMassVariousNeeds[] $common */
            $commonsLcl = array_map(
                function (LitMassVariousNeeds $item): string {
                    return $item->fullTranslate(LitLocale::isLatin(self::$locale));
                },
                $common
            );

            /**translators: when there are multiple possible commons, this will be the glue "[; or] From the Common of..." */
            $or               = LitLocale::isLatin(self::$locale) ? 'vel' : _('or');
            $this->common_lcl = implode('; ' . $or . ' ', $commonsLcl);
            return;
        }

        $this->common_lcl = '???';
    }

    /**
     * Overrides the abbreviated grade label with a value not derivable from the grade.
     *
     * Used for `DedicationLateran`, which is ranked as a FEAST_LORD but displayed as a FEAST. Like
     * `grade_display`, such an override is authored: a later `grade` write re-derives the label
     * from the new grade and discards it.
     */
    public function setGradeAbbreviation(string $abbreviation): void
    {
        $this->grade_abbr = $abbreviation;
    }

    /**
     * @return string The liturgical commons localized for the current locale.
     */
    public function getCommonLcl(): string
    {
        return $this->common_lcl;
    }
}
