<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Deploy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The deploy scripts and the job runner's unit (#1008). litcal-fpm-reload.sh is run for real against stub
 * `systemctl`, `logger` and `sleep` commands on PATH, so what it restarts — and what it refuses — is observed
 * rather than read.
 */
#[CoversNothing]
final class DeployScriptsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/deploy_' . uniqid();
        mkdir($this->tmp . '/bin', 0777, true);
        mkdir($this->tmp . '/api/tmp', 0777, true);

        // systemctl: records every call; NRestarts comes from a per-unit counter file that `restart` of a
        // unit named in crashloop.txt increments, to simulate a unit that dies straight after starting.
        $this->stub('systemctl', <<<'SH'
            #!/bin/sh
            echo "$*" >> "$STUB_DIR/calls.txt"
            if [ "$1" = "show" ]; then
              cat "$STUB_DIR/nrestarts-$2" 2>/dev/null || echo 0
              exit 0
            fi
            if [ "$1" = "restart" ] && grep -qx "$2" "$STUB_DIR/crashloop.txt" 2>/dev/null; then
              n=$(cat "$STUB_DIR/nrestarts-$2" 2>/dev/null || echo 0)
              echo $((n + 1)) > "$STUB_DIR/nrestarts-$2"
            fi
            exit 0
            SH);
        $this->stub('logger', "#!/bin/sh\necho \"\$*\" >> \"\$STUB_DIR/log.txt\"\n");
        $this->stub('sleep', "#!/bin/sh\nexit 0\n");
        touch($this->tmp . '/api/tmp/restart.txt');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    private function stub(string $name, string $body): void
    {
        file_put_contents("{$this->tmp}/bin/{$name}", $body);
        chmod("{$this->tmp}/bin/{$name}", 0755);
    }

    /** @return array{int, list<string>} Exit code and the systemctl calls made. */
    private function reload(string $jobsUnit): array
    {
        file_put_contents("{$this->tmp}/deploy.env", implode("\n", [
            "API_ROOT={$this->tmp}/api",
            'FPM_UNIT=php-fpm.service',
            'WS_UNIT=litcal-websocket.service',
            "JOBS_UNIT={$jobsUnit}",
        ]) . "\n");
        $env  = [
            'PATH'              => "{$this->tmp}/bin:" . getenv('PATH'),
            'STUB_DIR'          => $this->tmp,
            'LITCAL_DEPLOY_ENV' => "{$this->tmp}/deploy.env",
        ];
        $proc = proc_open(['sh', self::ROOT . '/deploy/sbin/litcal-fpm-reload.sh'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($proc);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $code  = proc_close($proc);
        $calls = is_file("{$this->tmp}/calls.txt") ? file("{$this->tmp}/calls.txt", FILE_IGNORE_NEW_LINES) : [];

        return [$code, $calls === false ? [] : $calls];
    }

    public function testAnApiDeployRestartsTheWebSocketServerAndTheJobRunner(): void
    {
        [$code, $calls] = $this->reload('litcal-jobs.service');

        self::assertSame(0, $code);
        self::assertContains('reload php-fpm.service', $calls);
        self::assertContains('restart litcal-websocket.service', $calls);
        self::assertContains('restart litcal-jobs.service', $calls);
        self::assertFileDoesNotExist("{$this->tmp}/api/tmp/restart.txt.claimed");
    }

    public function testWithoutAJobsUnitOnlyTheWebSocketServerIsRestarted(): void
    {
        [$code, $calls] = $this->reload('');

        self::assertSame(0, $code);
        self::assertContains('restart litcal-websocket.service', $calls);
        self::assertSame([], preg_grep('/litcal-jobs/', $calls));
    }

    public function testACrashLoopingJobRunnerFailsTheReloadAndKeepsTheSentinel(): void
    {
        file_put_contents("{$this->tmp}/crashloop.txt", "litcal-jobs.service\n");

        [$code] = $this->reload('litcal-jobs.service');

        self::assertSame(1, $code);
        self::assertFileExists("{$this->tmp}/api/tmp/restart.txt", 'the sentinel is put back so the next deploy retries');
        self::assertStringContainsString('CRASH-LOOPING', (string) file_get_contents("{$this->tmp}/log.txt"));
    }

    public function testTheScriptsParse(): void
    {
        foreach (['deploy/sbin/litcal-fpm-reload.sh', 'deploy/install.sh'] as $script) {
            exec('sh -n ' . escapeshellarg(self::ROOT . '/' . $script) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, $script . ': ' . implode("\n", $out));
        }
    }

    public function testTheScriptsPassShellcheck(): void
    {
        exec('command -v shellcheck', $found, $missing);
        if ($missing !== 0) {
            self::markTestSkipped('shellcheck is not installed.');
        }
        exec('shellcheck ' . escapeshellarg(self::ROOT . '/deploy/sbin/litcal-fpm-reload.sh') . ' ' . escapeshellarg(self::ROOT . '/deploy/install.sh') . ' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }

    public function testTheJobRunnerUnitRunsTheSupervisorAndStopsItInOrder(): void
    {
        $unit = (string) file_get_contents(self::ROOT . '/deploy/systemd/litcal-jobs.service.in');

        self::assertStringContainsString('ExecStart=@PHP_BIN@ @API_ROOT@/bin/litcal-jobs supervise', $unit);
        self::assertStringContainsString('KillMode=mixed', $unit);
        self::assertStringContainsString('TimeoutStopSec=45', $unit);
        self::assertStringContainsString('WorkingDirectory=@API_ROOT@', $unit);
    }

    /**
     * systemd reads StartLimitIntervalSec only in [Unit]; under [Service] it logs "Unknown key name" and ignores
     * it, leaving the restart loop with no ceiling (seen on staging, 2026-09-29).
     */
    public function testTheRestartCeilingIsInTheUnitSection(): void
    {
        $unit      = (string) file_get_contents(self::ROOT . '/deploy/systemd/litcal-jobs.service.in');
        $sections  = preg_split('/^(?=\[[A-Za-z]+\]\s*$)/m', $unit) ?: [];
        $bySection = [];
        foreach ($sections as $section) {
            if (preg_match('/^\[([A-Za-z]+)\]/', $section, $m) === 1) {
                $bySection[$m[1]] = $section;
            }
        }

        foreach (['StartLimitIntervalSec=300', 'StartLimitBurst=5'] as $setting) {
            self::assertStringContainsString($setting, $bySection['Unit'] ?? '', "{$setting} belongs in [Unit]");
            self::assertStringNotContainsString($setting, $bySection['Service'] ?? '', "{$setting} is ignored in [Service]");
        }
    }

    public function testTheRetiredUnitsAndCronAreGone(): void
    {
        self::assertFileDoesNotExist(self::ROOT . '/deploy/systemd/liturgical-calendar-reconciler.service');
        self::assertFileDoesNotExist(self::ROOT . '/deploy/systemd/litcal-publish-consumer.service.in');
        self::assertFileDoesNotExist(self::ROOT . '/deploy/cron/liturgical-calendar-backstop.cron');
    }
}
