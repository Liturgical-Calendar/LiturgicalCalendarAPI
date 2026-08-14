<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

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
        $this->assertSame(['diocesan_calendar_test', 'rotter_nl'], $r->resolve('FooTest'));
    }

    public function testResolvesNational(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(['national_calendar_test', 'US'], $r->resolve('BarTest'));
    }

    public function testResolvesRomanRiteWhenAppliestoAbsent(): void
    {
        // Legacy files written before applies_to.rite became required still
        // have to resolve to *something*; the default rite is Roman.
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['rite_calendar_test', 'roman'],
            $r->resolve('BazTest')
        );
    }

    public function testReturnsNullForNonexistentFile(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertNull($r->resolve('NonExistentTest'));
    }

    public function testRejectsPathTraversalDotDot(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertNull($r->resolve('../../etc/passwd'));
    }

    public function testRejectsPathWithDirectorySeparator(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertNull($r->resolve('Foo/Bar'));
    }

    public function testRejectsNameWithNullByte(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertNull($r->resolve("Foo\x00Bar"));
    }

    public function testRejectsNameWithDisallowedCharacter(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertNull($r->resolve('Foo Bar'));
    }

    public function testAcceptsValidAlphanumericDashUnderscoreName(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        // FooTest is a known fixture — must still resolve
        $this->assertSame(['diocesan_calendar_test', 'rotter_nl'], $r->resolve('FooTest'));
    }

    public function testAcceptsNameWithDashAndUnderscore(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        // Non-existent file: no traversal, just a missing file → null
        $this->assertNull($r->resolve('Valid-Test_Name-123'));
    }

    public function testResolveFromPayloadNational(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['national_calendar_test', 'NL'],
            $r->resolveFromPayload(['applies_to' => ['national_calendar' => 'NL']])
        );
    }

    public function testResolveFromPayloadDiocesan(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['diocesan_calendar_test', 'romamo_it'],
            $r->resolveFromPayload(['applies_to' => ['diocesan_calendar' => 'romamo_it']])
        );
    }

    public function testResolveFromPayloadDefaultsToRomanRite(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['rite_calendar_test', 'roman'],
            $r->resolveFromPayload(['name' => 'SomeTest'])
        );
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

    public function testCalendarScopeWinsOverRite(): void
    {
        // An Ambrosian diocesan test is scoped by its diocese, not its rite:
        // diocesan and national calendar ids are unique across rites, and the
        // matching data resource types are keyed the same way.
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['diocesan_calendar_test', 'lugano_ch'],
            $r->resolveFromPayload(['applies_to' => ['rite' => 'ambrosian', 'diocesan_calendar' => 'lugano_ch']])
        );
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
