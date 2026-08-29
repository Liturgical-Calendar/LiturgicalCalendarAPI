<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * @param array<string, string> $githubEnv Overrides for the GITHUB_* variables.
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runScript(array $githubEnv = []): array
    {
        $script = dirname(__DIR__, 3) . '/scripts/publish-sourcedata.php';
        self::assertFileExists($script);

        // Empty strings, not absent keys: the script's Dotenv loader is IMMUTABLE, so a
        // variable already present in the environment wins over whatever a developer's local
        // .env* files say. This is what guarantees the run stops at "not configured" instead
        // of reaching for a real GitHub App on someone's workstation.
        $env = array_merge(
            [
                'PATH'                        => getenv('PATH') === false ? '/usr/bin:/bin' : getenv('PATH'),
                'HOME'                        => getenv('HOME') === false ? sys_get_temp_dir() : getenv('HOME'),
                'GITHUB_APP_ID'               => '',
                'GITHUB_APP_INSTALLATION_ID'  => '',
                'GITHUB_APP_PRIVATE_KEY_PATH' => '',
                'GITHUB_REPOSITORY'           => '',
            ],
            $githubEnv
        );

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

    /**
     * A GITHUB_REPOSITORY that is SET but malformed — the failure mode a value that is merely
     * absent does not reach.
     *
     * `SourceDataPublisher::fromEnv()` throws `InvalidArgumentException` for anything that is
     * not one `owner/repo` pair. That extends `LogicException`, not `RuntimeException`, so a
     * `catch (\RuntimeException)` around the construction — which is what this script had, with
     * a comment saying it covered exactly this case — missed it entirely: uncaught fatal, exit
     * 255 (a code this script's own table does not list), a stack trace on a cron job's stderr,
     * and no log line past "run starting". Meanwhile `isConfigured()` tested non-emptiness
     * alone, so `/health` reported the publisher CONFIGURED. Exit code, log and health check all
     * said healthy while nothing could ever publish.
     *
     * Each of these is one paste or one keystroke away for an operator.
     *
     * @param string $repository A plausible mistyping of `owner/repo`.
     */
    #[DataProvider('malformedRepositoryProvider')]
    public function testAMalformedRepositoryIsReportedRatherThanFatal(string $repository): void
    {
        $result = $this->runScript([
            // Enough App credential to get PAST GitHubAppAuth::fromEnv() (which validates
            // presence, not the key file) so the run reaches the repository split. No network
            // call happens: fromEnv() throws while wiring, before any client is used.
            'GITHUB_APP_ID'               => '12345',
            'GITHUB_APP_INSTALLATION_ID'  => '67890',
            'GITHUB_APP_PRIVATE_KEY_PATH' => '/nonexistent/github-app.pem',
            'GITHUB_REPOSITORY'           => $repository,
        ]);

        self::assertSame(
            1,
            $result['exitCode'],
            "expected a reported error, not a fatal, got:\nSTDOUT: {$result['stdout']}\nSTDERR: {$result['stderr']}"
        );
        self::assertStringContainsString('GITHUB_REPOSITORY must be in the form "owner/repo"', $result['stderr']);
        self::assertStringNotContainsString('Uncaught', $result['stderr']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedRepositoryProvider(): array
    {
        return [
            'pasted repository URL' => ['https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI'],
            'trailing slash'        => ['Liturgical-Calendar/LiturgicalCalendarAPI/'],
            'no owner'              => ['LiturgicalCalendarAPI'],
        ];
    }
}
