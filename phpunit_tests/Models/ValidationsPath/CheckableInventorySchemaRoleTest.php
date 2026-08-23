<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\ValidationsPath;

use LiturgicalCalendar\Api\Enum\SchemaRole;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableItem;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `/validations` checks source data, and this is where that sentence stops being a convention.
 *
 * An item pointed at an output schema would validate a stored file against the shape of an API
 * response: it would pass or fail for reasons that have nothing to do with whether the source data is
 * correct — the wrong-green this endpoint exists to remove rather than produce. The distinction is
 * otherwise drawn only by hand, which is exactly how a design came to propose loosening `CommonDef`'s
 * output `Readings` so that a source check could reuse it.
 */
#[CoversClass(CheckableInventory::class)]
#[CoversClass(CheckableItem::class)]
final class CheckableInventorySchemaRoleTest extends TestCase
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

    public function testEveryCheckableItemUsesASourceSchema(): void
    {
        $items = CheckableInventory::all();
        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $this->assertSame(
                SchemaRole::SOURCE,
                $item->schema->role(),
                "Checkable item {$item->id} validates against {$item->schema->name()}, which is not a source schema"
            );
        }
    }
}
