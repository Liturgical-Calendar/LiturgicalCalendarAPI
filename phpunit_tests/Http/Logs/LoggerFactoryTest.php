<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http\Logs;

use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LoggerFactory::class)]
final class LoggerFactoryTest extends TestCase
{
    private string $tmpDir;

    /** @var array<class-string, \ReflectionProperty> */
    private static array $reflectionCache = [];

    /** @var mixed */
    private mixed $savedLogsFolder = null;
    private bool $logsFolderWasSet = false;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/litcal_test_logs_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);

        // Snapshot the static logsFolder so we can restore it — other tests
        // (e.g. handler tests that build their own loggers) rely on whatever
        // value was in place before this class ran.
        $folder                 = self::folderProperty();
        $this->logsFolderWasSet = $folder->isInitialized(null);
        if ($this->logsFolderWasSet) {
            $this->savedLogsFolder = $folder->getValue(null);
        }
    }

    protected function tearDown(): void
    {
        // Clear the logger cache so each test gets a fresh logger
        // (LoggerFactory memoises by $logName).
        self::loggersProperty()->setValue(null, []);

        // Restore the saved logsFolder (if any) so other tests are unaffected.
        if ($this->logsFolderWasSet && is_string($this->savedLogsFolder)) {
            self::folderProperty()->setValue(null, $this->savedLogsFolder);
        }

        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tmpDir);
        }
    }

    private static function loggersProperty(): \ReflectionProperty
    {
        return self::$reflectionCache['apiLoggers'] ??= new \ReflectionProperty(LoggerFactory::class, 'apiLoggers');
    }

    private static function folderProperty(): \ReflectionProperty
    {
        return self::$reflectionCache['logsFolder'] ??= new \ReflectionProperty(LoggerFactory::class, 'logsFolder');
    }

    public function testCreateReturnsLoggerAndWritesPlainLog(): void
    {
        $logger = LoggerFactory::create('factorytest', $this->tmpDir, 2, false, true, false);
        self::assertInstanceOf(Logger::class, $logger);

        $logger->info('hello world');
        $files = glob($this->tmpDir . '/factorytest-*.log') ?: [];
        self::assertNotEmpty($files);
    }

    public function testCreateMemoisesPerLogName(): void
    {
        $logger1 = LoggerFactory::create('memoised', $this->tmpDir, 2, false, false, false);
        $logger2 = LoggerFactory::create('memoised', $this->tmpDir, 2, false, false, false);
        self::assertSame($logger1, $logger2);
    }

    public function testCreateRejectsUnwritablePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LoggerFactory::create('rejecttest', '/this/path/should/not/exist');
    }

    public function testCreateAcceptsExistingWritableDir(): void
    {
        $logger = LoggerFactory::create('writabletest', $this->tmpDir);
        self::assertInstanceOf(Logger::class, $logger);
    }
}
