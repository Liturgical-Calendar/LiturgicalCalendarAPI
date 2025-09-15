<?php

namespace LiturgicalCalendar\Api\Http\Logs;

use Monolog\LogRecord;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

class RequestResponseProcessor
{
    private ?ServerRequestInterface $request = null;
    private ?ResponseInterface $response     = null;
    private const RED                        = "\033[0;31m";
    private const GREEN                      = "\033[0;32m";
    private const YELLOW                     = "\033[0;33m";
    private const BLUE                       = "\033[0;34m";
    private const NC                         = "\033[0m"; // No Color

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function setResponse(ResponseInterface $response): void
    {
        $this->response = $response;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if (( $record->context['type'] ?? null ) === 'request' && $this->request) {
            $protocol = $this->request->getProtocolVersion();

            // add extra fields
            $record = $record->with(extra: array_merge($record->extra, [
                'request_id' => $this->request->getAttribute('request_id'),
                'protocol'   => "HTTP/{$protocol}",
                'headers'    => $this->request->getHeaders(),
                'pid'        => getmypid(),
            ]));

            $coloredMessage = self::BLUE . $record->message . self::NC;
            $record         = $record->with(message: $coloredMessage);
        }

        if (( $record->context['type'] ?? null ) === 'response') {
            if ($this->response) {
                $status   = $this->response->getStatusCode();
                $protocol = $this->response->getProtocolVersion();

                // add extra fields
                $record = $record->with(extra: array_merge($record->extra, [
                    'response_id' => $this->request->getAttribute('request_id'),
                    'status_code' => $status,
                    'protocol'    => "HTTP/{$protocol}",
                    'pid'         => getmypid(),
                ]));

                // Change color depending on status
                $coloredMessage = $record->message;
                if ($status >= 500) {
                    $coloredMessage = self::RED . $record->message . self::NC;
                } elseif ($status >= 400) {
                    $coloredMessage = self::YELLOW . $record->message . self::NC;
                } else {
                    $coloredMessage = self::GREEN . $record->message . self::NC;
                }
                $record = $record->with(message: $coloredMessage);
            } else {
                // No response yet → just skip modification
                return $record;
            }
        }

        return $record;
    }
}
