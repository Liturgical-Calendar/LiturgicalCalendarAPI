<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Params;

use LiturgicalCalendar\Api\Handlers\MissalsHandler;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadata;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadataMap;
use LiturgicalCalendar\Api\Params\MissalsParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissalsParams::class)]
final class MissalsParamsTest extends TestCase
{
    /** @var MissalMetadataMap|null */
    private static ?MissalMetadataMap $savedIndex = null;

    /** @var string[] */
    private static array $savedAvailableLangs = [];

    public static function setUpBeforeClass(): void
    {
        self::$savedIndex          = MissalsHandler::$missalsIndex;
        self::$savedAvailableLangs = MissalsHandler::$availableLangs;
    }

    public static function tearDownAfterClass(): void
    {
        MissalsHandler::$missalsIndex   = self::$savedIndex;
        MissalsHandler::$availableLangs = self::$savedAvailableLangs;
    }

    protected function setUp(): void
    {
        // Reset to a clean per-test state.
        MissalsHandler::$missalsIndex   = self::buildIndex();
        MissalsHandler::$availableLangs = [];
    }

    private static function buildIndex(): MissalMetadataMap
    {
        $index = new MissalMetadataMap();
        $index->addMissal(MissalMetadata::fromArray([
            'missal_id'      => 'EDITIO_TYPICA_1970',
            'name'           => 'Editio Typica 1970',
            'region'         => 'VA',
            'locales'        => ['la', 'en'],
            'api_path'       => '/missals/EDITIO_TYPICA_1970',
            'year_published' => 1970,
            'year_limits'    => ['since_year' => 1970, 'until_year' => 2002],
        ]));
        $index->addMissal(MissalMetadata::fromArray([
            'missal_id'      => 'IT_1983',
            'name'           => 'Messale Romano 1983',
            'region'         => 'IT',
            'locales'        => ['it'],
            'api_path'       => '/missals/IT_1983',
            'year_published' => 1983,
            'year_limits'    => ['since_year' => 1983, 'until_year' => null],
        ]));
        return $index;
    }

    public function testEmptyParamsIsANoop(): void
    {
        // Empty params returns before touching the static index, so even an
        // unset index must not raise. Force the index null to prove this.
        MissalsHandler::$missalsIndex = null;

        $params = new MissalsParams([]);

        self::assertNull($params->Locale);
        self::assertNull($params->Year);
        self::assertNull($params->Region);
        self::assertFalse($params->IncludeEmpty);
    }

    public function testThrowsServiceUnavailableWhenIndexIsNull(): void
    {
        MissalsHandler::$missalsIndex = null;

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Missals index temporarily unavailable');

        new MissalsParams(['year' => 1970]);
    }

    public function testValidYearAsIntegerIsAccepted(): void
    {
        $params = new MissalsParams(['year' => 1970]);

        self::assertSame(1970, $params->Year);
    }

    public function testValidYearAsNumericStringIsAccepted(): void
    {
        $params = new MissalsParams(['year' => '1983']); // @phpstan-ignore-line

        self::assertSame(1983, $params->Year);
    }

    public function testNonIntegerYearStringIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('it must be an integer');

        new MissalsParams(['year' => 'abc']); // @phpstan-ignore-line
    }

    public function testYearOutsideKnownMissalsIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('for param `year`');

        new MissalsParams(['year' => 9999]);
    }

    public function testValidRegionIsAccepted(): void
    {
        $params = new MissalsParams(['region' => 'IT']);

        self::assertSame('IT', $params->Region);
    }

    public function testInvalidRegionIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('for param `region`');

        new MissalsParams(['region' => 'XX']);
    }

    public function testValidLocaleSplitsLocaleAndBaseLocale(): void
    {
        $params = new MissalsParams(['locale' => 'en_US']);

        self::assertSame('en_US', $params->Locale);
        self::assertSame('en', $params->baseLocale);
    }

    public function testUnsupportedLocaleIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not supported by this server');

        new MissalsParams(['locale' => 'not-a-locale']);
    }

    public function testLocaleNotSupportedByMissalIsRejected(): void
    {
        // When availableLangs is constrained, the chosen locale's primary
        // language must appear in the list — otherwise we surface that
        // mismatch with a more specific error.
        MissalsHandler::$availableLangs = ['fr', 'it'];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not a valid locale for the requested Missal');

        new MissalsParams(['locale' => 'en_US']);
    }

    public function testIncludeEmptyTrueFlipsTheFlagAndPropagates(): void
    {
        $params = new MissalsParams(['include_empty' => true]);

        self::assertTrue($params->IncludeEmpty);
    }

    public function testIncludeEmptyAcceptsStringTruthyValues(): void
    {
        $params = new MissalsParams(['include_empty' => 'true']); // @phpstan-ignore-line

        self::assertTrue($params->IncludeEmpty);
    }

    public function testIncludeEmptyRejectsNonBooleanish(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('for param `include_empty`');

        new MissalsParams(['include_empty' => 'maybe']); // @phpstan-ignore-line
    }

    public function testPayloadIsAssigned(): void
    {
        $payload      = new \stdClass();
        $payload->key = 'value';

        $params = new MissalsParams(['payload' => $payload]);

        self::assertSame($payload, $params->Payload);
    }

    public function testUnknownKeysAreSilentlyIgnored(): void
    {
        $params = new MissalsParams(['locale' => 'la_VA', 'unknown' => 'whatever']); // @phpstan-ignore-line

        self::assertSame('la_VA', $params->Locale);
        self::assertSame('la', $params->baseLocale);
    }
}
