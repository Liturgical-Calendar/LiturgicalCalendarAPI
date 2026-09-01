<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\MissalCatalog;
use LiturgicalCalendar\Api\Enum\Rite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame(['EDITIO_TYPICA_2024', 'EDITIO_TYPICA_1976'], $source->getMissalIds());
        self::assertSame('AMBROSIAN', $source->regionFor('EDITIO_TYPICA_2024'));
        self::assertSame('AMBROSIAN', $source->regionFor('EDITIO_TYPICA_1976'));
    }

    /**
     * `EDITIO_TYPICA_2024` is a typical edition — the normative base for the Ambrosian rite — and,
     * since its rename (#953 round 1), it now shares the `EDITIO_TYPICA_` prefix with the Roman
     * typical editions. That coincidence is exactly why the tier is not a prefix test: each source
     * answers from its own declared set, so the Ambrosian source reports it typical while the
     * Roman source — which has never declared `EDITIO_TYPICA_2024` as a valid id at all, prefix
     * notwithstanding — must not. A prefix-based `isEditioTypica()` would get this one right by
     * accident and be silently wrong the day a real Roman `EDITIO_TYPICA_2024` is ever declared.
     */
    public function testAnAmbrosianTypicalEditionIsNotReportedAsRomanEvenThoughTheIdsShareAPrefix(): void
    {
        self::assertTrue(MissalCatalog::for(Rite::AMBROSIAN)->isEditioTypica('EDITIO_TYPICA_2024'));
        self::assertFalse(MissalCatalog::for(Rite::ROMAN)->isEditioTypica('EDITIO_TYPICA_2024'));
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

    public function testNoAmbrosianEditionShipsALectionaryYet(): void
    {
        $source = MissalCatalog::for(Rite::AMBROSIAN);

        foreach ($source->getMissalIds() as $id) {
            self::assertFalse($source->getLectionaryFilePath($id), "$id must not claim lectionary data it does not ship");
        }
    }

    /**
     * The `calendar` label every sanctorale row carries. All 254 Ambrosian rows say AMBROSIAN on
     * disk, so the source must agree — and it is asked, never derived from a rite conditional.
     */
    public function testTheCalendarLabelComesFromTheSource(): void
    {
        self::assertSame('GENERAL ROMAN', MissalCatalog::for(Rite::ROMAN)->calendarLabelFor('EDITIO_TYPICA_1970'));
        self::assertSame('US', MissalCatalog::for(Rite::ROMAN)->calendarLabelFor('US_2011'));
        self::assertSame('AMBROSIAN', MissalCatalog::for(Rite::AMBROSIAN)->calendarLabelFor('EDITIO_TYPICA_2024'));
    }

    /** @return array<string, array{Rite}> */
    public static function riteProvider(): array
    {
        $cases = [];
        foreach (Rite::cases() as $rite) {
            $cases[$rite->value] = [$rite];
        }

        return $cases;
    }

    /** Both implementations reject an unknown id the same way; one interface, one contract. */
    #[DataProvider('riteProvider')]
    public function testRegionForRejectsAnUnknownIdInBothRites(Rite $rite): void
    {
        $this->expectException(\LiturgicalCalendar\Api\Http\Exception\ValidationException::class);
        MissalCatalog::for($rite)->regionFor('NOT_A_MISSAL');
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

    /**
     * There is no Ambrosian equivalent of `US_2011` or `IT_1983`: the Italian edition IS the authority for this
     * rite, its Latin counterpart is a translation, and no bishops' conference adapts it. So every declared
     * Ambrosian id must be a typical edition — and while that holds, the `national_calendar` branch of
     * `OpenFgaAuthorizationMiddleware::forMissals()` and of `ChangeResource::missal()` is unreachable for this
     * rite.
     *
     * The day someone coins a non-typical Ambrosian id, this test fails rather than the middleware quietly
     * filing a change request against an Ambrosian national calendar that does not exist.
     */
    public function testEveryDeclaredAmbrosianEditionIsTypicalSoTheRiteHasNoNationalTier(): void
    {
        $source = MissalCatalog::for(Rite::AMBROSIAN);
        $ids    = $source->getMissalIds();

        self::assertNotSame([], $ids, 'The Ambrosian rite must declare at least one edition.');

        foreach ($ids as $id) {
            self::assertTrue(
                $source->isEditioTypica($id),
                "$id is a declared Ambrosian id that is NOT a typical edition; the Ambrosian rite has no national tier, "
                . 'so either the id is wrong or OpenFgaAuthorizationMiddleware::forMissals() now has a reachable '
                . 'national_calendar branch for this rite that nothing covers.'
            );
        }
    }
}
