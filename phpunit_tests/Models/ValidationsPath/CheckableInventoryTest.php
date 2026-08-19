<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\ValidationsPath;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableItem;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The inventory of source data the API can validate (#806 step A).
 *
 * Its reason for existing is that the layout was written down in several places that had to be
 * edited in lockstep — the client's path constants, `Health`'s schema table, the `.vscode` globs —
 * and nothing failed loudly when they diverged. These tests pin the two properties that make one
 * list safe to rely on: it resolves everything the old table resolved, and its `region` mapping is
 * exactly what the client's scope predicate assumes.
 */
#[CoversClass(CheckableInventory::class)]
#[CoversClass(CheckableItem::class)]
final class CheckableInventoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // JsonData cases build filesystem paths from this prefix.
        Router::$apiFilePath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
    }

    /**
     * The arms of `Health::getPathToSchemaFile()` that name source-data FILES, pasted here as the
     * oracle. The refactor in a later task is proved equivalent against this rather than assumed.
     * The route arms of that table are not source data and stay where they are.
     *
     * @return array<string, LitSchema>
     */
    private static function legacyFileTable(): array
    {
        return [
            JsonData::MISSALS_FOLDER->value . '/propriumdetempore/propriumdetempore.json'                 => LitSchema::PROPRIUMDETEMPORE,
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_1970/propriumdesanctis_1970.json'       => LitSchema::PROPRIUMDESANCTIS,
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_2002/propriumdesanctis_2002.json'       => LitSchema::PROPRIUMDESANCTIS,
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_2008/propriumdesanctis_2008.json'       => LitSchema::PROPRIUMDESANCTIS,
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_IT_1983/propriumdesanctis_IT_1983.json' => LitSchema::PROPRIUMDESANCTIS,
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_US_2011/propriumdesanctis_US_2011.json' => LitSchema::PROPRIUMDESANCTIS,
            JsonData::AMBROSIAN_TEMPORALE_FILE->value                                                     => LitSchema::PROPRIUMDETEMPORE,
            JsonData::AMBROSIAN_SANCTORALE_FILE->value                                                    => LitSchema::PROPRIUMDESANCTIS,
        ];
    }

    public function testItResolvesEverythingTheOldFileTableResolved(): void
    {
        foreach (self::legacyFileTable() as $path => $schema) {
            $item = CheckableInventory::byPath($path);
            self::assertNotNull($item, "no inventory entry for {$path}");
            self::assertSame($schema, $item->schema, "wrong schema for {$path}");
        }
    }

    public function testEveryItemIsFullyPopulatedAndIdsAreUnique(): void
    {
        $ids = [];
        foreach (CheckableInventory::all() as $item) {
            self::assertNotSame('', $item->id);
            self::assertContains($item->kind, ['file', 'folder']);
            self::assertNotSame('', $item->label);
            self::assertNotSame('', $item->path);
            self::assertSame(['exists', 'parses', 'validates'], $item->steps);
            $ids[] = $item->id;
        }
        self::assertSame(array_unique($ids), $ids, 'inventory ids must be unique');
    }

    public function testItHoldsNineFilesAndNineFolders(): void
    {
        $kinds = array_count_values(array_map(
            static fn (CheckableItem $i): string => $i->kind,
            CheckableInventory::all()
        ));

        self::assertSame(9, $kinds['file']);
        self::assertSame(9, $kinds['folder']);
    }

    public function testTheFiveMissalsWithASanctoraleArePresentAndTheOthersAbsent(): void
    {
        $ids = array_map(static fn (CheckableItem $i): string => $i->id, CheckableInventory::all());

        foreach (['EDITIO_TYPICA_1970', 'EDITIO_TYPICA_2002', 'EDITIO_TYPICA_2008', 'US_2011', 'IT_1983'] as $missalId) {
            self::assertContains("sanctorale:roman:{$missalId}", $ids);
        }
        foreach (['EDITIO_TYPICA_1971', 'EDITIO_TYPICA_1975', 'IT_2020', 'NL_1978', 'CA_2011', 'CA_2016'] as $missalId) {
            self::assertNotContains("sanctorale:roman:{$missalId}", $ids);
        }
    }

    /**
     * The mapping the client's scope predicate depends on: `null` means "applies to the whole rite",
     * a nation code means "only that nation's calendar". Deliberately NOT produceMetadata()'s 'VA',
     * which is a nation code and would be simply false on the Ambrosian items.
     */
    public function testRegionIsNullForUniversalItemsAndANationCodeForNationalEditions(): void
    {
        self::assertNull(CheckableInventory::byId('temporale:roman')?->region);
        self::assertNull(CheckableInventory::byId('sanctorale:roman:EDITIO_TYPICA_1970')?->region);
        self::assertNull(CheckableInventory::byId('temporale:ambrosian')?->region);
        self::assertNull(CheckableInventory::byId('sanctorale:ambrosian')?->region);

        self::assertSame('US', CheckableInventory::byId('sanctorale:roman:US_2011')?->region);
        self::assertSame('IT', CheckableInventory::byId('sanctorale:roman:IT_1983')?->region);
    }

    /**
     * The client's predicate, applied here so the data is proved fit for it:
     *   item.rite === rite && (item.region === null || item.region === nation)
     */
    public function testTheClientScopePredicateSelectsTheRightItems(): void
    {
        $inScope = static fn (CheckableItem $i, Rite $rite, ?string $nation): bool
            => $i->rite === $rite && ( $i->region === null || $i->region === $nation );

        $usa = array_map(
            static fn (CheckableItem $i): string => $i->id,
            array_values(array_filter(
                CheckableInventory::all(),
                static fn (CheckableItem $i): bool => $inScope($i, Rite::ROMAN, 'US')
            ))
        );
        self::assertContains('sanctorale:roman:US_2011', $usa);
        self::assertNotContains('sanctorale:roman:IT_1983', $usa);
        self::assertContains('temporale:roman', $usa);

        $ambrosian = array_map(
            static fn (CheckableItem $i): string => $i->id,
            array_values(array_filter(
                CheckableInventory::all(),
                static fn (CheckableItem $i): bool => $inScope($i, Rite::AMBROSIAN, null)
            ))
        );
        sort($ambrosian);
        self::assertSame(
            ['sanctorale:ambrosian', 'sanctorale:ambrosian:i18n', 'temporale:ambrosian', 'temporale:ambrosian:i18n'],
            $ambrosian
        );
    }

    public function testTheSerializedFormNeverCarriesAPath(): void
    {
        foreach (CheckableInventory::all() as $item) {
            $encoded = json_encode($item, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('jsondata', $encoded, "item {$item->id} leaked a path");
            self::assertArrayNotHasKey('path', (array) json_decode($encoded, true, 512, JSON_THROW_ON_ERROR));
        }
    }
}
