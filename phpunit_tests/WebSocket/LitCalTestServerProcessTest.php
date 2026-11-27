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
        $env['WS_HOST']        = '127.0.0.1';
        $env['WS_PORT']        = (string) $port;
        $env['APP_ENV']        = 'test';
        $env['ZITADEL_ISSUER'] = '';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process     = proc_open(
            [PHP_BINARY, '-d', 'variables_order=GPCS', $serverScript],
            $descriptors,
            $pipes,
            $projectRoot,
            $env
        );
        self::assertIsResource($process, 'Could not start bin/LitCalTestServer.php subprocess');

        $stdout          = '';
        $stderr          = '';
        $connected       = false;
        $expectedMessage = sprintf('Listening on ws://127.0.0.1:%d', $port);

        try {
            fclose($pipes[0]);
            if (DIRECTORY_SEPARATOR === '/') {
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
            }

            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                if (DIRECTORY_SEPARATOR === '/') {
                    $stdoutChunk = stream_get_contents($pipes[1]);
                    if (is_string($stdoutChunk)) {
                        $stdout .= $stdoutChunk;
                    }
                    $stderrChunk = stream_get_contents($pipes[2]);
                    if (is_string($stderrChunk)) {
                        $stderr .= $stderrChunk;
                    }
                }

                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
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

                if ($connected && ( DIRECTORY_SEPARATOR !== '/' || str_contains($stdout, $expectedMessage) )) {
                    break;
                }

                usleep(25_000);
            }
        } finally {
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

            if (is_resource($pipes[1])) {
                $remainingStdout = stream_get_contents($pipes[1]);
                if (is_string($remainingStdout)) {
                    $stdout .= $remainingStdout;
                }
                fclose($pipes[1]);
            }
            if (is_resource($pipes[2])) {
                $remainingStderr = stream_get_contents($pipes[2]);
                if (is_string($remainingStderr)) {
                    $stderr .= $remainingStderr;
                }
                fclose($pipes[2]);
            }
            proc_close($process);
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
