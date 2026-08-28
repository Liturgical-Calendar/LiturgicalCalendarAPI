<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Locale;

use LiturgicalCalendar\Api\Services\Locale\LocaleReadinessCheck;
use LiturgicalCalendar\Api\Services\Locale\LocaleReadinessChecker;
use LiturgicalCalendar\Api\Services\Locale\LocaleReadinessReport;
use LiturgicalCalendar\Api\Services\SupportedLocales;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocaleReadinessChecker::class)]
#[CoversClass(LocaleReadinessReport::class)]
#[CoversClass(LocaleReadinessCheck::class)]
final class LocaleReadinessCheckerTest extends TestCase
{
    private LocaleReadinessChecker $checker;

    protected function setUp(): void
    {
        SupportedLocales::reset();
        $this->checker = new LocaleReadinessChecker();
    }

    /**
     * The guard that matters: promoting a locale turns quiet degradation into hard
     * failures, so an already-official locale must never be incomplete. If this
     * fails, either data regressed or a locale was promoted too early.
     */
    #[DataProvider('officialLocales')]
    public function testEveryOfficialLocaleIsReady(string $locale): void
    {
        $report = $this->checker->check($locale);

        self::assertTrue(
            $report->ready(),
            $report->describe() . "\n" . implode("\n", array_map(
                static fn (LocaleReadinessCheck $c): string => '  ' . $c->name . ': ' . implode(', ', $c->missing),
                $report->failures()
            ))
        );
    }

    /** @return array<string, array{string}> */
    public static function officialLocales(): array
    {
        $cases = [];
        foreach (SupportedLocales::official() as $locale) {
            $cases[$locale] = [$locale];
        }

        return $cases;
    }

    public function testAnOfficialLocaleIsReportedAsOfficial(): void
    {
        self::assertTrue($this->checker->check('en')->official);
    }

    public function testAnUnofficialLocaleIsReportedAsSuch(): void
    {
        self::assertFalse($this->checker->check('hr')->official);
    }

    /**
     * Croatian is the live example of a promotion candidate: complete lectionary,
     * gettext catalogue present, but a decreed event still unnamed. If this ever
     * starts passing, hr has become promotable and the resource should say so.
     */
    public function testCroatianIsBlockedOnlyByDecreeNames(): void
    {
        $report = $this->checker->check('hr');

        self::assertFalse($report->ready());
        self::assertSame(['decree_names'], array_map(
            static fn (LocaleReadinessCheck $c): string => $c->name,
            $report->failures()
        ));
    }

    public function testAnUntranslatedLocaleFailsOnLectionaryCorpora(): void
    {
        $failing = array_map(
            static fn (LocaleReadinessCheck $c): string => $c->name,
            $this->checker->check('es')->failures()
        );

        self::assertContains('lectionary_corpora', $failing);
    }

    public function testDecreesThatOnlyModifyAnEventDoNotRequireDecreeReadings(): void
    {
        // StMaryMagdalene_Upgrade (setProperty), StMartha_NameChange (setProperty),
        // StThereseChildJesus_Doctor and StIrenaeus_Doctor (makeDoctor) leave their
        // events in the sanctorale, where the readings already live. Requiring
        // decree readings for them would flag every official locale as incomplete.
        $readings = $this->namedCheck($this->checker->check('en'), 'decree_readings');

        self::assertTrue($readings->passed);
        self::assertNotContains('StMaryMagdalene', $readings->missing);
        self::assertNotContains('StIrenaeus', $readings->missing);
    }

    public function testEnglishNeedsNoGettextCatalogue(): void
    {
        $check = $this->namedCheck($this->checker->check('en'), 'gettext_catalogue');

        self::assertTrue($check->passed);
        self::assertStringContainsString('source language', $check->summary);
    }

    public function testAnUnknownLocaleFailsEverySubstantiveCheck(): void
    {
        $report = $this->checker->check('zz');

        self::assertFalse($report->ready());
        self::assertFalse($report->official);
    }

    public function testCheckOfficialLocalesCoversTheWholeList(): void
    {
        $reports = $this->checker->checkOfficialLocales();

        self::assertCount(count(SupportedLocales::official()), $reports);
        foreach ($reports as $report) {
            self::assertTrue($report->official);
        }
    }

    public function testTheReportSerialisesForTheAdminInterface(): void
    {
        $json = json_decode((string) json_encode($this->checker->check('hr')), true);

        self::assertIsArray($json);
        self::assertSame('hr', $json['locale']);
        self::assertFalse($json['ready']);
        self::assertFalse($json['official']);
        self::assertNotEmpty($json['checks']);
        self::assertArrayHasKey('missing', $json['checks'][0]);
    }

    public function testPluralAgreement(): void
    {
        self::assertSame('1 event has', LocaleReadinessCheck::plural(1, 'event has', 'events have'));
        self::assertSame('2 events have', LocaleReadinessCheck::plural(2, 'event has', 'events have'));
        self::assertSame('0 events have', LocaleReadinessCheck::plural(0, 'event has', 'events have'));
    }

    private function namedCheck(LocaleReadinessReport $report, string $name): LocaleReadinessCheck
    {
        foreach ($report->checks as $check) {
            if ($check->name === $name) {
                return $check;
            }
        }

        self::fail('No check named ' . $name);
    }
}
