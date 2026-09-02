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
}
