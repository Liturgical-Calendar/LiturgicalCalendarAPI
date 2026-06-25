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
}
