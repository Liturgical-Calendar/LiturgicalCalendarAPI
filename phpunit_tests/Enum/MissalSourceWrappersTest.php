<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\AmbrosianMissal;
use LiturgicalCalendar\Api\Enum\AmbrosianMissalSource;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Enum\RomanMissalSource;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadata;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `RomanMissalSource` and `AmbrosianMissalSource` are one-line-per-method delegation wrappers —
 * `MissalCatalogTest` already exercises several `MissalSource` methods *through* them, but not
 * every one, and codecov flagged the gap (46 uncovered lines, concentrated in these two classes:
 * 33.33% / 22 missing on the Ambrosian wrapper, 51.72% / 14 missing on the Roman one).
 *
 * A wrapper this thin does not fail by computing the wrong answer — it fails by wiring a method
 * to the wrong static, or omitting the validation the interface's docblock promises, exactly the
 * class of defect an earlier round of #953 review actually found: `regionFor()` threw for an
 * unknown id on one rite's wrapper and silently answered on the other, an inconsistency no test
 * caught because nothing exercised both wrappers' answers side by side. So this file's rule is:
 * every {@see \LiturgicalCalendar\Api\Enum\MissalSource} method, through BOTH wrappers, asserting
 * the delegated value against the underlying static directly (not merely "a value came back") —
 * and, wherever the interface's own docblock promises a `ValidationException` for an unknown id,
 * asserting BOTH wrappers keep that promise identically.
 */
#[CoversClass(RomanMissalSource::class)]
#[CoversClass(AmbrosianMissalSource::class)]
final class MissalSourceWrappersTest extends TestCase
{
    private const ROMAN_KNOWN_ID     = 'EDITIO_TYPICA_1970';
    private const AMBROSIAN_KNOWN_ID = 'EDITIO_TYPICA_2024';
    private const UNKNOWN_ID         = 'NOT_A_MISSAL';

    /**
     * `JsonData::…->path()` reads `Router::$apiFilePath`, which several `MissalSource` methods
     * touch (file-path resolution). Uninitialized under a bare PHPUnit run of this file alone.
     */
    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
    }

    public function testRomanRiteIdentity(): void
    {
        self::assertSame(Rite::ROMAN, ( new RomanMissalSource() )->rite());
    }

    public function testAmbrosianRiteIdentity(): void
    {
        self::assertSame(Rite::AMBROSIAN, ( new AmbrosianMissalSource() )->rite());
    }

    public function testRomanGetMissalIdsDelegatesToTheStatic(): void
    {
        self::assertSame(RomanMissal::getMissalIds(), ( new RomanMissalSource() )->getMissalIds());
    }

    public function testAmbrosianGetMissalIdsDelegatesToTheStatic(): void
    {
        self::assertSame(AmbrosianMissal::getMissalIds(), ( new AmbrosianMissalSource() )->getMissalIds());
    }

    public function testRomanIsValidDelegatesToTheStatic(): void
    {
        $source = new RomanMissalSource();
        self::assertTrue($source->isValid(self::ROMAN_KNOWN_ID));
        self::assertFalse($source->isValid(self::UNKNOWN_ID));
        self::assertSame(RomanMissal::isValid(self::ROMAN_KNOWN_ID), $source->isValid(self::ROMAN_KNOWN_ID));
    }

    public function testAmbrosianIsValidDelegatesToTheStatic(): void
    {
        $source = new AmbrosianMissalSource();
        self::assertTrue($source->isValid(self::AMBROSIAN_KNOWN_ID));
        self::assertFalse($source->isValid(self::UNKNOWN_ID));
        self::assertSame(AmbrosianMissal::isValid(self::AMBROSIAN_KNOWN_ID), $source->isValid(self::AMBROSIAN_KNOWN_ID));
    }

    public function testRomanGetNameDelegatesToTheStatic(): void
    {
        $source = new RomanMissalSource();
        self::assertSame(RomanMissal::getName(self::ROMAN_KNOWN_ID), $source->getName(self::ROMAN_KNOWN_ID));
    }

    public function testAmbrosianGetNameDelegatesToTheStatic(): void
    {
        $source = new AmbrosianMissalSource();
        self::assertSame(AmbrosianMissal::getName(self::AMBROSIAN_KNOWN_ID), $source->getName(self::AMBROSIAN_KNOWN_ID));
    }

    public function testRomanGetSanctoraleFileNameDelegatesToTheStatic(): void
    {
        $source = new RomanMissalSource();
        self::assertSame(
            RomanMissal::getSanctoraleFileName(self::ROMAN_KNOWN_ID),
            $source->getSanctoraleFileName(self::ROMAN_KNOWN_ID)
        );
    }

    public function testAmbrosianGetSanctoraleFileNameDelegatesToTheStatic(): void
    {
        $source = new AmbrosianMissalSource();
        self::assertSame(
            AmbrosianMissal::getSanctoraleFileName(self::AMBROSIAN_KNOWN_ID),
            $source->getSanctoraleFileName(self::AMBROSIAN_KNOWN_ID)
        );
    }

    public function testRomanGetSanctoraleI18nFilePathDelegatesToTheStatic(): void
    {
        $source = new RomanMissalSource();
        self::assertSame(
            RomanMissal::getSanctoraleI18nFilePath(self::ROMAN_KNOWN_ID),
            $source->getSanctoraleI18nFilePath(self::ROMAN_KNOWN_ID)
        );
    }

    public function testAmbrosianGetSanctoraleI18nFilePathDelegatesToTheStatic(): void
    {
        $source = new AmbrosianMissalSource();
        self::assertSame(
            AmbrosianMissal::getSanctoraleI18nFilePath(self::AMBROSIAN_KNOWN_ID),
            $source->getSanctoraleI18nFilePath(self::AMBROSIAN_KNOWN_ID)
        );
    }

    public function testRomanGetLectionaryFilePathDelegatesToTheStatic(): void
    {
        $source = new RomanMissalSource();
        self::assertSame(
            RomanMissal::getLectionaryFilePath(self::ROMAN_KNOWN_ID),
            $source->getLectionaryFilePath(self::ROMAN_KNOWN_ID)
        );
    }

    /**
     * No Ambrosian edition ships a lectionary of its own (#957), so this is always `false` for a
     * valid id — the interesting behaviour ({@see self::testGetLectionaryFilePathRejectsAnUnknownIdInBothRites()})
     * is that the id is still validated before answering.
     */
    public function testAmbrosianGetLectionaryFilePathIsFalseForAValidId(): void
    {
        self::assertFalse(( new AmbrosianMissalSource() )->getLectionaryFilePath(self::AMBROSIAN_KNOWN_ID));
    }

    /**
     * The rite-wide sanctorale lectionary corpus a missal without one of its own falls back to.
     * The Roman rite has one (`rite/roman/lectionary/sanctorum`); the Ambrosian rite does not
     * (#957) — `false`, never a fallback to the Roman folder, which is the defect this method
     * exists to prevent (see the class docblock on {@see \LiturgicalCalendar\Api\Enum\MissalSource::riteLectionaryFolder()}).
     */
    public function testRomanRiteLectionaryFolderIsTheRomanSanctorumCorpus(): void
    {
        self::assertSame(JsonData::LECTIONARY_SAINTS_FOLDER->path(), ( new RomanMissalSource() )->riteLectionaryFolder());
    }

    public function testAmbrosianRiteLectionaryFolderIsFalse(): void
    {
        self::assertFalse(( new AmbrosianMissalSource() )->riteLectionaryFolder());
    }

    public function testRomanGetYearLimitsDelegatesToTheStatic(): void
    {
        $source = new RomanMissalSource();
        self::assertSame(RomanMissal::getYearLimits(self::ROMAN_KNOWN_ID), $source->getYearLimits(self::ROMAN_KNOWN_ID));
    }

    public function testAmbrosianGetYearLimitsDelegatesToTheStatic(): void
    {
        $source = new AmbrosianMissalSource();
        self::assertSame(
            AmbrosianMissal::getYearLimits(self::AMBROSIAN_KNOWN_ID),
            $source->getYearLimits(self::AMBROSIAN_KNOWN_ID)
        );
    }

    public function testRomanIsEditioTypicaDelegatesToTheStatic(): void
    {
        $source = new RomanMissalSource();
        self::assertTrue($source->isEditioTypica(self::ROMAN_KNOWN_ID));
        self::assertSame(RomanMissal::isEditioTypica('US_2011'), $source->isEditioTypica('US_2011'));
    }

    public function testAmbrosianIsEditioTypicaDelegatesToTheStatic(): void
    {
        $source = new AmbrosianMissalSource();
        self::assertTrue($source->isEditioTypica(self::AMBROSIAN_KNOWN_ID));
        self::assertSame(
            AmbrosianMissal::isEditioTypica(self::UNKNOWN_ID),
            $source->isEditioTypica(self::UNKNOWN_ID)
        );
    }

    public function testRomanRegionForDelegatesToTheStatic(): void
    {
        $source = new RomanMissalSource();
        self::assertSame(RomanMissal::regionFor(self::ROMAN_KNOWN_ID), $source->regionFor(self::ROMAN_KNOWN_ID));
    }

    public function testAmbrosianRegionForDelegatesToTheStatic(): void
    {
        $source = new AmbrosianMissalSource();
        self::assertSame(AmbrosianMissal::REGION, $source->regionFor(self::AMBROSIAN_KNOWN_ID));
    }

    public function testRomanCalendarLabelForATypicalEditionIsGeneralRoman(): void
    {
        self::assertSame('GENERAL ROMAN', ( new RomanMissalSource() )->calendarLabelFor(self::ROMAN_KNOWN_ID));
    }

    public function testRomanCalendarLabelForANationalEditionIsItsRegion(): void
    {
        self::assertSame('US', ( new RomanMissalSource() )->calendarLabelFor('US_2011'));
    }

    public function testAmbrosianCalendarLabelForIsItsRegion(): void
    {
        self::assertSame(AmbrosianMissal::REGION, ( new AmbrosianMissalSource() )->calendarLabelFor(self::AMBROSIAN_KNOWN_ID));
    }

    /**
     * The Roman editio typica IS the Latin text, so an unsupported base locale falls back to
     * Latin. The Ambrosian editio typica is the reverse — `AmbrosianMissal`'s own docblock argues
     * the Italian edition is the authority — so this must NOT be the same value for both rites;
     * asserting they differ is the only way a copy-paste of one wrapper's answer into the other
     * would be caught.
     */
    public function testEditioTypicaFallbackLocaleDiffersByRiteAuthority(): void
    {
        $roman     = ( new RomanMissalSource() )->editioTypicaFallbackLocale();
        $ambrosian = ( new AmbrosianMissalSource() )->editioTypicaFallbackLocale();

        self::assertSame(LitLocale::LATIN_PRIMARY_LANGUAGE, $roman);
        self::assertSame(AmbrosianMissal::PRIMARY_LOCALE, $ambrosian);
        self::assertNotSame($roman, $ambrosian, 'the two rites disagree on which language their editio typica authority is written in');
    }

    public function testRomanProduceMetadataDelegatesToTheStatic(): void
    {
        $source = new RomanMissalSource();

        /** @var array<string, MissalMetadata> $expected */
        $expected = RomanMissal::produceMetadata();
        $actual   = $source->produceMetadata();

        self::assertSame(array_keys($expected), array_keys($actual));
        self::assertArrayHasKey(self::ROMAN_KNOWN_ID, $actual);
        self::assertEquals($expected[self::ROMAN_KNOWN_ID], $actual[self::ROMAN_KNOWN_ID]);
    }

    public function testAmbrosianProduceMetadataDelegatesToTheStatic(): void
    {
        $source = new AmbrosianMissalSource();

        /** @var array<string, MissalMetadata> $expected */
        $expected = AmbrosianMissal::produceMetadata();
        $actual   = $source->produceMetadata();

        self::assertSame(array_keys($expected), array_keys($actual));
        self::assertArrayHasKey(self::AMBROSIAN_KNOWN_ID, $actual);
        self::assertEquals($expected[self::AMBROSIAN_KNOWN_ID], $actual[self::AMBROSIAN_KNOWN_ID]);
    }

    /** @return array<string, array{\LiturgicalCalendar\Api\Enum\MissalSource}> */
    public static function bothWrappersProvider(): array
    {
        return [
            'roman'     => [new RomanMissalSource()],
            'ambrosian' => [new AmbrosianMissalSource()],
        ];
    }

    /**
     * Both wrappers must agree on contract SHAPE, not merely on Roman's or Ambrosian's own
     * behaviour: every method whose interface docblock promises a `ValidationException` for an
     * unknown id must keep that promise identically on both. Both implementations reject an
     * unknown id the same way; one interface, one contract.
     */
    #[DataProvider('bothWrappersProvider')]
    public function testGetNameRejectsAnUnknownIdInBothRites(\LiturgicalCalendar\Api\Enum\MissalSource $source): void
    {
        $this->expectException(ValidationException::class);
        $source->getName(self::UNKNOWN_ID);
    }

    #[DataProvider('bothWrappersProvider')]
    public function testGetSanctoraleFileNameRejectsAnUnknownIdInBothRites(\LiturgicalCalendar\Api\Enum\MissalSource $source): void
    {
        $this->expectException(ValidationException::class);
        $source->getSanctoraleFileName(self::UNKNOWN_ID);
    }

    #[DataProvider('bothWrappersProvider')]
    public function testGetSanctoraleI18nFilePathRejectsAnUnknownIdInBothRites(\LiturgicalCalendar\Api\Enum\MissalSource $source): void
    {
        $this->expectException(ValidationException::class);
        $source->getSanctoraleI18nFilePath(self::UNKNOWN_ID);
    }

    #[DataProvider('bothWrappersProvider')]
    public function testGetLectionaryFilePathRejectsAnUnknownIdInBothRites(\LiturgicalCalendar\Api\Enum\MissalSource $source): void
    {
        $this->expectException(ValidationException::class);
        $source->getLectionaryFilePath(self::UNKNOWN_ID);
    }

    #[DataProvider('bothWrappersProvider')]
    public function testGetYearLimitsRejectsAnUnknownIdInBothRites(\LiturgicalCalendar\Api\Enum\MissalSource $source): void
    {
        $this->expectException(ValidationException::class);
        $source->getYearLimits(self::UNKNOWN_ID);
    }

    #[DataProvider('bothWrappersProvider')]
    public function testCalendarLabelForRejectsAnUnknownIdInBothRites(\LiturgicalCalendar\Api\Enum\MissalSource $source): void
    {
        $this->expectException(ValidationException::class);
        $source->calendarLabelFor(self::UNKNOWN_ID);
    }

    /**
     * `isValid()` and `isEditioTypica()` are the two methods whose contract is a bare `false` for
     * an unknown id, never a throw — pinned here so a future edit cannot quietly "fix" one of
     * them into throwing and break every caller that treats an unknown id as an answerable no.
     */
    #[DataProvider('bothWrappersProvider')]
    public function testIsValidAndIsEditioTypicaAnswerFalseRatherThanThrowForAnUnknownIdInBothRites(
        \LiturgicalCalendar\Api\Enum\MissalSource $source
    ): void {
        self::assertFalse($source->isValid(self::UNKNOWN_ID));
        self::assertFalse($source->isEditioTypica(self::UNKNOWN_ID));
    }
}
