<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\LitCommon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LitCommon::class)]
final class LitCommonTest extends TestCase
{
    public function testCaseValuesMatchEnglish(): void
    {
        self::assertSame('Proper', LitCommon::PROPRIO->value);
        self::assertSame('Blessed Virgin Mary', LitCommon::BEATAE_MARIAE_VIRGINIS->value);
        self::assertSame('', LitCommon::NONE->value);
    }

    public function testLatinMapCoversAllCases(): void
    {
        $caseNames = array_map(static fn (LitCommon $c): string => $c->name, LitCommon::cases());
        self::assertSame($caseNames, array_keys(LitCommon::LATIN));
    }

    public function testLatinValueExamples(): void
    {
        self::assertSame('Beatæ Mariæ Virginis', LitCommon::LATIN[LitCommon::BEATAE_MARIAE_VIRGINIS->name]);
        self::assertSame('Pro papa', LitCommon::LATIN[LitCommon::PRO_PAPA->name]);
        self::assertSame('', LitCommon::LATIN[LitCommon::NONE->name]);
    }

    public function testGeneralCommonsConstantContents(): void
    {
        self::assertContains(LitCommon::PROPRIO, LitCommon::COMMUNES_GENERALIS);
        self::assertContains(LitCommon::DEDICATIONIS_ECCLESIAE, LitCommon::COMMUNES_GENERALIS);
        self::assertContains(LitCommon::SANCTORUM_ET_SANCTARUM, LitCommon::COMMUNES_GENERALIS);
        self::assertCount(8, LitCommon::COMMUNES_GENERALIS);
    }

    public function testSubCommonGroupings(): void
    {
        self::assertContains(LitCommon::PRO_UNO_MARTYRE, LitCommon::COMMUNE_MARTYRUM);
        self::assertContains(LitCommon::PRO_PAPA, LitCommon::COMMUNE_PASTORUM);
        self::assertContains(LitCommon::PRO_UNA_VIRGINE, LitCommon::COMMUNE_VIRGINUM);
        self::assertContains(LitCommon::PRO_ABBATE, LitCommon::COMMUNE_SANCTORUM);
    }

    public function testTranslateReturnsStrings(): void
    {
        foreach (LitCommon::cases() as $case) {
            $translated = $case->translate();
            self::assertIsString($translated);
        }
        // NONE always translates to empty string.
        self::assertSame('', LitCommon::NONE->translate());
    }

    public function testPossessiveForFeminineSingular(): void
    {
        // Without a catalog pgettext() returns msgid 'of the' / 'of'.
        self::assertSame('of the', LitCommon::BEATAE_MARIAE_VIRGINIS->possessive());
        self::assertSame('of the', LitCommon::DEDICATIONIS_ECCLESIAE->possessive());
    }

    public function testPossessiveForPluralFeminine(): void
    {
        self::assertSame('of', LitCommon::VIRGINUM->possessive());
    }

    public function testPossessiveForPluralMasculine(): void
    {
        self::assertSame('of', LitCommon::MARTYRUM->possessive());
        self::assertSame('of', LitCommon::PASTORUM->possessive());
        self::assertSame('of', LitCommon::DOCTORUM->possessive());
        self::assertSame('of', LitCommon::SANCTORUM_ET_SANCTARUM->possessive());
    }

    public function testPossessiveForSingularMasculineDefault(): void
    {
        // PROPRIO is in the General Commons but not matched by any other arm —
        // it falls into the default (SING_MASC) branch.
        self::assertSame('of the', LitCommon::PROPRIO->possessive());
    }

    public function testPossessiveRejectsNonGeneralCommons(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PRO_UNO_MARTYRE');
        LitCommon::PRO_UNO_MARTYRE->possessive();
    }
}
