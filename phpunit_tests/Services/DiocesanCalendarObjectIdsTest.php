<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Services\DiocesanCalendarObjectIds;
use LiturgicalCalendar\Tests\Support\PinsRouterPathsTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `diocesan_calendar` grant must name a calendar that exists or can be created (#993): a
 * diocesan calendar can only be created once the national calendar of its nation exists.
 *
 * Fixtures are the bundled source data: US and IT have national calendars (boston_us and
 * romamo_it have diocesan ones), France has none, and lugano_ch exists only under the
 * Ambrosian rite.
 */
#[CoversClass(DiocesanCalendarObjectIds::class)]
final class DiocesanCalendarObjectIdsTest extends TestCase
{
    use PinsRouterPathsTrait;

    public static function setUpBeforeClass(): void
    {
        self::pinRouterPaths();
    }

    public static function tearDownAfterClass(): void
    {
        self::restoreRouterPaths();
    }

    /** @return array<string, array{string, bool}> */
    public static function objectIdProvider(): array
    {
        return [
            'existing Roman diocesan calendar'                       => ['roman/boston_us', true],
            'prospective diocese of a nation with a calendar'        => ['roman/albany_us', true],
            'prospective diocese of a nation without one'            => ['roman/ageneg_fr', false],
            'id naming no diocese'                                   => ['roman/not_a_diocese', false],
            'existing Ambrosian diocesan calendar'                   => ['ambrosian/lugano_ch', true],
            'Ambrosian id with no calendar'                          => ['ambrosian/not_a_diocese', false],
            'Roman diocese has no calendar under the Ambrosian rite' => ['ambrosian/albany_us', false],
            'bare id'                                                => ['boston_us', false],
            'unknown rite'                                           => ['byzantine/boston_us', false],
        ];
    }

    #[DataProvider('objectIdProvider')]
    public function testIsValid(string $objectId, bool $expected): void
    {
        self::assertSame($expected, DiocesanCalendarObjectIds::isValid($objectId));
        self::assertSame($expected, AccessRequestRepository::isValidObjectIdForType('diocesan_calendar', $objectId));
        self::assertSame($expected, null === DiocesanCalendarObjectIds::invalidReason($objectId));
    }

    public function testReasonNamesTheMissingNationalCalendar(): void
    {
        $reason = DiocesanCalendarObjectIds::invalidReason('roman/ageneg_fr');

        self::assertNotNull($reason);
        self::assertStringContainsString('national calendar', $reason);
        self::assertStringContainsString('FR', $reason);
    }

    public function testReasonSaysTheIdNamesNoDiocese(): void
    {
        $reason = DiocesanCalendarObjectIds::invalidReason('roman/not_a_diocese');

        self::assertNotNull($reason);
        self::assertStringContainsString('not_a_diocese', $reason);
        self::assertStringContainsString('Latin-rite diocese', $reason);
    }

    public function testLabelDescribesTheRule(): void
    {
        $label = AccessRequestRepository::validIdsLabelForType('diocesan_calendar');

        self::assertStringContainsString('national calendar', $label);
        self::assertStringContainsString('ambrosian/lugano_ch', $label);
    }
}
