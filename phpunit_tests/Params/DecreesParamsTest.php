<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Params;

use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Params\DecreesParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DecreesParams::class)]
final class DecreesParamsTest extends TestCase
{
    public function testEmptyParamsLeavesLocaleNull(): void
    {
        $params = new DecreesParams([]);

        self::assertNull($params->Locale);
    }

    public function testValidLocaleIsReducedToPrimaryLanguage(): void
    {
        $params = new DecreesParams(['locale' => 'en_US']);

        self::assertSame('en', $params->Locale);
    }

    public function testValidLatinLocaleIsAccepted(): void
    {
        $params = new DecreesParams(['locale' => 'la_VA']);

        self::assertSame('la', $params->Locale);
    }

    public function testCanonicalizationNormalisesHyphenForm(): void
    {
        $params = new DecreesParams(['locale' => 'en-us']);

        self::assertSame('en', $params->Locale);
    }

    public function testUnsupportedLocaleThrowsValidationException(): void
    {
        // \Locale::canonicalize is permissive and rarely returns null; it
        // normalises 'not-a-locale' into 'not@a=locale' which then trips the
        // LitLocale::isValid branch.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('for param `locale`');

        new DecreesParams(['locale' => 'not-a-locale']);
    }

    public function testPayloadIsAssignedAsProvided(): void
    {
        $payload         = new \stdClass();
        $payload->some   = 'value';
        $payload->nested = (object) ['x' => 1];

        $params = new DecreesParams(['payload' => $payload]);

        self::assertSame($payload, $params->Payload);
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $params = new DecreesParams(['locale' => 'en', 'unknown' => 'value']);

        self::assertSame('en', $params->Locale);
    }
}
