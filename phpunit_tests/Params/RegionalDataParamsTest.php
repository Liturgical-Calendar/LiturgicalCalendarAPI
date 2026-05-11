<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Params;

use LiturgicalCalendar\Api\Enum\PathCategory;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanData;
use LiturgicalCalendar\Api\Params\RegionalDataParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegionalDataParams::class)]
final class RegionalDataParamsTest extends TestCase
{
    public function testCategoryAndKeyAreRequired(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Expected params `category` and `key`');

        new RegionalDataParams([]); // @phpstan-ignore-line - intentional missing params
    }

    public function testMissingKeyThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);

        new RegionalDataParams(['category' => PathCategory::NATION]); // @phpstan-ignore-line
    }

    public function testCategoryAndKeyAreAssigned(): void
    {
        $params = new RegionalDataParams([
            'category' => PathCategory::NATION,
            'key'      => 'IT',
        ]);

        self::assertSame(PathCategory::NATION, $params->category);
        self::assertSame('IT', $params->key);
        self::assertNull($params->locale);
        self::assertNull($params->i18nRequest);
    }

    public function testI18nRequestIsAssignedWhenProvided(): void
    {
        $params = new RegionalDataParams([
            'category'    => PathCategory::DIOCESE,
            'key'         => 'romamo_it',
            'i18nRequest' => 'it',
        ]);

        self::assertSame('it', $params->i18nRequest);
    }

    public function testValidLocaleIsCanonicalised(): void
    {
        $params = new RegionalDataParams([
            'category' => PathCategory::WIDERREGION,
            'key'      => 'Europe',
            'locale'   => 'fr-fr',
        ]);

        self::assertSame('fr_FR', $params->locale);
    }

    public function testUnsupportedLocaleThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('for param `locale`');

        new RegionalDataParams([
            'category' => PathCategory::NATION,
            'key'      => 'IT',
            'locale'   => 'not-a-locale',
        ]);
    }

    public function testPayloadRequiresRawPayload(): void
    {
        $reflection = new \ReflectionClass(DiocesanData::class);
        /** @var DiocesanData $payload */
        $payload = $reflection->newInstanceWithoutConstructor();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('rawPayload is required');

        new RegionalDataParams([
            'category' => PathCategory::DIOCESE,
            'key'      => 'romamo_it',
            'payload'  => $payload,
        ]); // @phpstan-ignore-line
    }

    public function testPayloadAndRawPayloadAreAssignedTogether(): void
    {
        $reflection = new \ReflectionClass(DiocesanData::class);
        /** @var DiocesanData $payload */
        $payload          = $reflection->newInstanceWithoutConstructor();
        $rawPayload       = new \stdClass();
        $rawPayload->name = 'Diocese of Rome';

        $params = new RegionalDataParams([
            'category'   => PathCategory::DIOCESE,
            'key'        => 'romamo_it',
            'payload'    => $payload,
            'rawPayload' => $rawPayload,
        ]);

        self::assertSame($payload, $params->payload);
        self::assertSame($rawPayload, $params->rawPayload);
    }
}
