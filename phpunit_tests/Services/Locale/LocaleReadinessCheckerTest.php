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

    /**
     * The blank-readings probe is advisory: it reports a real content gap that four
     * of the five official locales currently have, so gating on it would fail them
     * all at once. It must therefore never influence `ready()`.
     */
    public function testAdvisoryChecksAreReportedButDoNotGate(): void
    {
        $report = $this->checker->check('fr');

        self::assertTrue($report->ready(), 'an advisory failure must not make a locale unready');
        self::assertNotEmpty($report->advisories());
        self::assertSame(['decree_readings_populated'], array_map(
            static fn (LocaleReadinessCheck $c): string => $c->name,
            $report->advisories()
        ));
        self::assertSame([], $report->failures());
    }

    public function testAdvisoryChecksAreFlaggedInTheSerialisedReport(): void
    {
        /** @var array{checks: list<array{name: string, advisory: bool}>} $json */
        $json     = json_decode((string) json_encode($this->checker->check('fr')), true);
        $advisory = array_column($json['checks'], 'advisory', 'name');

        self::assertTrue($advisory['decree_readings_populated']);
        self::assertFalse($advisory['decree_names']);
    }

    /**
     * An unreadable decree corpus must fail, not pass vacuously.
     *
     * Before this guard, a missing or corrupt decrees.json produced an empty event
     * list, every downstream probe passed with "all 0 newly created events ...",
     * and the locale reported READY — a silent false pass inside the tool built to
     * prevent silent false passes.
     */
    #[DataProvider('unreadableCorpora')]
    public function testAnUnreadableDecreeCorpusIsNotReady(string $contents): void
    {
        $root = $this->rootWithDecrees($contents);

        try {
            $report = ( new LocaleReadinessChecker($root) )->check('en');

            self::assertFalse($report->ready(), 'an unreadable decrees.json must not report ready');
            self::assertContains('decree_source', array_map(
                static fn (LocaleReadinessCheck $c): string => $c->name,
                $report->failures()
            ));
        } finally {
            self::removeTree($root);
        }
    }

    /** @return array<string, array{string}> */
    public static function unreadableCorpora(): array
    {
        return [
            'absent'       => [''],
            'invalid json' => ['{ this is not json'],
            'not an array' => ['"a bare string"'],
        ];
    }

    public function testAReadableCorpusPassesTheSourceCheck(): void
    {
        $source = $this->checker->check('en');

        foreach ($source->checks as $check) {
            if ($check->name === 'decree_source') {
                self::assertTrue($check->passed);
                return;
            }
        }

        self::fail('no decree_source check was produced');
    }

    /**
     * `describe()` must not report a failed advisory as a passed check.
     */
    public function testDescribeCountsOnlyChecksThatActuallyPassed(): void
    {
        $report = $this->checker->check('fr');
        $passed = count(array_filter($report->checks, static fn (LocaleReadinessCheck $c): bool => $c->passed));

        self::assertTrue($report->ready());
        self::assertNotSame($passed, count($report->checks), 'fr is expected to have an advisory failure');

        $described = $report->describe();
        self::assertStringContainsString(sprintf('%d of %d checks passed', $passed, count($report->checks)), $described);
        self::assertStringContainsString('advisory not met', $described);
    }

    public function testDescribeOmitsTheAdvisoryClauseWhenThereIsNothingToReport(): void
    {
        $report = new LocaleReadinessReport('xx', false, [
            new LocaleReadinessCheck('a', true, 'fine'),
            new LocaleReadinessCheck('b', true, 'fine', [], true)
        ]);

        self::assertSame('xx: ready (2 of 2 checks passed)', $report->describe());
    }

    /**
     * A copy of the real data tree with decrees.json replaced, so every other probe
     * still passes and the corpus check is the only variable.
     */
    private function rootWithDecrees(string $contents): string
    {
        $root = sys_get_temp_dir() . '/litcal-readiness-' . bin2hex(random_bytes(6)) . '/';
        $repo = dirname(__DIR__, 3) . '/';

        self::copyTree($repo . 'jsondata', $root . 'jsondata');
        self::copyTree($repo . 'i18n', $root . 'i18n');

        $decrees = $root . 'jsondata/sourcedata/rite/roman/decrees/decrees.json';
        if ($contents === '') {
            unlink($decrees);
        } else {
            file_put_contents($decrees, $contents);
        }

        return $root;
    }

    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, 0o755, true);
        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $it */
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $target = $to . DIRECTORY_SEPARATOR . $it->getSubPathName();
            if ($item->isDir()) {
                mkdir($target, 0o755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
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
