<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http\Enum;

use LiturgicalCalendar\Api\Http\Enum\Charset;
use LiturgicalCalendar\Api\Http\Exception\UnsupportedCharsetException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Charset::class)]
final class CharsetTest extends TestCase
{
    public function testCommonCanonicalLabels(): void
    {
        self::assertSame(Charset::UTF8, Charset::fromLabel('utf-8'));
        self::assertSame(Charset::UTF8, Charset::fromLabel('UTF-8'));
        self::assertSame(Charset::UTF8, Charset::fromLabel(' utf-8 '));
        self::assertSame(Charset::UTF16LE, Charset::fromLabel('utf-16'));
        self::assertSame(Charset::UTF16BE, Charset::fromLabel('utf-16be'));
    }

    public function testWindows1252Aliases(): void
    {
        foreach (['windows-1252', 'cp1252', 'iso-8859-1', 'ascii', 'us-ascii', 'latin1', 'l1'] as $alias) {
            self::assertSame(Charset::WINDOWS1252, Charset::fromLabel($alias));
        }
    }

    public function testIsoVariantsAndJapaneseLabels(): void
    {
        self::assertSame(Charset::WINDOWS1250, Charset::fromLabel('iso-8859-2'));
        self::assertSame(Charset::WINDOWS1251, Charset::fromLabel('iso-8859-5'));
        self::assertSame(Charset::SHIFTJIS, Charset::fromLabel('shift_jis'));
        self::assertSame(Charset::SHIFTJIS, Charset::fromLabel('sjis'));
        self::assertSame(Charset::EUCJP, Charset::fromLabel('euc-jp'));
        self::assertSame(Charset::EUCKR, Charset::fromLabel('korean'));
    }

    public function testChineseLabels(): void
    {
        self::assertSame(Charset::GBK, Charset::fromLabel('gb2312'));
        self::assertSame(Charset::GBK, Charset::fromLabel('chinese'));
        self::assertSame(Charset::GB18030, Charset::fromLabel('gb18030'));
        self::assertSame(Charset::BIG5, Charset::fromLabel('big5-hkscs'));
    }

    public function testKoi8Variants(): void
    {
        self::assertSame(Charset::KOI8R, Charset::fromLabel('cskoi8r'));
        self::assertSame(Charset::KOI8U, Charset::fromLabel('koi8-u'));
    }

    public function testMacVariants(): void
    {
        self::assertSame(Charset::MACINTOSH, Charset::fromLabel('mac'));
        self::assertSame(Charset::MACCYRILLIC, Charset::fromLabel('mac-cyrillic'));
    }

    public function testUnsupportedLabelRaises(): void
    {
        $this->expectException(UnsupportedCharsetException::class);
        Charset::fromLabel('not-a-charset');
    }

    public function testEmptyStringLabelRaises(): void
    {
        $this->expectException(UnsupportedCharsetException::class);
        Charset::fromLabel('');
    }

    public function testWhitespaceOnlyLabelRaises(): void
    {
        // fromLabel() trims before matching, so whitespace-only collapses to ''
        // and lands in the default arm.
        $this->expectException(UnsupportedCharsetException::class);
        Charset::fromLabel('   ');
    }
}
