<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

/**
 * Every lectionary file in the repository, against `Lectionary.json`.
 *
 * A data-integrity test rather than a unit test: it is what proves the schema fits the corpus, and it
 * is the test that fails first if a lectionary file grows a shape the schema does not describe.
 *
 * It asserts *structure*, deliberately not completeness. 85% of entries carry at least one empty-string
 * reading, and `Lectionary.json` admits them on purpose — filling them in is #712. A green here means
 * the structure is in place, which is exactly what the inventory item's label says.
 *
 * One cross-file invariant is asserted on top of that: within a single lectionary folder, every locale
 * file must carry the same `event_key` SET. Validating each file in isolation cannot see a key that one
 * locale spells differently from the rest, and that is exactly how `StsIoannemBrebeuf` survived in the
 * Croatian sanctorale — a well-formed file whose key named a celebration nothing declares, orphaning the
 * readings in one direction and leaving Croatian readers with none in the other (#969). Note that the
 * comparison is over sets, never counts: all six sanctorale files held exactly 210 keys throughout.
 */
#[CoversNothing]
final class LectionaryCorpusTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Router::$apiFilePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    }

    /** @return list<string> Every lectionary JSON file under jsondata/sourcedata. */
    private static function lectionaryFiles(): array
    {
        $root  = dirname(__DIR__) . '/jsondata/sourcedata';
        $found = [];

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iter as $file) {
            /** @var \SplFileInfo $file */
            // A `lectionary` segment anywhere in the path, not just the immediate parent: the calendar
            // tiers store `lectionary/{locale}.json`, while the universal corpus nests one level deeper
            // as `lectionary/{section}/{locale}.json`.
            if (!$file->isFile() || 'json' !== $file->getExtension()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (in_array('lectionary', explode(DIRECTORY_SEPARATOR, $relative), true)) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);
        return $found;
    }

    public function testTheCorpusIsFound(): void
    {
        $this->assertGreaterThan(90, count(self::lectionaryFiles()), 'expected around 95 lectionary files');
    }

    public function testEveryLectionaryFileValidatesAgainstTheLectionarySchema(): void
    {
        $schema   = Schema::import(LitSchema::LECTIONARY->path());
        $failures = [];

        foreach (self::lectionaryFiles() as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents, "could not read {$file}");

            $data  = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            $short = basename(dirname($file)) . '/' . basename($file);

            try {
                $schema->in($data);
            } catch (\Throwable $e) {
                $failures[] = $short . ': ' . $e->getMessage();
            }
        }

        $this->assertSame([], $failures, "Lectionary files failed schema validation:\n" . implode("\n", $failures));
    }

    /**
     * Within one lectionary folder, every locale file must declare the same set of `event_key`s.
     *
     * A folder is one section of one corpus — `lectionary/sanctorum`, `lectionary/feriale_per_annum_I`,
     * a nation's `lectionary/`, a diocese's — and its files are the same readings in different languages.
     * A key present in some of them and absent from the others is either a typo or an untranslated entry;
     * both want fixing, and neither is visible to per-file schema validation.
     *
     * Folders holding a single file are skipped: there is nothing to compare them against.
     */
    public function testEveryLocaleFileInALectionaryFolderDeclaresTheSameKeySet(): void
    {
        /** @var array<string, array<string, list<string>>> $folders folder => file => sorted keys */
        $folders = [];

        foreach (self::lectionaryFiles() as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents, "could not read {$file}");

            /** @var array<string, mixed> $data */
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            $keys = array_keys($data);
            sort($keys);
            $folders[dirname($file)][basename($file)] = $keys;
        }

        $this->assertNotEmpty($folders, 'no lectionary folders were discovered');

        $root = dirname(__DIR__) . DIRECTORY_SEPARATOR;

        // Pin the one folder this invariant was written for, by name. A count guard alone is not
        // enough: 22 folders qualify, but 11 of them are small calendar-tier folders, so discovery
        // could lose `sanctorum` — the six-locale corpus where #969 happened — and still clear any
        // plausible threshold, leaving the test green having never compared what it exists to guard.
        $sanctorum = $root . 'jsondata/sourcedata/rite/roman/lectionary/sanctorum';
        $this->assertArrayHasKey($sanctorum, $folders, 'the rite-level sanctorale folder was not discovered');
        $this->assertGreaterThan(1, count($folders[$sanctorum]), 'the rite-level sanctorale folder must hold several locale files');

        $failures = [];
        $compared = 0;

        foreach ($folders as $folder => $files) {
            if (count($files) < 2) {
                continue;
            }

            ++$compared;

            $union = [];
            foreach ($files as $keys) {
                $union = array_merge($union, $keys);
            }
            $union = array_unique($union);
            sort($union);

            foreach ($union as $key) {
                $present = array_keys(array_filter($files, static fn (array $keys): bool => in_array($key, $keys, true)));

                if (count($present) === count($files)) {
                    continue;
                }

                $missing    = array_values(array_diff(array_keys($files), $present));
                $failures[] = sprintf(
                    '%s: "%s" is declared by [%s] but missing from [%s]',
                    str_replace($root, '', $folder),
                    $key,
                    implode(', ', $present),
                    implode(', ', $missing)
                );
            }
        }

        $this->assertGreaterThan(15, $compared, 'expected around 22 multi-locale lectionary folders');

        $this->assertSame(
            [],
            $failures,
            "Lectionary locale files within a folder disagree on their event_key set:\n" . implode("\n", $failures)
        );
    }

    /**
     * True when every reading in one Mass block is present as an empty string.
     *
     * 85% of the corpus is unfilled placeholders (#712), and two empty blocks are trivially
     * equal, so the duplication check below has to be able to tell "not yet filled in" from
     * "filled in wrongly".
     *
     * Anything that is not an empty string makes this false — a populated reading, but equally
     * a null, a number or a nested array — so the entry is COMPARED rather than skipped. The
     * skip is the only way an entry escapes this guard, so it has to be the narrow case: a block
     * of nulls is not a placeholder this test understands, and passing silently over one would
     * make the guard report an untruth. The schema admits only strings, so the non-string arms
     * are unreachable against today's corpus and cost nothing.
     */
    private static function readingsAreAllEmpty(mixed $block): bool
    {
        if (!is_array($block)) {
            return false;
        }

        foreach ($block as $reading) {
            if (!is_string($reading) || '' !== $reading) {
                return false;
            }
        }

        return true;
    }

    /**
     * An entry that carries both a `vigil` and a `day` block must not hold the same readings in both.
     *
     * A Vigil Mass has its own proper readings; that is what makes it a vigil rather than an
     * anticipation of the day. `NativityJohnBaptist` held the Vigil's readings in BOTH blocks in
     * all six locales, so the Mass during the Day served Jeremiah 1:4-10 instead of Isaiah 49:1-6
     * (#971).
     *
     * This is the check #969 could not be. #969 compares `event_key` SETS across the locale files
     * of a folder, so a defect that is uniform across all six files and internal to one entry
     * passes it by construction — every file agreed, and every file was wrong.
     *
     * Entries whose blocks are both entirely empty are skipped: `Christmas` and `Pentecost` are in
     * that state in seventeen files, and flagging them would report #712 as if it were this defect.
     *
     * The two blocks are compared by key-sorted value, not by literal identity. PHP's `===` on
     * associative arrays is order-sensitive, so a duplicate whose keys had merely been written in
     * a different order would slip past — a false negative in the one direction this guard exists
     * to prevent.
     */
    public function testNoEntryHoldsTheSameReadingsForItsVigilAndItsDay(): void
    {
        $root     = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $failures = [];

        /** @var array<string, list<string>> $comparedIn event_key => the files it was compared in */
        $comparedIn = [];

        foreach (self::lectionaryFiles() as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents, "could not read {$file}");

            /** @var array<string, mixed> $data */
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            foreach ($data as $key => $entry) {
                if (!is_array($entry) || !array_key_exists('vigil', $entry) || !array_key_exists('day', $entry)) {
                    continue;
                }

                if (self::readingsAreAllEmpty($entry['vigil']) && self::readingsAreAllEmpty($entry['day'])) {
                    continue;
                }

                $relative           = str_replace($root, '', $file);
                $comparedIn[$key][] = $relative;

                $vigil = $entry['vigil'];
                $day   = $entry['day'];

                if (is_array($vigil) && is_array($day)) {
                    ksort($vigil);
                    ksort($day);
                }

                if ($vigil === $day) {
                    $failures[] = sprintf(
                        '%s: "%s" repeats its vigil readings verbatim as its day readings',
                        $relative,
                        $key
                    );
                }
            }
        }

        // Pin the entry this invariant was written for, and pin it per FILE rather than once
        // globally: a name recorded from any one locale would leave the guard green while five
        // of the six sanctorale files had silently stopped being compared.
        $sanctorum     = str_replace('/', DIRECTORY_SEPARATOR, 'jsondata/sourcedata/rite/roman/lectionary/sanctorum/');
        $comparedFiles = $comparedIn['NativityJohnBaptist'] ?? [];

        foreach (['en', 'fr', 'hr', 'it', 'la', 'nl'] as $locale) {
            $this->assertContains(
                $sanctorum . $locale . '.json',
                $comparedFiles,
                sprintf('the sanctorale %s file never had its NativityJohnBaptist vigil/day pair compared', $locale)
            );
        }

        $this->assertSame(
            [],
            $failures,
            "Lectionary entries repeat one Mass's readings for both the vigil and the day:\n" . implode("\n", $failures)
        );
    }
}
