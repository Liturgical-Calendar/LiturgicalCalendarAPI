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

    /**
     * Regression guard for issue #786.
     *
     * exists() used to glob only JsonData::DIOCESAN_CALENDARS_FOLDER, which derives
     * from ROMAN_RITE_FOLDER, so every Ambrosian diocese reported as gone. Because
     * ResourceTuplePurgeReconciler purges on exactly this predicate, a legitimate
     * editor grant on an Ambrosian diocesan calendar was revoked on any sweep.
     */
    public function testAmbrosianDiocesanCalendarsExist(): void
    {
        $checker = new ResourceExistenceChecker();
        foreach (['lugano_ch', 'milano_it', 'bergam_it', 'novara_it'] as $diocese) {
            $this->assertTrue(
                $checker->exists('diocesan_calendar', $diocese),
                "Ambrosian diocese {$diocese} must not read as deleted — the reconciler purges on this."
            );
        }
    }

    public function testRomanDiocesanCalendarsStillExist(): void
    {
        $checker = new ResourceExistenceChecker();
        foreach (['rotter_nl', 'romamo_it', 'boston_us'] as $diocese) {
            $this->assertTrue($checker->exists('diocesan_calendar', $diocese));
        }
    }

    public function testUnknownDiocesanCalendarDoesNotExistUnderEitherRite(): void
    {
        $checker = new ResourceExistenceChecker();
        $this->assertFalse($checker->exists('diocesan_calendar', 'nowhere_zz'));
        $this->assertFalse($checker->exists('diocesan_calendar', ''));
    }

    /**
     * The Vatican is announced as a national calendar but is still served by the
     * General Roman Calendar, so it has no `nations/VA/VA.json` yet. Without the
     * special case a live `national_calendar:VA` grant reads as orphaned and the
     * reconciler purges it. Delete this test's VA assertions once the Vatican gains
     * its own folder — the ordinary is_file() path will cover it then.
     */
    public function testVaticanNationalCalendarExistsWithoutItsOwnFolder(): void
    {
        $checker = new ResourceExistenceChecker();
        $this->assertTrue($checker->exists('national_calendar', 'VA'));
        $this->assertTrue($checker->exists('national_calendar', 'roman/VA'));
    }

    public function testNationalCalendarsWithFoldersExistAndUnknownOnesDoNot(): void
    {
        $checker = new ResourceExistenceChecker();
        $this->assertTrue($checker->exists('national_calendar', 'roman/US'));
        $this->assertTrue($checker->exists('national_calendar', 'roman/IT'));
        $this->assertFalse($checker->exists('national_calendar', 'roman/ZZ'));
        $this->assertFalse($checker->exists('national_calendar', 'ZZ'));
    }

    public function testQualifiedAndUnqualifiedIdsResolveAlike(): void
    {
        // Unqualified ids stay in the store for the whole migration window, and this
        // predicate decides what gets purged, so both forms must resolve.
        $checker = new ResourceExistenceChecker();
        $this->assertTrue($checker->exists('diocesan_calendar', 'ambrosian/lugano_ch'));
        $this->assertTrue($checker->exists('diocesan_calendar', 'lugano_ch'));
        $this->assertTrue($checker->exists('wider_region', 'roman/Europe'));
        $this->assertTrue($checker->exists('wider_region', 'Europe'));
    }

    public function testNonResourceTypeIsNotAResourceType(): void
    {
        $checker = new ResourceExistenceChecker();
        $this->assertFalse($checker->isResourceType('user'));
        $this->assertTrue($checker->isResourceType('national_calendar'));
        $this->assertTrue($checker->isResourceType('diocesan_calendar'));
        $this->assertTrue($checker->isResourceType('wider_region'));
        $this->assertTrue($checker->isResourceType('rite_calendar'));
        $this->assertTrue($checker->isResourceType('general_roman_calendar'));
        $this->assertTrue($checker->isResourceType('national_calendar_test'));
        $this->assertTrue($checker->isResourceType('diocesan_calendar_test'));
        $this->assertTrue($checker->isResourceType('general_roman_calendar_test'));
        $this->assertTrue($checker->isResourceType('rite_calendar_test'));
    }

    public function testRiteCalendarTestExistsOnlyForKnownRites(): void
    {
        $checker = new ResourceExistenceChecker();
        $this->assertTrue($checker->exists('rite_calendar_test', 'roman'));
        $this->assertTrue($checker->exists('rite_calendar_test', 'ambrosian'));
        $this->assertFalse($checker->exists('rite_calendar_test', 'byzantine'));
        $this->assertFalse($checker->exists('rite_calendar_test', ''));
    }

    public function testRiteCalendarIsAKnownResourceType(): void
    {
        $checker = new ResourceExistenceChecker();

        self::assertTrue($checker->isResourceType('rite_calendar'));
    }

    /**
     * `exists()` decides what the reconciler PURGES, so a false negative destroys a live grant
     * while a false positive merely leaves a stale tuple for the next sweep. It therefore
     * answers `true` for the whole fixed catalog and deliberately does NOT validate the
     * `<rite>/<subresource>` shape — legacy unqualified ids are still in the store for the
     * entire migration window.
     */
    public function testRiteCalendarObjectsAreNeverReportedMissing(): void
    {
        $checker = new ResourceExistenceChecker();

        self::assertTrue($checker->exists('rite_calendar', 'roman/decrees'));
        self::assertTrue($checker->exists('rite_calendar', 'ambrosian/EDITIO_TYPICA_2024'));
        self::assertTrue($checker->exists('rite_calendar', 'decrees'));
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
