<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models;

/**
 * The fields a liturgical event model computes from OTHER properties rather than receiving
 * directly, together with the single declarative mapping of which source property invalidates
 * which of them.
 *
 * Both event models — {@see \LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent} (the
 * calculated `/calendar` event) and {@see \LiturgicalCalendar\Api\Models\EventsPath\LiturgicalEventAbstract}
 * (the `/events` catalog entry) — derive these in their constructors and serialize them alongside
 * the raw values they describe. Their raw sources are then written after construction:
 * `LiturgicalEventCollection::setProperty()` writes them by reflection, and the catalog's
 * `applyGrade()`/`applyCommon()` write them directly. Every such write has to bring the dependents
 * with it, or the API publishes a numeric grade next to the label of a different grade, or a
 * `common` next to the `common_lcl` of the Common it replaced.
 *
 * Before #872 that was done with one hardcoded `if` branch per field, added each time somebody was
 * bitten — which is why `color_lcl` never got one at all. Here it is one row in {@see self::DEPENDENTS},
 * and forgetting a row is visible in one place instead of being invisible in the middle of a
 * 40-line method.
 *
 * The enum is deliberately closed: {@see DerivesLiturgicalFieldsTrait::rederive()} dispatches over
 * it with a `match` that has no `default` arm, so a new case with no derivation is a PHPStan
 * `match.unhandled` error at analysis time and an `\UnhandledMatchError` at runtime, never a
 * silently stale serialized field. `DerivedFieldCoverageTest` closes the remaining direction: a new
 * derived property on a model with no case here at all.
 */
enum DerivedField: string
{
    /** The localized colour names, derived from `color`. */
    case COLOR_LCL = 'color_lcl';

    /** The localized grade label, derived from `grade`. */
    case GRADE_LCL = 'grade_lcl';

    /** The abbreviated localized grade label, derived from `grade`. */
    case GRADE_ABBR = 'grade_abbr';

    /**
     * The display override for the grade. Only PARTLY derived: it holds a caller-supplied string
     * that no source property can reconstruct, and a grade write only clears it for a
     * HIGHER_SOLEMNITY. See {@see DerivesLiturgicalFieldsTrait::deriveGradeDisplay()}.
     */
    case GRADE_DISPLAY = 'grade_display';

    /** The localized Common (or Proper) description, derived from `common`. */
    case COMMON_LCL = 'common_lcl';

    /**
     * Source property => the derived fields a write to it invalidates.
     *
     * Keys are the property names as written by `setProperty()` and by the catalog mutators.
     *
     * @var array<string, list<self>>
     */
    private const DEPENDENTS = [
        'color'  => [self::COLOR_LCL],
        'grade'  => [self::GRADE_LCL, self::GRADE_ABBR, self::GRADE_DISPLAY],
        'common' => [self::COMMON_LCL],
    ];

    /**
     * The derived fields invalidated by a write to `$sourceProperty`.
     *
     * A property with no dependents (`name`, `psalter_week`, `date`, …) yields an empty list, so
     * callers can re-derive unconditionally after any write without knowing which properties matter.
     *
     * @return list<self>
     */
    public static function dependentsOf(string $sourceProperty): array
    {
        return self::DEPENDENTS[$sourceProperty] ?? [];
    }

    /**
     * Every source property that has dependents, for callers that need to re-derive everything —
     * the constructors, which populate all derived fields at once.
     *
     * @return list<string>
     */
    public static function sourceProperties(): array
    {
        return array_keys(self::DEPENDENTS);
    }
}
