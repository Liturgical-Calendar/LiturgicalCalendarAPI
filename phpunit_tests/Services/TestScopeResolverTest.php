<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Services\TestScopeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TestScopeResolver::class)]
final class TestScopeResolverTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = dirname(__DIR__) . '/fixtures/tests';
    }

    public function testResolvesDiocesan(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(['diocesan_calendar_test', 'roman/rotter_nl'], $r->resolve(Rite::ROMAN, 'FooTest'));
    }

    public function testResolvesNational(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(['national_calendar_test', 'roman/US'], $r->resolve(Rite::ROMAN, 'BarTest'));
    }

    public function testResolvesRomanRiteWhenAppliestoAbsent(): void
    {
        // Legacy files written before applies_to.rite became required still
        // have to resolve to *something*; the default rite is Roman.
        //
        // This also pins the #790 follow-up constraint that resolve() — unlike the now-
        // strict resolveFromPayload() (see TestScopeResolverTest::
        // testResolveFromPayloadWithoutAppliesToFailsClosed()) — must KEEP this lenient
        // default: it reads stored files, and making this path strict would break
        // authorization for existing tests written before applies_to was required.
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['rite_calendar_test', 'roman'],
            $r->resolve(Rite::ROMAN, 'BazTest')
        );
    }

    public function testReturnsNullForNonexistentFile(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertNull($r->resolve(Rite::ROMAN, 'NonExistentTest'));
    }

    public function testRejectsPathTraversalDotDot(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertNull($r->resolve(Rite::ROMAN, '../../etc/passwd'));
    }

    public function testRejectsPathWithDirectorySeparator(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertNull($r->resolve(Rite::ROMAN, 'Foo/Bar'));
    }

    public function testRejectsNameWithNullByte(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertNull($r->resolve(Rite::ROMAN, "Foo\x00Bar"));
    }

    public function testRejectsNameWithDisallowedCharacter(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertNull($r->resolve(Rite::ROMAN, 'Foo Bar'));
    }

    public function testAcceptsValidAlphanumericDashUnderscoreName(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        // FooTest is a known fixture — must still resolve. It predates the required
        // `rite`, so it falls back to the default rite and is qualified with it.
        $this->assertSame(['diocesan_calendar_test', 'roman/rotter_nl'], $r->resolve(Rite::ROMAN, 'FooTest'));
    }

    public function testAcceptsNameWithDashAndUnderscore(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        // Non-existent file: no traversal, just a missing file → null
        $this->assertNull($r->resolve(Rite::ROMAN, 'Valid-Test_Name-123'));
    }

    public function testResolvesRiteQualifiedDiocesanScopeFromFile(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['diocesan_calendar_test', 'ambrosian/lugano_ch'],
            $r->resolve(Rite::AMBROSIAN, 'AmbrosianDioceseTest')
        );
    }

    public function testResolveFromPayloadNational(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['national_calendar_test', 'roman/NL'],
            $r->resolveFromPayload(['applies_to' => ['rite' => 'roman', 'national_calendar' => 'NL']])
        );
    }

    public function testResolveFromPayloadDiocesan(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['diocesan_calendar_test', 'roman/romamo_it'],
            $r->resolveFromPayload(['applies_to' => ['rite' => 'roman', 'diocesan_calendar' => 'romamo_it']])
        );
    }

    public function testUnqualifiedLegacyPayloadFallsBackToTheDefaultRite(): void
    {
        // A payload written before `rite` was required still has to resolve to a
        // scope; it is qualified with the default rite rather than left bare.
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['diocesan_calendar_test', 'roman/romamo_it'],
            $r->resolveFromPayload(['applies_to' => ['diocesan_calendar' => 'romamo_it']])
        );
    }

    /**
     * Issue #790 follow-up: unlike resolve() (which must stay lenient for legacy stored
     * files), resolveFromPayload() authorizes a *write* — a payload with no `applies_to`
     * at all must fail closed rather than silently default to the rite-level scope. This
     * pins the tightened contract; it replaces what was previously
     * testResolveFromPayloadDefaultsToRomanRite(), which asserted the lenient default this
     * change deliberately removes for the payload path only.
     */
    public function testResolveFromPayloadWithoutAppliesToFailsClosed(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertNull($r->resolveFromPayload(['name' => 'SomeTest']));
    }

    /**
     * Twin of the above: `applies_to` present but not an array (e.g. a client sending a
     * string or null) must also fail closed, not be coerced into the lenient default.
     */
    public function testResolveFromPayloadWithNonArrayAppliesToFailsClosed(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertNull($r->resolveFromPayload(['applies_to' => 'roman']));
        $this->assertNull($r->resolveFromPayload(['applies_to' => null]));
    }

    public function testResolveFromPayloadRomanRite(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['rite_calendar_test', 'roman'],
            $r->resolveFromPayload(['applies_to' => ['rite' => 'roman']])
        );
    }

    public function testResolveFromPayloadAmbrosianRite(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['rite_calendar_test', 'ambrosian'],
            $r->resolveFromPayload(['applies_to' => ['rite' => 'ambrosian']])
        );
    }

    public function testResolveFromPayloadUnknownRiteFallsBackToDefault(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['rite_calendar_test', 'roman'],
            $r->resolveFromPayload(['applies_to' => ['rite' => 'byzantine']])
        );
    }

    public function testCalendarScopeSelectsTheTypeAndTheRiteQualifiesTheId(): void
    {
        // An Ambrosian diocesan test lands on diocesan_calendar_test, but its id
        // carries the rite: `lugano_ch` on its own would be an ambiguous grant,
        // since the source tree admits the same diocese under either rite.
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['diocesan_calendar_test', 'ambrosian/lugano_ch'],
            $r->resolveFromPayload(['applies_to' => ['rite' => 'ambrosian', 'diocesan_calendar' => 'lugano_ch']])
        );
    }

    public function testTheSameDioceseUnderTwoRitesGetsTwoDistinctScopes(): void
    {
        $r     = new TestScopeResolver($this->fixturesDir);
        $ambro = $r->resolveFromPayload(['applies_to' => ['rite' => 'ambrosian', 'diocesan_calendar' => 'lugano_ch']]);
        $roman = $r->resolveFromPayload(['applies_to' => ['rite' => 'roman', 'diocesan_calendar' => 'lugano_ch']]);

        $this->assertNotSame($ambro, $roman);
        $this->assertSame(['diocesan_calendar_test', 'ambrosian/lugano_ch'], $ambro);
        $this->assertSame(['diocesan_calendar_test', 'roman/lugano_ch'], $roman);
    }

    public function testQualifyAndParseRoundTrip(): void
    {
        $qualified = TestScopeResolver::qualify(Rite::AMBROSIAN, 'lugano_ch');
        $this->assertSame('ambrosian/lugano_ch', $qualified);
        $this->assertSame([Rite::AMBROSIAN, 'lugano_ch'], TestScopeResolver::parseQualifiedId($qualified));
    }

    public function testParseQualifiedIdRejectsUnqualifiedAndUnknownRites(): void
    {
        $this->assertNull(TestScopeResolver::parseQualifiedId('rotter_nl'));
        $this->assertNull(TestScopeResolver::parseQualifiedId('byzantine/foo'));
        $this->assertNull(TestScopeResolver::parseQualifiedId('roman/'));
    }

    public function testResolveFromPayloadReturnsNullForNonArray(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertNull($r->resolveFromPayload(null));
        $this->assertNull($r->resolveFromPayload('not-json'));
    }

    public function testIsSafeName(): void
    {
        $this->assertTrue(TestScopeResolver::isSafeName('Foo_Test-1'));
        $this->assertFalse(TestScopeResolver::isSafeName('..'));
        $this->assertFalse(TestScopeResolver::isSafeName('a/b'));
        $this->assertFalse(TestScopeResolver::isSafeName(''));
    }
}
