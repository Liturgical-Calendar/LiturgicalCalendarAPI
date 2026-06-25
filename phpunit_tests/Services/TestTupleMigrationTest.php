<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\TestScopeResolver;
use LiturgicalCalendar\Api\Services\TestTupleMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TestTupleMigration::class)]
final class TestTupleMigrationTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = dirname(__DIR__) . '/fixtures/tests';
    }

    /**
     * A `test_definition:FooTest` tuple must remap to `diocesan_calendar_test:rotter_nl`
     * when FooTest.json carries `applies_to.diocesan_calendar = "rotter_nl"`.
     */
    public function testMapsTupleToDiocesanScope(): void
    {
        $resolver  = new TestScopeResolver($this->fixturesDir);
        $migration = new TestTupleMigration();

        $tuple = [
            'user'     => 'user:1',
            'relation' => 'editor',
            'object'   => 'test_definition:FooTest',
        ];

        $result = $migration->mapTuple($tuple, $resolver);

        $this->assertSame(
            [
                'user'     => 'user:1',
                'relation' => 'editor',
                'object'   => 'diocesan_calendar_test:rotter_nl',
            ],
            $result
        );
    }

    /**
     * When the resolver cannot find the test file it returns null, and
     * mapTuple must propagate that null so the old tuple is never deleted.
     */
    public function testReturnsNullWhenResolverReturnsNull(): void
    {
        $resolver  = new TestScopeResolver($this->fixturesDir);
        $migration = new TestTupleMigration();

        $tuple = [
            'user'     => 'user:1',
            'relation' => 'editor',
            'object'   => 'test_definition:NonExistentTest',
        ];

        $this->assertNull($migration->mapTuple($tuple, $resolver));
    }
}
