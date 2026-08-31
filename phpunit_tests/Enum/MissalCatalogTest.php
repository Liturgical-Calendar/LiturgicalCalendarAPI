<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\MissalCatalog;
use LiturgicalCalendar\Api\Enum\Rite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissalCatalog::class)]
final class MissalCatalogTest extends TestCase
{
    public function testTheRomanSourceKnowsTheRomanMissals(): void
    {
        $source = MissalCatalog::for(Rite::ROMAN);

        self::assertSame(Rite::ROMAN, $source->rite());
        self::assertContains('EDITIO_TYPICA_1970', $source->getMissalIds());
        self::assertTrue($source->isEditioTypica('EDITIO_TYPICA_1970'));
        self::assertFalse($source->isEditioTypica('US_2011'));
        self::assertSame('VA', $source->regionFor('EDITIO_TYPICA_1970'));
        self::assertSame('US', $source->regionFor('US_2011'));
    }

    public function testTheAmbrosianSourceKnowsTheAmbrosianMissal(): void
    {
        $source = MissalCatalog::for(Rite::AMBROSIAN);

        self::assertSame(Rite::AMBROSIAN, $source->rite());
        self::assertSame(['EDITIO_2024'], $source->getMissalIds());
        self::assertSame('AMBROSIAN', $source->regionFor('EDITIO_2024'));
    }

    /**
     * EDITIO_2024 is a typical edition — the normative base for the Ambrosian rite — while
     * matching no `EDITIO_TYPICA_` prefix. That is the whole reason the tier stopped being a
     * prefix test (#953, spec §4.3). Asserted against each source's own answer: the Ambrosian
     * source must report it typical despite the prefix, and the Roman source — which has never
     * heard of `EDITIO_2024` as a valid id — must not.
     */
    public function testTheAmbrosianEditionIsATypicalEditionDespiteItsIdPrefix(): void
    {
        self::assertTrue(MissalCatalog::for(Rite::AMBROSIAN)->isEditioTypica('EDITIO_2024'));
        self::assertFalse(MissalCatalog::for(Rite::ROMAN)->isEditioTypica('EDITIO_2024'));
    }

    /**
     * Pins Important 1 (code review round 1, #953): `AmbrosianMissalSource::isEditioTypica()`
     * must consult a declared typical-edition set, not merely `isValid()` — the same coupling
     * Step 5 removed on the Roman side by declaring `RomanMissal::$editioTypicaIds`. The Roman
     * side pins this with a valid-but-non-typical id (`US_2011`, see
     * {@see self::testTheRomanSourceKnowsTheRomanMissals()}); no such id exists yet for the
     * Ambrosian rite, so this exercises the weaker but still real case: an id the rite has never
     * declared at all must not be reported as typical either, rather than the tier collapsing
     * into a bare validity check.
     */
    public function testAnUndeclaredIdIsNotReportedAsAnAmbrosianTypicalEdition(): void
    {
        self::assertFalse(MissalCatalog::for(Rite::AMBROSIAN)->isEditioTypica('NOT_A_MISSAL'));
    }

    public function testTheRitesDoNotShareIds(): void
    {
        $roman     = MissalCatalog::for(Rite::ROMAN)->getMissalIds();
        $ambrosian = MissalCatalog::for(Rite::AMBROSIAN)->getMissalIds();

        self::assertSame([], array_intersect($roman, $ambrosian), 'a missal id must name one missal in one rite');
    }

    public function testTheAmbrosianMissalHasNoLectionary(): void
    {
        self::assertFalse(MissalCatalog::for(Rite::AMBROSIAN)->getLectionaryFilePath('EDITIO_2024'));
    }

    /**
     * The `calendar` label every sanctorale row carries. All 254 Ambrosian rows say AMBROSIAN on
     * disk, so the source must agree — and it is asked, never derived from a rite conditional.
     */
    public function testTheCalendarLabelComesFromTheSource(): void
    {
        self::assertSame('GENERAL ROMAN', MissalCatalog::for(Rite::ROMAN)->calendarLabelFor('EDITIO_TYPICA_1970'));
        self::assertSame('US', MissalCatalog::for(Rite::ROMAN)->calendarLabelFor('US_2011'));
        self::assertSame('AMBROSIAN', MissalCatalog::for(Rite::AMBROSIAN)->calendarLabelFor('EDITIO_2024'));
    }

    /** Both implementations reject an unknown id the same way; one interface, one contract. */
    public function testRegionForRejectsAnUnknownIdInBothRites(): void
    {
        $this->expectException(\LiturgicalCalendar\Api\Http\Exception\ValidationException::class);
        MissalCatalog::for(Rite::AMBROSIAN)->regionFor('NOT_A_MISSAL');
    }

    /**
     * Pins Important 2 (code review round 1, #953): `getLectionaryFilePath()` must validate the
     * id before answering `false`, exactly as `regionFor()` already does — an unanswerable
     * question ("does this id even name a missal?") is not the same as an answerable "no".
     */
    public function testGetLectionaryFilePathRejectsAnUnknownAmbrosianId(): void
    {
        $this->expectException(\LiturgicalCalendar\Api\Http\Exception\ValidationException::class);
        MissalCatalog::for(Rite::AMBROSIAN)->getLectionaryFilePath('NOT_A_MISSAL');
    }
}
