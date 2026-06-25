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

    public function testResolvesGeneralWhenAppliestoAbsent(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['general_roman_calendar_test', 'general_roman_calendar'],
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
}
