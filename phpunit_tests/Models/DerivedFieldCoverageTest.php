<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models;

use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Models\DerivedField;
use LiturgicalCalendar\Api\Models\EventsPath\LiturgicalEventAbstract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The structural half of the guarantee that a derived field cannot silently stop being re-derived
 * (#872).
 *
 * The other half is enforced by the type system: {@see DerivedField} is a closed enum, and
 * {@see \LiturgicalCalendar\Api\Models\DerivesLiturgicalFieldsTrait::rederive()} dispatches over it
 * with a `match` carrying no `default` arm, so adding a case without adding its derivation is a
 * PHPStan `match.unhandled` error at analysis time and an `\UnhandledMatchError` at runtime.
 *
 * What the type system cannot see is the step BEFORE that: declaring a new derived property on a
 * model and never adding a `DerivedField` case for it at all. These tests close that gap from both
 * ends — every derived property must have a case, and every case must be reachable from some source
 * property, so a case that exists but is wired to nothing is caught too.
 */
#[CoversClass(DerivedField::class)]
final class DerivedFieldCoverageTest extends TestCase
{
    /**
     * The two models that carry derived fields. Both use `DerivesLiturgicalFieldsTrait`; listing
     * them explicitly means a third model that copies the pattern without the trait still has to be
     * added here consciously.
     *
     * @return array<string, array{class-string}>
     */
    public static function modelProvider(): array
    {
        return [
            'Calendar\LiturgicalEvent'           => [LiturgicalEvent::class],
            'EventsPath\LiturgicalEventAbstract' => [LiturgicalEventAbstract::class],
        ];
    }

    /**
     * On both models the derived fields are exactly the non-public instance properties: the raw
     * source properties they are derived from are all public, and the localized companions are not.
     * So any non-public instance property that is NOT a declared `DerivedField` is either a new
     * derived field nobody wired up, or a property that needs an explicit exemption here.
     *
     * @param class-string $model
     */
    #[DataProvider('modelProvider')]
    public function testEveryNonPublicInstancePropertyIsADeclaredDerivedField(string $model): void
    {
        $reflection = new \ReflectionClass($model);
        $checked    = 0;

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic() || $property->isPublic()) {
                continue;
            }

            ++$checked;
            self::assertNotNull(
                DerivedField::tryFrom($property->getName()),
                sprintf(
                    '%s::$%s is a non-public instance property of a liturgical event model, which on these models means a DERIVED field, '
                    . 'but there is no DerivedField case for it — so nothing re-derives it after a write to whatever it is derived from. '
                    . 'Add the case (and its arm in DerivesLiturgicalFieldsTrait::rederive()), or exempt the property here deliberately.',
                    $model,
                    $property->getName()
                )
            );
        }

        self::assertGreaterThan(0, $checked, $model . ' declares no non-public instance properties: this test is no longer checking anything.');
    }

    /**
     * `grade_display` is public (clients read it, and `CalendarHandler` writes it directly for
     * `AllSouls`), so the property sweep above cannot see it. It is nonetheless grade-coupled, and
     * the coupling is the one decided in #872: a grade write clears it to `''` for a
     * HIGHER_SOLEMNITY and otherwise leaves an explicit override alone.
     */
    public function testGradeDisplayIsWiredAsADependentOfGrade(): void
    {
        self::assertContains(DerivedField::GRADE_DISPLAY, DerivedField::dependentsOf('grade'));
    }

    /**
     * A case that no source property invalidates would never be re-derived, however complete its
     * `rederive()` arm looks.
     */
    public function testEveryDerivedFieldCaseIsReachableFromASourceProperty(): void
    {
        $reachable = [];
        foreach (DerivedField::sourceProperties() as $sourceProperty) {
            foreach (DerivedField::dependentsOf($sourceProperty) as $derivedField) {
                $reachable[] = $derivedField;
            }
        }

        foreach (DerivedField::cases() as $case) {
            self::assertContains(
                $case,
                $reachable,
                sprintf('DerivedField::%s is not listed as a dependent of any source property, so nothing would ever re-derive it.', $case->name)
            );
        }
    }

    public function testAnUnknownSourcePropertyHasNoDependents(): void
    {
        self::assertSame([], DerivedField::dependentsOf('psalter_week'));
    }
}
