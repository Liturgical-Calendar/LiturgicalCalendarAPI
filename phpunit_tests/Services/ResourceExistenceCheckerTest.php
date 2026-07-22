<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\ResourceExistenceChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResourceExistenceChecker::class)]
final class ResourceExistenceCheckerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // JsonData::path() concatenates Router::$apiFilePath; populate it.
        Router::getApiPaths();
    }

    public function testGrcAlwaysExists(): void
    {
        $checker = new ResourceExistenceChecker();
        $this->assertTrue($checker->exists('general_roman_calendar', 'temporale'));
        $this->assertTrue($checker->exists('general_roman_calendar_test', 'temporale'));
    }

    public function testNonResourceTypeIsNotAResourceType(): void
    {
        $checker = new ResourceExistenceChecker();
        $this->assertFalse($checker->isResourceType('user'));
        $this->assertTrue($checker->isResourceType('national_calendar'));
        $this->assertTrue($checker->isResourceType('diocesan_calendar'));
        $this->assertTrue($checker->isResourceType('wider_region'));
        $this->assertTrue($checker->isResourceType('general_roman_calendar'));
        $this->assertTrue($checker->isResourceType('national_calendar_test'));
        $this->assertTrue($checker->isResourceType('diocesan_calendar_test'));
        $this->assertTrue($checker->isResourceType('general_roman_calendar_test'));
    }

    public function testMissingNationalCalendarDoesNotExist(): void
    {
        $checker = new ResourceExistenceChecker();
        // 'ZZ' has no folder under jsondata/sourcedata/rite/roman/calendars/nations
        $this->assertFalse($checker->exists('national_calendar', 'ZZ'));
    }

    public function testExistingNationalCalendarExists(): void
    {
        $checker = new ResourceExistenceChecker();
        // IT has jsondata/sourcedata/rite/roman/calendars/nations/IT/IT.json
        $this->assertTrue($checker->exists('national_calendar', 'IT'));
    }

    public function testExistingWiderRegionExists(): void
    {
        $checker = new ResourceExistenceChecker();
        // Europe has jsondata/sourcedata/rite/roman/calendars/wider_regions/Europe/
        $this->assertTrue($checker->exists('wider_region', 'Europe'));
    }

    public function testMissingWiderRegionDoesNotExist(): void
    {
        $checker = new ResourceExistenceChecker();
        $this->assertFalse($checker->exists('wider_region', 'Antarctica'));
    }

    public function testExistingDiocesanCalendarExists(): void
    {
        $checker = new ResourceExistenceChecker();
        // agrige_it lives at dioceses/IT/agrige_it/
        $this->assertTrue($checker->exists('diocesan_calendar', 'agrige_it'));
    }

    public function testMissingDiocesanCalendarDoesNotExist(): void
    {
        $checker = new ResourceExistenceChecker();
        $this->assertFalse($checker->exists('diocesan_calendar', 'nonexistent_xx'));
    }

    public function testScopedTestTypesAlwaysExist(): void
    {
        $checker = new ResourceExistenceChecker();
        $this->assertTrue($checker->exists('national_calendar_test', 'IT'));
        $this->assertTrue($checker->exists('diocesan_calendar_test', 'agrige_it'));
    }

    public function testUnknownTypeDoesNotExist(): void
    {
        $checker = new ResourceExistenceChecker();
        $this->assertFalse($checker->exists('user', 'admin'));
    }
}
