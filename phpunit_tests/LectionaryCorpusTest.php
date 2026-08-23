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
}
