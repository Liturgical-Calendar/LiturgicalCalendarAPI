<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\EventsPath;

use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitMassVariousNeeds;
use LiturgicalCalendar\Api\Models\EventsPath\LiturgicalEventAbstract;
use LiturgicalCalendar\Api\Models\EventsPath\LiturgicalEventFixed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Guards the Latin branch of the Masses for Various Needs commons in the /events
 * models (#749).
 *
 * Unlike LitCommons::fullTranslate() and LitGrade::i18n() — which accept either
 * Latin form via in_array($locale, [LitLocale::LATIN, LitLocale::LATIN_PRIMARY_LANGUAGE])
 * — LiturgicalEventAbstract branches on the strict primary-language form
 * (self::$locale === 'la') for LitMassVariousNeeds commons. Before #749,
 * EventsHandler::setLocale() passed the raw EventsParams->Locale, which defaults to
 * 'la_VA'; that missed this branch and would have rendered these commons in English
 * for a Latin request. No calendar in jsondata/sourcedata/ currently declares an MVN
 * common, so no fixture or golden master exercises this path — hence this test.
 *
 * @see \LiturgicalCalendar\Api\Handlers\EventsHandler::setLocale()
 */
#[CoversClass(LiturgicalEventAbstract::class)]
final class MassVariousNeedsLatinLocaleTest extends TestCase
{
    protected function tearDown(): void
    {
        // The model locale is process-global static state; restore the class default.
        LiturgicalEventAbstract::setLocale(LitLocale::LATIN_PRIMARY_LANGUAGE);
        parent::tearDown();
    }

    /**
     * @param LitMassVariousNeeds|LitMassVariousNeeds[] $common
     */
    private function commonLclFor(LitMassVariousNeeds|array $common): string
    {
        $event = new LiturgicalEventFixed(
            'TestMassVariousNeeds',
            'Test Event',
            1,
            1,
            LitColor::WHITE,
            LitEventType::FIXED,
            LitGrade::MEMORIAL_OPT,
            $common
        );

        $commonLcl = $event->jsonSerialize()['common_lcl'];
        self::assertIsString($commonLcl);
        return $commonLcl;
    }

    public function testSingleMassVariousNeedsCommonRendersInLatin(): void
    {
        LiturgicalEventAbstract::setLocale(LitLocale::LATIN_PRIMARY_LANGUAGE);

        self::assertSame(
            'MISSÆ ET ORATIONES PRO VARIIS NECESSITATIBUS VEL AD DIVERSA: Pro Papa',
            $this->commonLclFor(LitMassVariousNeeds::PRO_PAPA)
        );
    }

    public function testMultipleMassVariousNeedsCommonsAreJoinedWithTheLatinGlue(): void
    {
        LiturgicalEventAbstract::setLocale(LitLocale::LATIN_PRIMARY_LANGUAGE);

        $commonLcl = $this->commonLclFor([LitMassVariousNeeds::PRO_PAPA, LitMassVariousNeeds::PRO_ECCLESIA]);

        self::assertSame(
            'MISSÆ ET ORATIONES PRO VARIIS NECESSITATIBUS VEL AD DIVERSA: Pro Papa'
                . '; vel MISSÆ ET ORATIONES PRO VARIIS NECESSITATIBUS VEL AD DIVERSA: Pro Ecclesia',
            $commonLcl
        );
    }

    /**
     * The complement: a non-Latin locale must NOT take the Latin branch. This is what
     * a Latin request wrongly produced while the handler passed the raw 'la_VA'.
     */
    public function testNonLatinLocaleDoesNotTakeTheLatinBranch(): void
    {
        LiturgicalEventAbstract::setLocale('en_US');

        $commonLcl = $this->commonLclFor(LitMassVariousNeeds::PRO_PAPA);

        self::assertStringNotContainsString('MISSÆ ET ORATIONES', $commonLcl);
        self::assertStringContainsString('For the Pope', $commonLcl);
    }
}
