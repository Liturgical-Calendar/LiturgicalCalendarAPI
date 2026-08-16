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
     * A `test_definition:FooTest` tuple must remap to `diocesan_calendar_test:roman/rotter_nl`
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
                'object'   => 'diocesan_calendar_test:roman/rotter_nl',
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

    /**
     * An object with no ':' separator (no type prefix at all) must return null.
     * This exercises the `$colonPos === false` guard in mapTuple.
     */
    public function testReturnsNullWhenObjectHasNoColon(): void
    {
        $resolver  = new TestScopeResolver($this->fixturesDir);
        $migration = new TestTupleMigration();

        $tuple = [
            'user'     => 'user:1',
            'relation' => 'editor',
            'object'   => 'no_colon_here',
        ];

        $this->assertNull($migration->mapTuple($tuple, $resolver));
    }

    /**
     * An object of `test_definition:` (type prefix but empty name segment) must
     * return null. This exercises the `$testName === ''` guard in mapTuple.
     */
    public function testReturnsNullWhenTestNameIsEmpty(): void
    {
        $resolver  = new TestScopeResolver($this->fixturesDir);
        $migration = new TestTupleMigration();

        $tuple = [
            'user'     => 'user:1',
            'relation' => 'editor',
            'object'   => 'test_definition:',
        ];

        $this->assertNull($migration->mapTuple($tuple, $resolver));
    }

    /**
     * An object whose name segment itself contains a colon (e.g. "Foo:Bar") must
     * still be treated as the test name "Foo:Bar" — only the FIRST colon is used
     * as the type/id separator. The resolver will be called with "Foo:Bar" and,
     * because there is no fixture for that name, return null (which mapTuple
     * propagates). This verifies that extra colons do not crash the extraction.
     */
    public function testHandlesColonInTestName(): void
    {
        $resolver  = new TestScopeResolver($this->fixturesDir);
        $migration = new TestTupleMigration();

        $tuple = [
            'user'     => 'user:1',
            'relation' => 'editor',
            'object'   => 'test_definition:Foo:Bar',
        ];

        // TestScopeResolver rejects 'Foo:Bar' (contains ':' which is outside
        // [A-Za-z0-9_-]) → resolve() returns null → mapTuple returns null.
        $this->assertNull($migration->mapTuple($tuple, $resolver));
    }

    /**
     * A legacy `test_definition:` tuple carries no rite. When the name resolves
     * under BOTH rite partitions — `CollideTest.json` exists under both
     * fixtures/tests/roman/ and fixtures/tests/ambrosian/, as two different
     * tests with two different scopes — mapTuple() must refuse to guess which
     * one the tuple meant, rather than silently picking the first rite tried.
     * Guessing wrong would grant a scope the tuple's holder never had while
     * revoking the one they actually held — a privilege shift in both
     * directions once the caller deletes the old tuple.
     */
    public function testAmbiguousAcrossTwoRitesIsRefused(): void
    {
        $resolver  = new TestScopeResolver($this->fixturesDir);
        $migration = new TestTupleMigration();

        $tuple = [
            'user'     => 'user:1',
            'relation' => 'editor',
            'object'   => 'test_definition:CollideTest',
        ];

        $reason = null;
        $result = $migration->mapTuple($tuple, $resolver, $reason);

        $this->assertNull($result);
        $this->assertSame('ambiguous', $reason);
    }

    /**
     * The companion case: a name resolving in NEITHER partition is reported as
     * 'not_found' rather than 'ambiguous', so a CLI consumer can tell the two
     * failure modes apart.
     */
    public function testNotFoundReasonIsDistinctFromAmbiguous(): void
    {
        $resolver  = new TestScopeResolver($this->fixturesDir);
        $migration = new TestTupleMigration();

        $tuple = [
            'user'     => 'user:1',
            'relation' => 'editor',
            'object'   => 'test_definition:NonExistentTest',
        ];

        $reason = null;
        $result = $migration->mapTuple($tuple, $resolver, $reason);

        $this->assertNull($result);
        $this->assertSame('not_found', $reason);
    }
}
