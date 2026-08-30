<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Services\Locale\LocaleReadinessChecker;
use LiturgicalCalendar\Api\Services\SupportedLocales;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The `locale_readiness` block of `/health` is how an operator finds out that a locale the
 * API already advertises as officially supported has lost a resource it needs.
 *
 * That matters more than it sounds. An official locale is served STRICTLY —
 * `ReadingsMap::getReadings()` throws rather than degrading — so the drift this block
 * reports is a calendar about to answer 500, not one that will merely render a gap (#904).
 *
 * `composer lint:locales` already proves the COMMITTED data is complete. What these tests
 * pin is the block's own contract, which CI cannot: the shape monitoring parses, the
 * advisory tier never gating, and the warning branch actually firing.
 *
 * The warning branch is driven by pointing a checker at a deliberately incomplete tree,
 * never by rewriting `jsondata/supportedLocales.json`. Rewriting it would work, but the file
 * is committed source data read by a process-wide memoized static: a fatal anywhere in the
 * run would leave it replaced in the working tree, and every later test in the process would
 * be reading a list this one invented.
 */
#[CoversClass(Health::class)]
#[CoversClass(LocaleReadinessChecker::class)]
final class HealthLocaleReadinessTest extends TestCase
{
    /** @var list<string> Temporary trees to remove, deepest paths first. */
    private array $tempRoots = [];

    protected function setUp(): void
    {
        SupportedLocales::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempRoots as $root) {
            self::removeTree($root);
        }
        $this->tempRoots = [];

        SupportedLocales::reset();
    }

    public function testTheBlockCarriesTheKeysMonitoringParses(): void
    {
        $block = Health::buildLocaleReadinessStatus();

        self::assertContains($block['status'], ['ok', 'warning']);
        self::assertNotSame('', $block['message'], 'the block must explain itself, not just flag a state');
        self::assertSame(SupportedLocales::official(), $block['official']);
    }

    /**
     * On the committed data every official locale is complete, so the block reports `ok` and
     * names nothing. If this ever fails, `composer lint:locales` names the gap.
     */
    public function testTheCommittedDataReportsOk(): void
    {
        $block = Health::buildLocaleReadinessStatus();

        self::assertSame('ok', $block['status'], (string) json_encode($block['not_ready']));
        self::assertSame([], $block['not_ready']);
    }

    /**
     * The blank-decree-readings probe is advisory, and four of the five official locales fail
     * it on real data. It must be REPORTED — that is what the tier is for — while leaving the
     * status `ok`. Making it a warning would leave /health permanently yellow and train
     * operators to ignore the field.
     */
    public function testAdvisoryFailuresAreReportedWithoutRaisingTheStatus(): void
    {
        $block = Health::buildLocaleReadinessStatus();

        self::assertSame('ok', $block['status']);
        self::assertNotEmpty($block['advisories'], 'the advisory tier is expected to have something to say');

        foreach ($block['advisories'] as $locale => $names) {
            self::assertContains($locale, $block['official']);
            self::assertNotEmpty($names);
        }
    }

    /**
     * The warning branch, and the one that must discriminate: exactly one official locale has
     * lost exactly one lectionary corpus, and the block must implicate that locale and no
     * other. A block that reported "something is wrong" without naming which calendar is
     * about to answer 500 would not be worth polling.
     */
    public function testAnOfficialLocaleThatLostAResourceIsNamed(): void
    {
        $official = SupportedLocales::official();
        $victim   = $official[count($official) - 1];

        $root = $this->mirrorWithoutSanctorum($victim);

        $block = Health::buildLocaleReadinessStatus(new LocaleReadinessChecker($root));

        self::assertSame('warning', $block['status']);
        self::assertSame([$victim], array_keys($block['not_ready']));
        self::assertSame(['lectionary_corpora'], $block['not_ready'][$victim]);
        self::assertSame($official, $block['official'], 'the block still reports the whole list it checked');
        self::assertStringContainsString($victim . ' (lectionary_corpora)', $block['message']);
        self::assertStringContainsString('supportedLocales.json', $block['message']);
    }

    /**
     * Nothing to look at is not the same as nothing wrong.
     *
     * A deployment whose data tree is absent — a half-finished rsync, a bad document root —
     * must report `warning`, never `ok`. The checker exists to refuse silent false passes,
     * and a block that reported ready because it could not look would be one.
     */
    public function testAnEmptyDataTreeIsAWarningRatherThanOk(): void
    {
        $root  = $this->tempRoot();
        $block = Health::buildLocaleReadinessStatus(new LocaleReadinessChecker($root));

        self::assertSame('warning', $block['status']);
        self::assertSame(SupportedLocales::official(), array_keys($block['not_ready']));
        foreach ($block['not_ready'] as $failures) {
            self::assertContains('decree_source', $failures, 'an unreadable corpus must fail loudly, not vacuously');
        }
    }

    /**
     * A tree that is the real one in every respect except that $locale has lost one of its
     * ten lectionary corpora.
     *
     * Built out of symlinks rather than copies: the checker only ever stats, globs and reads,
     * so a mirror is indistinguishable from the real tree to it, and this stays cheap enough
     * to run per test.
     */
    private function mirrorWithoutSanctorum(string $locale): string
    {
        $repo = dirname(__DIR__) . '/';
        $root = $this->tempRoot();

        symlink($repo . 'i18n', $root . 'i18n');

        $roman = $root . 'jsondata/sourcedata/rite/roman/';
        mkdir($roman . 'lectionary', 0o755, true);

        foreach (['decrees', 'missals', 'calendars'] as $sibling) {
            symlink($repo . 'jsondata/sourcedata/rite/roman/' . $sibling, $roman . $sibling);
        }

        foreach (glob($repo . 'jsondata/sourcedata/rite/roman/lectionary/*', GLOB_ONLYDIR) ?: [] as $corpus) {
            $name = basename($corpus);

            if ($name !== 'sanctorum') {
                symlink($corpus, $roman . 'lectionary/' . $name);
                continue;
            }

            // The one corpus that is rebuilt file by file, minus the victim locale.
            mkdir($roman . 'lectionary/sanctorum', 0o755, true);
            foreach (glob($corpus . '/*.json') ?: [] as $file) {
                if (basename($file) === $locale . '.json') {
                    continue;
                }
                symlink($file, $roman . 'lectionary/sanctorum/' . basename($file));
            }
        }

        return $root;
    }

    private function tempRoot(): string
    {
        $root = sys_get_temp_dir() . '/litcal-health-readiness-' . bin2hex(random_bytes(6)) . '/';
        mkdir($root, 0o755, true);
        $this->tempRoots[] = $root;

        return $root;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            // SKIP_DOTS plus no FOLLOW_SYMLINKS: the mirror is made of symlinks into the real
            // source tree, and descending into them would delete the repository's own data.
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink() || !$item->isDir()) {
                unlink($item->getPathname());
                continue;
            }
            rmdir($item->getPathname());
        }

        rmdir($dir);
    }
}
