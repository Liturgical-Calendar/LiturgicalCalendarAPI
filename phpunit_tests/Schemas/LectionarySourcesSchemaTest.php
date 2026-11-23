<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\SchemaRole;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Exception as SchemaException;
use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;

/**
 * The lectionary sources sidecar (#1074): each bishops' conference site that publishes the
 * day's readings, its URL patterns and published range, and the page leftovers that cannot be
 * computed from the date.
 */
final class LectionarySourcesSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
    }

    private static function schema(): Schema
    {
        return Schema::import(LitSchema::LECTIONARY_SOURCES->path());
    }

    /**
     * @return \stdClass a minimal valid document
     */
    private static function minimal(): \stdClass
    {
        $doc = json_decode(
            '{"usccb":{"day_page":"https://bible.usccb.org/bible/readings/{MM}{DD}{YY}.cfm",'
            . '"linked_page":"https://bible.usccb.org/bible/readings/{page}","first_date":"2013-01-01",'
            . '"last_date":"2027-12-01","calendars":["US"],"pages":{}}}'
        );
        self::assertInstanceOf(\stdClass::class, $doc);
        return $doc;
    }

    public function testSourceFileValidatesAgainstItsSchema(): void
    {
        $data = json_decode((string) file_get_contents(JsonData::LECTIONARY_SOURCES_FILE->path()));
        self::schema()->in($data);
        $this->addToAssertionCount(1);
    }

    public function testSchemaRoleIsSource(): void
    {
        $this->assertSame(SchemaRole::SOURCE, LitSchema::LECTIONARY_SOURCES->role());
    }

    public function testMinimalDocumentValidates(): void
    {
        self::schema()->in(self::minimal());
        $this->addToAssertionCount(1);
    }

    public function testRejectsUnknownCycleKey(): void
    {
        $doc                        = self::minimal();
        $doc->usccb->pages->StBlase = (object) ['III' => '1'];
        $this->expectException(SchemaException::class);
        self::schema()->in($doc);
    }

    public function testRejectsEmptyCalendars(): void
    {
        $doc                   = self::minimal();
        $doc->usccb->calendars = [];
        $this->expectException(InvalidValue::class);
        self::schema()->in($doc);
    }

    public function testEveryPagesKeyIsAKnownEventKey(): void
    {
        $known = [];
        $root  = Router::$apiFilePath . 'jsondata/sourcedata/rite/roman';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'json' || !str_contains($file->getPathname(), '/lectionary/')) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($file->getPathname()), true);
            if (is_array($decoded)) {
                foreach (array_keys($decoded) as $key) {
                    $known[(string) $key] = true;
                }
            }
        }
        $this->assertNotEmpty($known);

        $data = json_decode((string) file_get_contents(JsonData::LECTIONARY_SOURCES_FILE->path()), true);
        $this->assertIsArray($data);
        $unknown = [];
        foreach ($data as $sourceKey => $source) {
            $this->assertIsArray($source);
            $pages = $source['pages'] ?? [];
            $this->assertIsArray($pages);
            foreach (array_keys($pages) as $eventKey) {
                $base = preg_replace('/_vigil$/', '', (string) $eventKey);
                if (!isset($known[$base])) {
                    $unknown[] = "{$sourceKey}: {$eventKey}";
                }
            }
        }
        $this->assertSame([], $unknown, 'pages keys that are no lectionary event_key');
    }
}
