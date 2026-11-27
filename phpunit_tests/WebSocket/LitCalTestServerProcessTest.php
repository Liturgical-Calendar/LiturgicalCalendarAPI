<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\WebSocket;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that `bin/LitCalTestServer.php` honours `WS_HOST`, `WS_PORT`, and `APP_ENV` passed
 * through the process environment even under `variables_order=GPCS` (where PHP CLI leaves `$_ENV`
 * empty for inherited variables and `Dotenv::createImmutable()` declines to overwrite `$_SERVER`).
 */
#[CoversNothing]
final class LitCalTestServerProcessTest extends TestCase
{
    public function testServerBindsHostPortAndAppEnvFromProcessEnvironment(): void
    {
        $projectRoot  = dirname(__DIR__, 2);
        $serverScript = $projectRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'LitCalTestServer.php';
        self::assertFileExists($serverScript);

        $ephemeralProbe = @stream_socket_server('tcp://127.0.0.1:0', $probeErrno, $probeErrstr);
        self::assertIsResource(
            $ephemeralProbe,
            sprintf('Could not allocate an ephemeral port: %s (%d)', $probeErrstr, $probeErrno)
        );
        $boundName = stream_socket_get_name($ephemeralProbe, false);
        fclose($ephemeralProbe);
        self::assertIsString($boundName);
        $colonPos = strrpos($boundName, ':');
        self::assertNotFalse($colonPos);
        $port = (int) substr($boundName, $colonPos + 1);
        self::assertGreaterThan(0, $port);

        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        unset($env['PCOV_SERVER_COVERAGE_DIR']);
        $env['WS_HOST'] = '127.0.0.1';
        $env['WS_PORT'] = (string) $port;
        $env['APP_ENV'] = 'test';
        // PHP's proc_open() omits empty-string ('') entries from the child env, which would let
        // Dotenv::createImmutable()->safeLoad() load ZITADEL_* from local .env* files. Passing '0'
        // keeps the keys present in $_SERVER/getenv() so Dotenv leaves them alone, while
        // WsCallerResolver::fromEnv() and OidcAuthMiddleware treat '0' as falsy in `?:` and resolve
        // Zitadel as unconfigured, preventing warmKeySet() from making network requests.
        $env['ZITADEL_ISSUER']       = '0';
        $env['ZITADEL_INTERNAL_URL'] = '0';
        $env['ZITADEL_CLIENT_ID']    = '0';
        $env['ZITADEL_PROJECT_ID']   = '0';

        $stdoutFile = tempnam(sys_get_temp_dir(), 'litcal-ws-stdout-');
        if ($stdoutFile === false) {
            self::fail('Could not create temporary stdout file');
        }
        $stderrFile = tempnam(sys_get_temp_dir(), 'litcal-ws-stderr-');
        if ($stderrFile === false) {
            @unlink($stdoutFile);
            self::fail('Could not create temporary stderr file');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];

        $process         = null;
        $pipes           = [];
        $stdout          = '';
        $stderr          = '';
        $connected       = false;
        $expectedMessage = sprintf('Listening on ws://127.0.0.1:%d', $port);

        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'variables_order=GPCS', $serverScript],
                $descriptors,
                $pipes,
                $projectRoot,
                $env
            );
            self::assertIsResource($process, 'Could not start bin/LitCalTestServer.php subprocess');

            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }

            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                $currentStdout = @file_get_contents($stdoutFile);
                if (is_string($currentStdout)) {
                    $stdout = $currentStdout;
                }

                if (!$connected) {
                    $client = @stream_socket_client(
                        sprintf('tcp://127.0.0.1:%d', $port),
                        $connErrno,
                        $connErrstr,
                        0.05
                    );
                    if (is_resource($client)) {
                        fclose($client);
                        $connected = true;
                    }
                }

                if ($connected && str_contains($stdout, $expectedMessage)) {
                    break;
                }

                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }

                usleep(25_000);
            }
        } finally {
            if (is_resource($process)) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process);
                    $stopDeadline = microtime(true) + 1.0;
                    while (microtime(true) < $stopDeadline) {
                        $status = proc_get_status($process);
                        if (!$status['running']) {
                            break;
                        }
                        usleep(20_000);
                    }
                    if ($status['running'] && defined('SIGKILL')) {
                        proc_terminate($process, SIGKILL);
                    }
                }

                proc_close($process);
            }

            $finalStdout = @file_get_contents($stdoutFile);
            if (is_string($finalStdout)) {
                $stdout = $finalStdout;
            }
            $finalStderr = @file_get_contents($stderrFile);
            if (is_string($finalStderr)) {
                $stderr = $finalStderr;
            }

            @unlink($stdoutFile);
            @unlink($stderrFile);
        }

        self::assertTrue(
            $connected,
            sprintf(
                "bin/LitCalTestServer.php did not bind 127.0.0.1:%d within timeout.\nSTDOUT:\n%s\nSTDERR:\n%s",
                $port,
                $stdout,
                $stderr
            )
        );
        self::assertStringContainsString(
            $expectedMessage,
            $stdout,
            sprintf("Expected startup log '%s' in STDOUT.\nSTDOUT:\n%s\nSTDERR:\n%s", $expectedMessage, $stdout, $stderr)
        );
    }
}
