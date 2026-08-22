<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the two PHPUnit behaviours the #868 fix depends on, so neither can regress
 * silently under a future PHPUnit upgrade.
 *
 * 1. `tearDown()` DOES run after a skip raised inside `setUp()`. This is the trap:
 *    a class that snapshots state after a skipping `parent::setUp()` leaves its own
 *    `tearDown()` reading properties that were never assigned — fatal for typed
 *    properties without defaults, which is exactly how `CalendarHandlerLocaleLeakTest`
 *    and `DecreesHandlerWriteTest` errored instead of skipping.
 *
 * 2. A skip raised in `setUpBeforeClass()` aborts the whole class: neither `setUp()`
 *    nor `tearDown()` runs. That is why `AbstractHandlerTestCase` now decides
 *    availability there — it makes the trap unreachable for every subclass.
 *
 * Both are asserted by running a nested PHPUnit process over fixture test classes,
 * because a test cannot observe its own skipped lifecycle from the inside.
 */
#[CoversNothing]
final class AbstractHandlerTestCaseSkipContractTest extends TestCase
{
    private string $fixtureDir = '';

    protected function tearDown(): void
    {
        if ('' !== $this->fixtureDir && is_dir($this->fixtureDir)) {
            foreach (glob($this->fixtureDir . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->fixtureDir);
        }
        parent::tearDown();
    }

    /**
     * Write a one-off test class to disk and run it in a nested PHPUnit process.
     */
    private function runFixture(string $className, string $classBody): string
    {
        $root             = dirname(__DIR__, 2);
        $this->fixtureDir = $root . '/phpunit_tests/SkipContractFixtures';
        if (!is_dir($this->fixtureDir)) {
            mkdir($this->fixtureDir, 0755, true);
        }

        $source = "<?php\ndeclare(strict_types=1);\n"
            . "namespace LiturgicalCalendar\\Tests\\SkipContractFixtures;\n"
            . "use PHPUnit\\Framework\\TestCase;\n"
            . "final class {$className} extends TestCase\n{\n{$classBody}\n}\n";
        file_put_contents($this->fixtureDir . '/' . $className . '.php', $source);

        $cmd = sprintf(
            'cd %s && vendor/bin/phpunit --no-configuration --bootstrap %s %s 2>&1',
            escapeshellarg($root),
            escapeshellarg($root . '/vendor/autoload.php'),
            escapeshellarg($this->fixtureDir . '/' . $className . '.php')
        );

        return (string) shell_exec($cmd);
    }

    public function testTearDownRunsAfterASkipInSetUp(): void
    {
        $output = $this->runFixture('SkipInSetUpTest', <<<'PHP'
    private string $neverAssigned;
    protected function setUp(): void { $this->markTestSkipped('probe'); }
    protected function tearDown(): void { echo "TEARDOWN_REACHED:" . $this->neverAssigned; }
    public function testAnything(): void { self::assertTrue(true); }
PHP);

        // The uninitialized read proves tearDown() ran; PHPUnit reports it as an Error.
        self::assertStringContainsString(
            'must not be accessed before initialization',
            $output,
            'PHPUnit must still run tearDown() after a skip in setUp() — the premise of the #868 fix.'
        );
    }

    public function testNeitherSetUpNorTearDownRunsAfterASkipInSetUpBeforeClass(): void
    {
        $output = $this->runFixture('SkipInSetUpBeforeClassTest', <<<'PHP'
    private string $neverAssigned;
    public static function setUpBeforeClass(): void { self::markTestSkipped('probe'); }
    protected function setUp(): void { echo "SETUP_REACHED"; }
    protected function tearDown(): void { echo "TEARDOWN_REACHED:" . $this->neverAssigned; }
    public function testAnything(): void { self::assertTrue(true); }
PHP);

        self::assertStringNotContainsString('SETUP_REACHED', $output, 'a class-level skip must not run setUp()');
        self::assertStringNotContainsString('TEARDOWN_REACHED', $output, 'a class-level skip must not run tearDown()');
        self::assertStringNotContainsString(
            'must not be accessed before initialization',
            $output,
            'a class-level skip must leave no lifecycle hook able to read uninitialized state (#868).'
        );
        self::assertStringContainsString('Skipped: 1', $output, 'the test must be reported as skipped, not errored');
    }
}
