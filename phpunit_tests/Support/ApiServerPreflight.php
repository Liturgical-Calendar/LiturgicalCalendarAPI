<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;

/**
 * Answers one question for every suite that talks to a live API server: is the thing on
 * the configured port OUR API, something else, or nothing at all? (#922)
 *
 * The `Routes/*` and `WebSocket/*` suites used to ask only whether the port accepted a
 * connection. When a stale container held `127.0.0.1:8000` and 404'd every path, that
 * produced 131 failures spread across dozens of classes, all of which read like a
 * regression in whatever branch happened to be checked out. Establishing that they were
 * environmental took running the same suites against a clean checkout and diffing the
 * failing test names.
 *
 * The three outcomes are deliberately NOT treated alike:
 *
 *  - NOT_LISTENING — nothing has the port. This is the ordinary "I haven't started the
 *    server" case and stays exactly as loud as it was: the caller skips (WebSocket) or
 *    fails with the documented "maybe run `composer start` first?" message (ApiTestCase).
 *  - FOREIGN — something answers, but it is not this API. This must never look like
 *    either of the other two: not a skip (the developer would never see it) and not a
 *    burst of assertion failures (the developer would misread it). Callers fail fast with
 *    {@see message()}, which names what answered and what was expected.
 *  - OK — `/calendars` returned 200 with a `litcal_metadata` body.
 *
 * How strong is "OK"? It proves the responder is a Liturgical Calendar API serving
 * source data. It does NOT prove the responder is running the working tree's PHP code:
 * a stale container of THIS project, a few commits behind, answers exactly the same way.
 * {@see buildDrift()} is the partial answer to that — a byte comparison of one file the
 * API serves verbatim — and its limits are documented there.
 */
final class ApiServerPreflight
{
    public const OK            = 'ok';
    public const NOT_LISTENING = 'not-listening';
    public const FOREIGN       = 'foreign';

    /** The endpoint probed for identity: cheap, unauthenticated, and always 200 on a healthy API. */
    private const IDENTITY_PATH = '/calendars';

    /** The key every `/calendars` response carries, and no generic JSON 404 page does. */
    private const IDENTITY_KEY = 'litcal_metadata';

    /** A file the API serves verbatim from `jsondata/schemas/`, used by the advisory build check. */
    private const BUILD_PROBE_PATH = '/schemas/LitCal.json';

    /**
     * One probe per base URI per process: the suites ask on every class, and the answer
     * cannot change mid-run in any way a test could act on.
     *
     * @var array<string, self>
     */
    private static array $memo = [];

    /** True once the FOREIGN banner has been written to STDERR, so it is printed once per run. */
    private static bool $bannerPrinted = false;

    /** True once the advisory build-drift comparison has run, so it costs one GET per run. */
    private static bool $driftChecked = false;

    private function __construct(
        public readonly string $status,
        public readonly string $baseUri,
        public readonly int $httpStatus,
        public readonly string $poweredBy,
        public readonly string $contentType,
        public readonly string $bodyExcerpt,
        public readonly string $location = ''
    ) {
    }

    /**
     * Probe the configured server, memoised per base URI.
     *
     * @param null|callable(string, string): (array{int, array<string, string>, string}|null) $transport
     *        Test seam. Receives the base URI and the path, and returns [status, headers, body],
     *        or null when nothing is listening. When supplied, the memo is bypassed.
     */
    public static function inspect(string $protocol, string $host, int $port, ?callable $transport = null): self
    {
        $baseUri = sprintf('%s://%s:%d', $protocol, $host, $port);

        // An injected transport belongs to a single test: never read or write the memo for it.
        if (null !== $transport) {
            return self::evaluate($baseUri, $transport);
        }

        return self::$memo[$baseUri] ??= self::evaluate($baseUri, self::realTransport(...));
    }

    public function ok(): bool
    {
        return self::OK === $this->status;
    }

    public function isForeign(): bool
    {
        return self::FOREIGN === $this->status;
    }

    /**
     * Full diagnostic for the FOREIGN case: what answered, what was expected, and the
     * two things that realistically cause it. Written to be readable in a PHPUnit
     * failure message, where it is the only thing the developer will see.
     */
    public function message(): string
    {
        if (!$this->isForeign()) {
            return sprintf('API preflight on %s: %s.', $this->baseUri, $this->status);
        }

        $lines = [
            sprintf('Something is listening on %s, but it is not this API (#922).', $this->baseUri),
            '',
            sprintf('  probed:      GET %s%s', $this->baseUri, self::IDENTITY_PATH),
            sprintf(
                '  responded:   %s%s',
                0 === $this->httpStatus ? 'no HTTP response' : 'HTTP ' . $this->httpStatus,
                '' === $this->contentType ? '' : ' (' . $this->contentType . ')'
            ),
        ];

        if ('' !== $this->poweredBy) {
            $lines[] = sprintf(
                '  served by:   %s — this test process runs PHP %s',
                $this->poweredBy,
                PHP_VERSION
            );
        }
        if ('' !== $this->location) {
            $lines[] = sprintf('  redirect to: %s — not followed, so the fields above describe this port', $this->location);
        }
        if ('' !== $this->bodyExcerpt) {
            $lines[] = sprintf('  body:        %s', $this->bodyExcerpt);
        }

        $lines[] = sprintf('  expected:    HTTP 200 with a JSON body containing "%s"', self::IDENTITY_KEY);
        $lines[] = '';
        $lines[] = 'A stale container (or another project) is holding the port. Recreate it, or move these tests:';
        $lines[] = '  docker compose up -d --force-recreate litcal-api';
        $lines[] = '  ./stop-server.sh && composer start';
        $lines[] = '  API_PORT=<free port> in .env.local';
        $lines[] = '';
        $lines[] = 'This is deliberately an error and not a skip: a foreign server on the port otherwise';
        $lines[] = 'produces a suite-wide burst of failures that reads like a regression in your branch.';

        return implode(PHP_EOL, $lines);
    }

    /**
     * Advisory only, and only a partial answer to "is this the code under test?".
     *
     * Compares one file the API serves verbatim out of `jsondata/schemas/` with the same
     * file in the working tree. A mismatch proves the server is serving a different
     * checkout. A match proves only that this one file agrees: a stale container whose
     * schemas are unchanged — the common case for a code-only change — matches happily.
     * That is why this never fails a test; it only prints a warning worth checking when
     * results look impossible.
     *
     * @param null|callable(string, string): (array{int, array<string, string>, string}|null) $transport
     * @return string|null A human-readable warning, or null when nothing looks wrong (or
     *                     when the comparison could not be made at all).
     */
    public function buildDrift(string $projectRoot, ?callable $transport = null): ?string
    {
        if (!$this->ok()) {
            return null;
        }

        $localFile = rtrim($projectRoot, DIRECTORY_SEPARATOR) . '/jsondata/schemas/LitCal.json';
        if (!is_file($localFile)) {
            return null;
        }

        $transport ??= self::realTransport(...);
        $response    = $transport($this->baseUri, self::BUILD_PROBE_PATH);
        if (null === $response || 200 !== $response[0]) {
            return null;
        }

        $servedHash = hash('sha256', $response[2]);
        $localHash  = hash('sha256', (string) file_get_contents($localFile));
        if ($servedHash === $localHash) {
            return null;
        }

        return sprintf(
            'API build drift: %s%s does not match this working tree\'s jsondata/schemas/LitCal.json '
            . '(served sha256 %s, local %s). The server on %s is serving a different checkout — '
            . 'results from Routes/ and WebSocket/ describe THAT build, not yours.',
            $this->baseUri,
            self::BUILD_PROBE_PATH,
            substr($servedHash, 0, 12),
            substr($localHash, 0, 12),
            $this->baseUri
        );
    }

    /** Print the FOREIGN diagnostic to STDERR once per process, ahead of the per-class errors. */
    public function announceOnce(): void
    {
        if (self::$bannerPrinted || !$this->isForeign()) {
            return;
        }
        self::$bannerPrinted = true;
        fwrite(STDERR, PHP_EOL . $this->message() . PHP_EOL . PHP_EOL);
    }

    /**
     * Run the advisory build comparison once per process and print any warning to STDERR.
     * Never fails anything: see buildDrift() for what a clean result does and does not prove.
     */
    public function warnOnBuildDriftOnce(string $projectRoot): void
    {
        if (self::$driftChecked) {
            return;
        }
        self::$driftChecked = true;

        $drift = $this->buildDrift($projectRoot);
        if (null !== $drift) {
            fwrite(STDERR, PHP_EOL . 'WARNING: ' . $drift . PHP_EOL . PHP_EOL);
        }
    }

    /** Drop the memo and the once-per-run latches. For tests of this class only. */
    public static function reset(): void
    {
        self::$memo          = [];
        self::$bannerPrinted = false;
        self::$driftChecked  = false;
    }

    /**
     * @param callable(string, string): (array{int, array<string, string>, string}|null) $transport
     */
    private static function evaluate(string $baseUri, callable $transport): self
    {
        $response = $transport($baseUri, self::IDENTITY_PATH);

        if (null === $response) {
            return new self(self::NOT_LISTENING, $baseUri, 0, '', '', '');
        }

        [$httpStatus, $headers, $body] = $response;

        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            $normalizedHeaders[strtolower($name)] = $value;
        }
        $poweredBy   = $normalizedHeaders['x-powered-by'] ?? ( $normalizedHeaders['server'] ?? '' );
        $contentType = $normalizedHeaders['content-type'] ?? '';

        $location = $normalizedHeaders['location'] ?? '';

        // A redirect is FOREIGN by construction: `$isOurs` requires 200, and this API answers
        // /calendars directly. Reported with its target, because the realistic cause is a
        // misconfigured base URI (API_PROTOCOL/API_HOST/API_PORT) rather than a stale container,
        // and those two need different fixes.
        $decoded = json_decode($body, true);
        $isOurs  = 200 === $httpStatus && is_array($decoded) && array_key_exists(self::IDENTITY_KEY, $decoded);

        return new self(
            $isOurs ? self::OK : self::FOREIGN,
            $baseUri,
            $httpStatus,
            $poweredBy,
            $contentType,
            $isOurs ? '' : self::excerpt($body),
            $isOurs ? '' : $location
        );
    }

    /** Single-line, bounded excerpt of a response body, for the diagnostic. */
    private static function excerpt(string $body): string
    {
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $body));
        if ('' === $collapsed) {
            return '(empty)';
        }
        return strlen($collapsed) > 160 ? substr($collapsed, 0, 157) . '...' : $collapsed;
    }

    /**
     * The real probe: a TCP connect (so "nothing listening" is answered without waiting on
     * an HTTP timeout) followed by one GET.
     *
     * @return array{int, array<string, string>, string}|null
     */
    private static function realTransport(string $baseUri, string $path): ?array
    {
        $parts = parse_url($baseUri);
        $host  = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : '';
        $port  = is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : 80;
        if ('' === $host) {
            return null;
        }

        $socket = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errno, $errstr, 2.0);
        if (false === $socket) {
            return null;
        }
        fclose($socket);

        try {
            $client   = new Client([
                'base_uri'        => $baseUri,
                'timeout'         => 10,
                'connect_timeout' => 2,
                'http_errors'     => false,
                // Guzzle follows up to 5 redirects by default. Following one here would make
                // every field below describe the redirect TARGET while the message still names
                // this base URI — the probe would report on a server the tests never talk to,
                // and a 3xx that happened to land on a healthy API would be reported as OK.
                'allow_redirects' => false,
            ]);
            $response = $client->get($path, ['headers' => ['Accept' => 'application/json']]);
            $headers  = [];
            foreach ($response->getHeaders() as $name => $values) {
                $headers[$name] = implode(', ', $values);
            }
            return [$response->getStatusCode(), $headers, (string) $response->getBody()];
        } catch (TransferException $e) {
            // The port accepted a connection but no HTTP response came back: still "something
            // is there that isn't us", which is exactly the FOREIGN case.
            return [0, [], 'transport error: ' . $e->getMessage()];
        }
    }
}
