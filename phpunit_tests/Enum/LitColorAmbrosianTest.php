<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitLocale;
use PHPUnit\Framework\TestCase;

final class LitColorAmbrosianTest extends TestCase
{
    public function testMorelloAndBlackCasesExist(): void
    {
        $this->assertSame('morello', LitColor::MORELLO->value);
        $this->assertSame('black', LitColor::BLACK->value);
    }

    public function testMorelloAndBlackAreValidFromValue(): void
    {
        $this->assertSame(LitColor::MORELLO, LitColor::from('morello'));
        $this->assertSame(LitColor::BLACK, LitColor::from('black'));
    }

    public function testI18nItalian(): void
    {
        // No gettext catalog is loaded in unit tests; _() falls through to the
        // source string, so these assert the source strings, not real Italian.
        $this->assertSame('violaceo', LitColor::MORELLO->i18n('it_IT'));
        $this->assertSame('black', LitColor::BLACK->i18n('it_IT'));
    }

    public function testI18nLatin(): void
    {
        $this->assertSame('violaceus', LitColor::MORELLO->i18n(LitLocale::LATIN));
        $this->assertSame('niger', LitColor::BLACK->i18n(LitLocale::LATIN));
    }

    public function testExistingColorsUnchanged(): void
    {
        $this->assertSame('green', LitColor::GREEN->value);
        $this->assertSame('purple', LitColor::PURPLE->value);
    }
}
