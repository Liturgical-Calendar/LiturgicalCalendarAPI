<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitLocale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LitColor::class)]
final class LitColorTest extends TestCase
{
    public function testCases(): void
    {
        self::assertSame('green', LitColor::GREEN->value);
        self::assertSame('purple', LitColor::PURPLE->value);
        self::assertSame('white', LitColor::WHITE->value);
        self::assertSame('red', LitColor::RED->value);
        self::assertSame('rose', LitColor::ROSE->value);
    }

    public function testIsValid(): void
    {
        self::assertTrue(LitColor::isValid('green'));
        self::assertTrue(LitColor::isValid('rose'));
        self::assertFalse(LitColor::isValid('blue'));
    }

    public function testI18nLatin(): void
    {
        self::assertSame('viridis', LitColor::GREEN->i18n(LitLocale::LATIN));
        self::assertSame('purpura', LitColor::PURPLE->i18n(LitLocale::LATIN));
        self::assertSame('albus', LitColor::WHITE->i18n(LitLocale::LATIN_PRIMARY_LANGUAGE));
        self::assertSame('ruber', LitColor::RED->i18n(LitLocale::LATIN));
        self::assertSame('rosea', LitColor::ROSE->i18n(LitLocale::LATIN));
    }

    public function testI18nNonLatinReturnsAtLeastOriginalValueWhenNoTranslation(): void
    {
        // No gettext catalog is loaded in unit tests; _() falls through to the
        // original string. We only care that the call doesn't blow up.
        self::assertSame('green', LitColor::GREEN->i18n('en_US'));
        self::assertSame('purple', LitColor::PURPLE->i18n('en_US'));
        self::assertSame('white', LitColor::WHITE->i18n('en_US'));
        self::assertSame('red', LitColor::RED->i18n('en_US'));
        self::assertSame('rose', LitColor::ROSE->i18n('en_US'));
    }
}
