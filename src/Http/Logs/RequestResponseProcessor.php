<?php

namespace LiturgicalCalendar\Api\Http\Logs;

use Monolog\LogRecord;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

class RequestResponseProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $ctx = $record->context ?? [];
        if (isset($ctx['type'])) {
            switch ($ctx['type']) {
                case 'request':
                    if (!isset($ctx['request']) || false === ( $ctx['request'] instanceof ServerRequestInterface )) {
                        throw new \RuntimeException('Expected request object in context of RequestResponseProcessor when invoked with context of type request');
                    }
                    $request  = $ctx['request'];
                    $protocol = $request->getProtocolVersion();

                    // add extra fields
                    $record = $record->with(extra: array_merge($record->extra, [
                        'pid'        => getmypid(),
                        'protocol'   => "HTTP/{$protocol}",
                        'headers'    => self::sanitizeHeaders($request->getHeaders()),
                        'request_id' => $ctx['request_id'],
                    ]));

                    return $record;
                case 'response':
                    if (!isset($ctx['response']) || false === ( $ctx['response'] instanceof ResponseInterface )) {
                        throw new \RuntimeException('Expected response object in context of RequestResponseProcessor when invoked with context of type response');
                    }
                    $response = $ctx['response'];
                    $status   = $response->getStatusCode();
                    $protocol = $response->getProtocolVersion();

                    // add extra fields
                    $record = $record->with(extra: array_merge($record->extra, [
                        'pid'         => getmypid(),
                        'protocol'    => "HTTP/{$protocol}",
                        'headers'     => self::sanitizeHeaders($response->getHeaders()),
                        'status_code' => $status,
                        'response_id' => $ctx['request_id'] // correlate with request
                    ]));

                    return $record;
                default:
                    throw new \RuntimeException('Cannot process either request or response for logging if context type is not set to either request or response');
            }
        } else {
            throw new \RuntimeException('Cannot process either request or response for logging if context type is not set to either request or response');
        }
    }

    /**
     * Sanitizes an array of HTTP headers by replacing sensitive information with "[redacted]" string.
     *
     * Sensitive headers are:
     *   - Authorization
     *   - Cookie
     *   - Set-Cookie
     *   - Proxy-Authorization
     *   - X-API-Key
     *   - X-Auth-Token
     *
     * @param string[][] $headers an array of HTTP headers
     * @return string[][] an array of sanitized HTTP headers
     */
    private static function sanitizeHeaders(array $headers): array
    {
        $sensitive = ['authorization', 'cookie', 'set-cookie', 'proxy-authorization', 'x-api-key', 'x-auth-token'];
        $out       = [];
        foreach ($headers as $name => $values) {
            $out[$name] = in_array(strtolower($name), $sensitive, true) ? ['[redacted]'] : $values;
        }
        return $out;
    }
}
