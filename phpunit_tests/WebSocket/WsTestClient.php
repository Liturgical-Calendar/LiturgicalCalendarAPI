<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\WebSocket;

/**
 * Minimal synchronous WebSocket client for PHPUnit tests.
 *
 * Implements the RFC 6455 handshake + text-frame send/receive by hand so the
 * test suite doesn't need a separate WebSocket client library as a dev
 * dependency. Only the subset used by phpunit_tests/WebSocket/* is implemented:
 *
 *   - GET upgrade handshake with a generated Sec-WebSocket-Key,
 *   - text frame send (always masked, as required of clients),
 *   - text frame receive (server frames are unmasked),
 *   - clean close with a control frame.
 *
 * Binary frames, continuation frames, fragmented payloads, ping/pong, and
 * frames > 65535 bytes are NOT supported. None of those are needed by the
 * Health WebSocket handler we're exercising — its replies are slim
 * `{type, text, classes, test}` objects since #589 trimmed the `jsonData`
 * payload from non-assertion error frames.
 */
final class WsTestClient
{
    /** @var resource */
    private $sock;

    private function __construct($sock)
    {
        $this->sock = $sock;
    }

    /**
     * Open a WS connection to ws://$host:$port/ and complete the handshake.
     *
     * @throws \RuntimeException on transport failure or non-101 server response.
     */
    public static function connect(string $host, int $port, float $timeoutSeconds = 5.0): self
    {
        $sock = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            $timeoutSeconds
        );
        if ($sock === false) {
            throw new \RuntimeException("Could not connect to ws://$host:$port — $errstr (errno=$errno)");
        }

        stream_set_timeout($sock, (int) $timeoutSeconds);

        $key      = base64_encode(random_bytes(16));
        $expected = base64_encode(
            sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)
        );

        $request = "GET / HTTP/1.1\r\n"
            . "Host: $host:$port\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $key\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";

        fwrite($sock, $request);

        // Read response headers up to the blank line.
        $response = '';
        while (!feof($sock)) {
            $line = fgets($sock);
            if ($line === false) {
                break;
            }
            $response .= $line;
            if ($line === "\r\n") {
                break;
            }
        }

        if (!preg_match('#^HTTP/1\.1 101\b#i', $response)) {
            $firstLine = strtok($response, "\r\n") ?: '(no response)';
            fclose($sock);
            throw new \RuntimeException("WebSocket handshake failed; expected 101 Switching Protocols, got: $firstLine");
        }
        if (!str_contains(strtolower($response), 'sec-websocket-accept: ' . strtolower($expected))) {
            fclose($sock);
            throw new \RuntimeException('WebSocket handshake failed: Sec-WebSocket-Accept did not match.');
        }

        return new self($sock);
    }

    /**
     * Send a text frame. Client frames MUST be masked per RFC 6455 §5.3.
     */
    public function sendText(string $payload): void
    {
        $len = strlen($payload);
        if ($len > 65535) {
            throw new \RuntimeException('Payloads > 65535 bytes are not supported by this minimal client.');
        }

        // First byte: FIN=1, RSV=000, opcode=0x1 (text)
        $frame = chr(0x81);

        // Mask bit + length
        if ($len < 126) {
            $frame .= chr(0x80 | $len);
        } else {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        }

        $mask   = random_bytes(4);
        $frame .= $mask;
        for ($i = 0; $i < $len; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }

        fwrite($this->sock, $frame);
    }

    /**
     * Receive one text frame and return its payload. Blocks until the frame is
     * complete or the socket times out. Server frames are unmasked.
     *
     * @throws \RuntimeException on close-frame, unsupported opcode, or read failure.
     */
    public function receiveText(): string
    {
        $hdr = $this->readExact(2);
        $b0  = ord($hdr[0]);
        $b1  = ord($hdr[1]);

        $fin    = ( $b0 & 0x80 ) !== 0;
        $opcode = $b0 & 0x0F;
        $masked = ( $b1 & 0x80 ) !== 0;
        $len    = $b1 & 0x7F;

        if (!$fin) {
            throw new \RuntimeException('Fragmented frames are not supported by this minimal client.');
        }
        if ($opcode === 0x8) {
            throw new \RuntimeException('Received close frame.');
        }
        if ($opcode !== 0x1) {
            throw new \RuntimeException('Expected a text frame, got opcode 0x' . dechex($opcode));
        }

        if ($len === 126) {
            $ext = $this->readExact(2);
            $len = unpack('n', $ext)[1];
        } elseif ($len === 127) {
            throw new \RuntimeException('Payloads > 65535 bytes are not supported by this minimal client.');
        }

        $maskKey = $masked ? $this->readExact(4) : '';
        $payload = $len > 0 ? $this->readExact($len) : '';

        if ($masked) {
            $unmasked = '';
            for ($i = 0; $i < $len; $i++) {
                $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
            }
            return $unmasked;
        }

        return $payload;
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            // Send a clean close frame (opcode 0x8), masked, no payload.
            $mask  = random_bytes(4);
            $frame = chr(0x88) . chr(0x80) . $mask;
            @fwrite($this->sock, $frame);
            @fclose($this->sock);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function readExact(int $bytes): string
    {
        $buf  = '';
        $left = $bytes;
        while ($left > 0) {
            $chunk = fread($this->sock, $left);
            if ($chunk === false || $chunk === '') {
                $info = stream_get_meta_data($this->sock);
                if (!empty($info['timed_out'])) {
                    throw new \RuntimeException("Read timed out waiting for $left more byte(s).");
                }
                throw new \RuntimeException("Connection closed while reading; $left of $bytes byte(s) outstanding.");
            }
            $buf  .= $chunk;
            $left -= strlen($chunk);
        }
        return $buf;
    }
}
