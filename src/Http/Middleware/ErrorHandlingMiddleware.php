<?php

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Http\Exception\ApiException;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use Monolog\Logger;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ErrorHandlingMiddleware implements MiddlewareInterface
{
    private ResponseFactoryInterface $responseFactory;
    private bool $debug;
    private Logger $debugLogger;
    private Logger $errorLogger;

    // Store the current request so processors can use it
    private ?ServerRequestInterface $currentRequest = null;

    public function __construct(ResponseFactoryInterface $responseFactory, bool $debug = false)
    {
        $this->responseFactory = $responseFactory;
        $this->debug           = $debug;
        $debugLogger           = LoggerFactory::createApiLogger($debug);
        $this->debugLogger     = $debugLogger;
        $errorLogger           = LoggerFactory::createApiLogger($debug, 'api-error');
        $this->errorLogger     = $errorLogger;

        // Catch fatal errors
        register_shutdown_function(function () use ($errorLogger) {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                try {
                    $errorLogger->critical(sprintf(
                        'Fatal error: %s in %s:%d | memory_peak_usage=%s',
                        $error['message'],
                        $error['file'],
                        $error['line'],
                        self::formatBytes(memory_get_peak_usage(true))
                    ), [
                        // Pass along the request (if available) for processors
                        'request' => $this->currentRequest,
                    ]);
                } catch (\Throwable $e) {
                    // Fallback to error_log if logger fails during shutdown
                    error_log('Fatal error logging failed: ' . $e->getMessage());
                }
            }
        });
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->currentRequest = $request;

        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            $logMessage = sprintf(
                "%s in %s:%d\nStack trace:\n%s",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            );

            // context is available to processors, but only used by JSON formatter for final output
            $logContext = [
                'type'       => 'request',
                'request'    => $request,
                'request_id' => $request->getAttribute('request_id'),
                'exception'  => $e
            ];

            $this->debugLogger->error(
                $logMessage,
                $logContext
            );

            $this->errorLogger->error(
                $logMessage,
                $logContext
            );

            // Default values
            $status  = 500;
            $problem = [
                'type'   => 'about:blank',
                'title'  => 'Internal Server Error',
                'status' => $status,
                'detail' => $this->debug ? $e->getMessage() : 'An unexpected error occurred.',
            ];

            if ($e instanceof ApiException) {
                // Let the ApiException define its structure
                $status  = $e->getStatus();
                $problem = $e->toArray($this->debug);
            } elseif ($this->debug) {
                // For non-ApiExceptions in debug mode, add trace info
                $problem['file']  = $e->getFile();
                $problem['line']  = $e->getLine();
                $problem['trace'] = explode("\n", $e->getTraceAsString());
            }

            $response     = $this->responseFactory->createResponse($status);
            $responseBody = json_encode($problem, JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (false === $responseBody) {
                error_log('Failed to encode error to application/problem+json: ' . json_last_error_msg());
                return $response;
            }

            $response
                ->getBody()
                ->write($responseBody);

            return $response
                ->withHeader('Content-Type', 'application/problem+json')
                ->withHeader('Access-Control-Allow-Origin', '*');
        }
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        return number_format($bytes / ( 1024 ** $power ), 2) . ' ' . $units[$power];
    }
}
