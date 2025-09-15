<?php

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Http\Logs\RequestResponseProcessor;
use LiturgicalCalendar\Api\Router;
use Monolog\Logger;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class LoggingMiddleware implements MiddlewareInterface
{
    private static string $logsFolder;
    private Logger $logger;
    private RequestResponseProcessor $processor;

    public function __construct(bool $debug = false)
    {
        if (false === isset(self::$logsFolder)) {
            self::$logsFolder = Router::$apiFilePath . 'logs';
            if (!file_exists(self::$logsFolder)) {
                mkdir(self::$logsFolder);
            }
        }

        $this->processor = new RequestResponseProcessor();
        $this->logger    = LoggerFactory::createApiLogger(self::$logsFolder, $debug, $this->processor);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $request->getAttribute('request_id');

        $this->processor->setRequest($request);
        $this->logger->debug('Incoming request', [
            'request_id' => $requestId,
            'headers'    => $request->getHeaders(),
            'query'      => $request->getQueryParams(),
            'body'       => (string) $request->getBody(),
            'type'       => 'request',
            'pid'        => getmypid(),
        ]);

        $response = $handler->handle($request);

        // Add response context to log entries
        $this->processor->setResponse($response);
        $this->logger->debug('Outgoing response', [
            'request_id' => $requestId,
            'status'     => $response->getStatusCode(),
            'headers'    => $response->getHeaders(),
            'body'       => (string) $response->getBody(),
            'type'       => 'response',
            'pid'        => getmypid(),
        ]);

        return $response;
    }
}
