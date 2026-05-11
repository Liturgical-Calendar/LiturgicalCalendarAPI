<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\CacheDuration;
use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Enum\CorpusChristi;
use LiturgicalCalendar\Api\Enum\DateRelation;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Enum\LitEventTestAssertion;
use LiturgicalCalendar\Api\Enum\LitEventTestType;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\ParamError;
use LiturgicalCalendar\Api\Enum\PathCategory;
use LiturgicalCalendar\Api\Enum\YearType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the simple/value-only enums — verifies enum cases resolve from
 * value and the EnumToArrayTrait helpers (names, values, asArray, isValid,
 * areValid) work where mixed in.
 */
#[CoversClass(Ascension::class)]
#[CoversClass(CacheDuration::class)]
#[CoversClass(CalEventAction::class)]
#[CoversClass(CorpusChristi::class)]
#[CoversClass(DateRelation::class)]
#[CoversClass(Epiphany::class)]
#[CoversClass(LitEventType::class)]
#[CoversClass(LitEventTestType::class)]
#[CoversClass(LitEventTestAssertion::class)]
#[CoversClass(PathCategory::class)]
#[CoversClass(YearType::class)]
#[CoversClass(ParamError::class)]
final class SimpleEnumsTest extends TestCase
{
    public function testAscensionCases(): void
    {
        self::assertSame('THURSDAY', Ascension::THURSDAY->value);
        self::assertSame('SUNDAY', Ascension::SUNDAY->value);
        self::assertTrue(Ascension::isValid('THURSDAY'));
        self::assertFalse(Ascension::isValid('MONDAY'));
        self::assertSame(['THURSDAY', 'SUNDAY'], Ascension::values());
        self::assertSame(['THURSDAY' => 'THURSDAY', 'SUNDAY' => 'SUNDAY'], Ascension::asArray());
    }

    public function testCorpusChristiCases(): void
    {
        self::assertSame(CorpusChristi::THURSDAY, CorpusChristi::from('THURSDAY'));
        self::assertSame(CorpusChristi::SUNDAY, CorpusChristi::from('SUNDAY'));
        self::assertTrue(CorpusChristi::areValid(['THURSDAY', 'SUNDAY']));
        self::assertFalse(CorpusChristi::areValid(['THURSDAY', 'WEDNESDAY']));
    }

    public function testEpiphanyCases(): void
    {
        self::assertSame(['SUNDAY_JAN2_JAN8', 'JAN6'], Epiphany::names());
        self::assertSame(['SUNDAY_JAN2_JAN8', 'JAN6'], Epiphany::values());
        self::assertTrue(Epiphany::isValid('JAN6'));
    }

    public function testCacheDurationCases(): void
    {
        // No trait — bare enum.
        self::assertSame('DAY', CacheDuration::DAY->value);
        self::assertSame('WEEK', CacheDuration::WEEK->value);
        self::assertSame('MONTH', CacheDuration::MONTH->value);
        self::assertSame('YEAR', CacheDuration::YEAR->value);
        self::assertSame(CacheDuration::MONTH, CacheDuration::from('MONTH'));
    }

    public function testCalEventActionCases(): void
    {
        self::assertSame('makePatron', CalEventAction::MakePatron->value);
        self::assertSame('setProperty', CalEventAction::SetProperty->value);
        self::assertSame('moveEvent', CalEventAction::MoveEvent->value);
        self::assertSame('createNew', CalEventAction::CreateNew->value);
        self::assertSame('makeDoctor', CalEventAction::MakeDoctor->value);
    }

    public function testDateRelationCases(): void
    {
        self::assertSame('before', DateRelation::Before->value);
        self::assertSame('after', DateRelation::After->value);
        self::assertTrue(DateRelation::isValid('after'));
        self::assertFalse(DateRelation::isValid('during'));
    }

    public function testLitEventTypeCases(): void
    {
        self::assertSame('fixed', LitEventType::FIXED->value);
        self::assertSame('mobile', LitEventType::MOBILE->value);
        self::assertSame(['fixed', 'mobile'], LitEventType::values());
    }

    public function testLitEventTestTypeCases(): void
    {
        self::assertSame('exactCorrespondence', LitEventTestType::EXACT_CORRESPONDENCE->value);
        self::assertSame('exactCorrespondenceSince', LitEventTestType::EXACT_CORRESPONDENCE_SINCE->value);
        self::assertSame('variableCorrespondence', LitEventTestType::VARIABLE_CORRESPONDENCE->value);
    }

    public function testLitEventTestAssertionCases(): void
    {
        self::assertSame('eventNotExists', LitEventTestAssertion::EVENT_NOT_EXISTS->value);
        self::assertSame(
            'eventExists AND hasExpectedDate',
            LitEventTestAssertion::EVENT_EXISTS_AND_HAS_EXPECTED_DATE->value
        );
    }

    public function testPathCategoryCases(): void
    {
        self::assertSame(['nation', 'diocese', 'widerregion'], PathCategory::values());
        self::assertTrue(PathCategory::isValid('nation'));
        self::assertFalse(PathCategory::isValid('planet'));
    }

    public function testYearTypeCases(): void
    {
        self::assertSame('CIVIL', YearType::CIVIL->value);
        self::assertSame('LITURGICAL', YearType::LITURGICAL->value);
        self::assertTrue(YearType::areValid(['CIVIL', 'LITURGICAL']));
    }

    public function testParamErrorGetMessage(): void
    {
        self::assertSame('No error', ParamError::NONE->getMessage());
        self::assertSame('Invalid locale provided', ParamError::INVALID_LOCALE->getMessage());
        self::assertSame('Invalid year provided', ParamError::INVALID_YEAR->getMessage());
        self::assertSame('Invalid region provided', ParamError::INVALID_REGION->getMessage());
        self::assertSame('Missing required parameter', ParamError::MISSING_REQUIRED_PARAM->getMessage());
        self::assertSame('An unknown error occurred', ParamError::UNKNOWN_ERROR->getMessage());
        self::assertSame(0, ParamError::NONE->value);
        self::assertSame(5, ParamError::UNKNOWN_ERROR->value);
    }
}
