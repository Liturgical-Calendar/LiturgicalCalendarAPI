<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Runs `scripts/poll-sourcedata-merges.php` as a real process — mirrors
 * {@see PublishSourceDataScriptTest}, and for the same reason: the wiring both scripts now share
 * lives in {@see \LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisherFactory}, but
 * only a subprocess proves that the LOGGER it hands back does not itself blow up the moment this
 * entry point calls it, since a fatal is not catchable in-process.
 */
#[CoversNothing]
final class PollSourceDataMergesScriptTest extends TestCase
{
    /**
     * @param array<string, string> $githubEnv Overrides for the GITHUB_* variables.
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runScript(array $githubEnv = []): array
    {
        $script = dirname(__DIR__, 3) . '/scripts/poll-sourcedata-merges.php';
        self::assertFileExists($script);

        // Empty strings, not absent keys: the script's Dotenv loader is IMMUTABLE, so a
        // variable already present in the environment wins over whatever a developer's local
        // .env* files say. This is what guarantees the run stops at "not configured" instead of
        // reaching for a real GitHub App on someone's workstation — see
        // PublishSourceDataScriptTest::runScript()'s identical comment.
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
        $process     = proc_open([PHP_BINARY, $script], $descriptors, $pipes, null, $env);
        self::assertIsResource($process, 'could not start the poll script');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => proc_close($process)];
    }

    /**
     * A GitHub App credential complete enough to get PAST
     * `SourceDataPublisherFactory::mergePollRunner()`'s own configuration checks — the same
     * shape `PublishSourceDataScriptTest::testAMalformedRepositoryIsReportedRatherThanFatal()`
     * uses to reach the repository split without any network call.
     *
     * @return array<string, string>
     */
    private function configuredGithubEnv(): array
    {
        return [
            'GITHUB_APP_ID'               => '12345',
            'GITHUB_APP_INSTALLATION_ID'  => '67890',
            'GITHUB_APP_PRIVATE_KEY_PATH' => '/nonexistent/github-app.pem',
            'GITHUB_REPOSITORY'           => 'Liturgical-Calendar/LiturgicalCalendarAPI',
        ];
    }

    public function testItExitsOneAndSaysWhyWhenTheGithubAppIsUnconfigured(): void
    {
        $result = $this->runScript(['GITHUB_REPOSITORY' => '']);

        self::assertSame(
            1,
            $result['exitCode'],
            "expected a reported error, got:\nSTDOUT: {$result['stdout']}\nSTDERR: {$result['stderr']}"
        );

        // Same either-legitimate-stopping-point reasoning as
        // PublishSourceDataScriptTest::testTheScriptRunsAndReportsMisconfigurationInsteadOfCrashing():
        // which message is reached depends on where THIS host keeps its database credentials.
        self::assertMatchesRegularExpression(
            '/not configured|database unavailable/',
            $result['stderr'],
            'the script must name what stopped it'
        );

        // The signature of the logger defect this factory exists to prevent: the script got far
        // enough to write its first log record before either error could be reported, and that
        // record did not blow up. A regression here is a PHP fatal, exit 255, not a failed
        // assertion — which is precisely why this runs out of process.
        self::assertStringNotContainsString('Uncaught', $result['stderr']);
        self::assertStringNotContainsString('Cannot process either request or response', $result['stderr']);
    }

    public function testItReportsASummaryLine(): void
    {
        $result = $this->runScript($this->configuredGithubEnv());

        self::assertMatchesRegularExpression(
            '/^poll-sourcedata-merges merged=\d+ closed=\d+ reset=\d+ unpollable=\d+ stopped_on_failure=(true|false)$/m',
            $result['stdout'],
            "expected a summary line, got:\nSTDOUT: {$result['stdout']}\nSTDERR: {$result['stderr']}"
        );
        self::assertContains($result['exitCode'], [0, 1]);
    }
}
