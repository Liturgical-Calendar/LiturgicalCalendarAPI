<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\ValidationsPath;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableItem;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The lectionary corpus, and the locale expectations that come with it.
 *
 * Until this, `jsondata/sourcedata/**\/lectionary/` was 95 files no client could ask about, because
 * `CheckableItem` requires a `LitSchema` and none described a lectionary. Same shape as #800: the data
 * exists and nothing lists it.
 */
#[CoversClass(CheckableInventory::class)]
#[CoversClass(CheckableItem::class)]
final class CheckableInventoryLectionaryTest extends TestCase
{
    private static string $savedApiPath = '';

    public static function setUpBeforeClass(): void
    {
        Router::$apiFilePath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
        self::$savedApiPath  = isset(Router::$apiPath) ? Router::$apiPath : '';
        Router::$apiPath     = '';
        CheckableInventory::reset();
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiPath = self::$savedApiPath;
        CheckableInventory::reset();
    }

    public function testTheTenUniversalSectionsAreAdvertised(): void
    {
        $sections = [
            'dominicale_et_festivum_A',
            'dominicale_et_festivum_B',
            'dominicale_et_festivum_C',
            'feriale_per_annum_I',
            'feriale_per_annum_II',
            'feriale_tempus_adventus',
            'feriale_tempus_nativitatis',
            'feriale_tempus_paschatis',
            'feriale_tempus_quadragesimae',
            'sanctorum'
        ];

        foreach ($sections as $section) {
            $item = CheckableInventory::byId("lectionary:roman:{$section}");
            $this->assertNotNull($item, "lectionary:roman:{$section} is not advertised");
            $this->assertSame('folder', $item->kind);
            $this->assertSame(LitSchema::LECTIONARY, $item->schema);
            $this->assertContains('covers', $item->steps);
        }
    }

    public function testFoldersOwnedBySomethingElseUseTheSuffixForm(): void
    {
        $ids = [
            'decrees:roman:lectionary',
            'nation:roman:US:lectionary',
            'diocese:roman:bredad_nl:lectionary',
            'sanctorale:roman:US_2011:lectionary',
            'widerregion:roman:Europe:lectionary'
        ];

        foreach ($ids as $id) {
            $item = CheckableInventory::byId($id);
            $this->assertNotNull($item, "{$id} is not advertised");
            $this->assertSame(LitSchema::LECTIONARY, $item->schema);
        }
    }

    public function testAbsentLectionaryFoldersAreNotAdvertised(): void
    {
        // IT has a national calendar and no nation-level lectionary folder. Advertising it anyway would
        // mean an item whose `exists` step is guaranteed to fail — a red that says nothing.
        $this->assertNull(CheckableInventory::byId('nation:roman:IT:lectionary'));
        $this->assertNull(CheckableInventory::byId('nation:roman:HR:lectionary'));
    }

    public function testTheCoversStepAndTheExpectationNeverDisagree(): void
    {
        foreach (CheckableInventory::all() as $item) {
            $this->assertSame(
                in_array('covers', $item->steps, true),
                null !== $item->expectedLocales,
                "{$item->id}: the covers step and expectedLocales disagree"
            );
        }
    }

    public function testFoldersThatDeclareTheirOwnLocalesCarryNoExpectation(): void
    {
        // A wider region's and a missal's declared locales are *scanned from these very folders*, so
        // comparing the folder against them could only ever pass.
        $this->assertNull(CheckableInventory::byId('widerregion:roman:Europe:i18n')?->expectedLocales);
        $this->assertNull(CheckableInventory::byId('sanctorale:roman:US_2011:i18n')?->expectedLocales);
    }

    public function testFoldersWithAnIndependentAuthorityCarryTheOwnersLocales(): void
    {
        // US.json declares its own `locales`; it is not scanned from the i18n folder.
        $this->assertSame(['en_US'], CheckableInventory::byId('nation:roman:US:i18n')?->expectedLocales);
        $this->assertSame(['en_US'], CheckableInventory::byId('nation:roman:US:lectionary')?->expectedLocales);
        $this->assertSame(['nl_NL'], CheckableInventory::byId('diocese:roman:bredad_nl:lectionary')?->expectedLocales);
    }

    public function testTheRiteLevelCorpusExpectsTheGeneralRomanLocales(): void
    {
        $item = CheckableInventory::byId('lectionary:roman:sanctorum');
        $this->assertNotNull($item);
        // The fully-translated set, not the fourteen gettext folders.
        $this->assertSame(['en', 'fr', 'it', 'la', 'nl'], $item->expectedLocales);
    }

    public function testEveryLectionaryItemIsAFolderCheck(): void
    {
        $lectionary = array_filter(
            CheckableInventory::all(),
            static fn (CheckableItem $i): bool => LitSchema::LECTIONARY === $i->schema
        );

        $this->assertCount(26, $lectionary, 'expected 26 lectionary folders on disk');

        foreach ($lectionary as $item) {
            $this->assertSame('folder', $item->kind);
            $this->assertNotNull($item->expectedLocales);
        }
    }
}
