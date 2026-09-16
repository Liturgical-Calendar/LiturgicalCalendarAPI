<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Docs;

use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

/**
 * docs/decrees/notitiae-register.json is a hand-maintained register; this pins it to its schema so a
 * malformed entry (bad ISO code, unknown kind, missing citation) fails CI rather than rotting silently.
 */
final class NotitiaeRegisterSchemaTest extends TestCase
{
    private const string DOCS = __DIR__ . '/../../docs/decrees/';

    public function testRegisterValidatesAgainstSchema(): void
    {
        $schema = Schema::import(self::DOCS . 'notitiae-register.schema.json');
        $data   = json_decode((string) file_get_contents(self::DOCS . 'notitiae-register.json'));
        $schema->in($data);
        $this->addToAssertionCount(1);
    }

    public function testIdsAreUniqueAndSortedByDateThenId(): void
    {
        $entries = json_decode((string) file_get_contents(self::DOCS . 'notitiae-register.json'), true);
        assert(is_array($entries));
        $ids = array_column($entries, 'id');
        $this->assertSame($ids, array_values(array_unique($ids)), 'duplicate id');
        $keys   = array_map(static fn(array $e): string => ( $e['date'] ?? '9999-99-99' ) . ' ' . $e['id'], $entries);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys, 'register must be sorted by date then id (run scripts/notitiae/merge.py)');
    }

    public function testEntryWithUnknownKindIsRejected(): void
    {
        $schema = Schema::import(self::DOCS . 'notitiae-register.schema.json');
        $entry  = json_decode('{"id":"N1976-CD-1-76","source":{"volume":12,"year":1976,"issue":"116","pdf":"Notitiae-116-1976.pdf",'
            . '"pdf_pages":[5,5],"printed_pages":[93,93],"url":"https://www.cultodivino.va/x.pdf"},"protocol":"CD 1/76",'
            . '"date":"1976-01-01","kind":"bogus","target":{"level":"national","nation":"IT","diocese":null,"diocese_id":null,'
            . '"institute":null},"celebration":null,"summary_en":"x","excerpt":"x",'
            . '"api":{"calendar_implemented":true,"status":"recorded","applied_in":null},"needs_review":false}');
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        $schema->in([$entry]);
    }
}
