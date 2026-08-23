<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\ValidationsPath;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckableItem::class)]
final class CheckableItemExpectedLocalesTest extends TestCase
{
    public function testAnItemWithoutAnExpectationSerialisesNull(): void
    {
        $item = new CheckableItem(
            'x:roman',
            'file',
            Rite::ROMAN,
            null,
            'X',
            LitSchema::NATIONAL,
            ['exists', 'parses', 'validates'],
            '/tmp/x'
        );

        $json = $item->jsonSerialize();
        $this->assertNull($json['expected_locales']);
        $this->assertNotContains('covers', $json['steps']);
    }

    public function testAnItemWithAnExpectationSerialisesIt(): void
    {
        $item = new CheckableItem(
            'x:roman:i18n',
            'folder',
            Rite::ROMAN,
            null,
            'X translations',
            LitSchema::I18N,
            ['exists', 'parses', 'validates', 'covers'],
            '/tmp/x/i18n',
            ['en', 'it']
        );

        $json = $item->jsonSerialize();
        $this->assertSame(['en', 'it'], $json['expected_locales']);
        $this->assertContains('covers', $json['steps']);
    }

    public function testTheServerSidePathStaysOffTheWire(): void
    {
        $item = new CheckableItem(
            'x:roman:i18n',
            'folder',
            Rite::ROMAN,
            null,
            'X translations',
            LitSchema::I18N,
            ['exists', 'parses', 'validates', 'covers'],
            '/tmp/x/i18n',
            ['en']
        );

        $this->assertArrayNotHasKey('path', $item->jsonSerialize());
    }
}
