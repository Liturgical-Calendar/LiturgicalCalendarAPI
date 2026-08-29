<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Runs `scripts/publish-sourcedata.php` as a real process.
 *
 * Nothing else does, and that is how a fatal survived every one of this feature's unit tests:
 * they inject a `Monolog\Logger` built by hand with a `TestHandler` and no processors, while
 * the script asks `LoggerFactory` for one — which by default attaches
 * {@see \LiturgicalCalendar\Api\Http\Logs\RequestResponseProcessor}, a processor that THROWS
 * for any record whose context is not a request or a response. `PublishRunner` logs batch ids
 * and exception classes, so with that default every log call it makes would throw — including
 * the ones inside its catch blocks, before `releaseClaim()` could run, stranding the batch and
 * killing the process with an uncaught `RuntimeException`. The class under test was perfectly
 * correct; the wiring around it was not.
 *
 * A subprocess is the only way to assert that, since a fatal is not catchable in-process. The
 * run is deliberately given no GitHub App, so it exercises the logger and its first record and
 * then stops at a reported misconfiguration, before any network call — this test never touches
 * GitHub, whatever a developer happens to have in their own `.env` files. It needs no database
 * of its own for the same reason: whichever of the two configuration errors the host reaches
 * first, the logging happened before it.
 */
#[CoversNothing]
final class PublishSourceDataScriptTest extends TestCase
{
    /**
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runScript(): array
    {
        $script = dirname(__DIR__, 3) . '/scripts/publish-sourcedata.php';
        self::assertFileExists($script);

        // Empty strings, not absent keys: the script's Dotenv loader is IMMUTABLE, so a
        // variable already present in the environment wins over whatever a developer's local
        // .env* files say. This is what guarantees the run stops at "not configured" instead
        // of reaching for a real GitHub App on someone's workstation.
        $env = [
            'PATH'                        => getenv('PATH') === false ? '/usr/bin:/bin' : getenv('PATH'),
            'HOME'                        => getenv('HOME') === false ? sys_get_temp_dir() : getenv('HOME'),
            'GITHUB_APP_ID'               => '',
            'GITHUB_APP_INSTALLATION_ID'  => '',
            'GITHUB_APP_PRIVATE_KEY_PATH' => '',
            'GITHUB_REPOSITORY'           => '',
        ];

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process     = proc_open([PHP_BINARY, $script, '1'], $descriptors, $pipes, null, $env);
        self::assertIsResource($process, 'could not start the publish script');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => proc_close($process)];
    }

    public function testTheScriptRunsAndReportsMisconfigurationInsteadOfCrashing(): void
    {
        $result = $this->runScript();

        self::assertSame(
            1,
            $result['exitCode'],
            "expected a reported error, got:\nSTDOUT: {$result['stdout']}\nSTDERR: {$result['stderr']}"
        );

        // Both are legitimate stopping points for a run with no GitHub App, and which one is
        // reached depends on where THIS host keeps its database credentials — the script reads
        // .env* files itself, and its Dotenv loader is immutable, so an exported DB_HOST that
        // is not also in a file does not reach it. Either message proves the same thing this
        // test is for: the script reported its problem instead of dying on it.
        self::assertMatchesRegularExpression(
            '/GitHub App is not configured|database unavailable/',
            $result['stderr'],
            'the script must name what stopped it'
        );

        // The signature of the logger defect: the script got far enough to write its first log
        // record (which happens before either of those errors can be reported), and that record
        // did not blow up. A regression here is a PHP fatal, exit 255, not a failed assertion —
        // which is precisely why this runs out of process.
        self::assertStringNotContainsString('Uncaught', $result['stderr']);
        self::assertStringNotContainsString('Cannot process either request or response', $result['stderr']);
    }
}
