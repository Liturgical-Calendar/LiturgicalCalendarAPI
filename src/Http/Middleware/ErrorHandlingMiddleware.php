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
    /** @var resource */
    private $stderr;
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
        $stderr                = fopen('php://stderr', 'w');
        if ($stderr === false) {
            throw new \RuntimeException('Failed to open php://stderr for writing.');
        }

        $this->stderr = $stderr;

        // Catch fatal errors
        register_shutdown_function([$this, 'handleShutdown']);

        // Catch uncaught exceptions globally
        set_exception_handler([$this, 'handleUncaughtException']);

        // Escalate PHP warnings/notices to exceptions
        set_error_handler([$this, 'handlePhpWarning']);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->currentRequest = $request;

        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            $this->logException($e, 'error');

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

    /**
     * Convert PHP warnings, notices, etc., into exceptions
     */
    public function handlePhpWarning(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        if (!( error_reporting() & $errno )) {
            return false; // respect current error_reporting
        }

        // Only escalate non-fatal errors
        switch ($errno) {
            case E_WARNING:
            case E_NOTICE:
            case E_USER_WARNING:
            case E_USER_NOTICE:
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
            case E_RECOVERABLE_ERROR:
                throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);

            default:
                // For fatal errors, let PHP handle them (or use register_shutdown_function)
                return false;
        }
    }


    /**
     * Catch fatal errors at shutdown
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            $exception = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
            $this->logException($exception, 'critical');
        }
    }

    /**
     * Catch uncaught exceptions
     */
    public function handleUncaughtException(\Throwable $e): void
    {
        $this->logException($e, 'critical');
        exit(1);
    }

    private function logException(\Throwable $e, string $severity = 'error'): void
    {
        $method = $this->currentRequest?->getMethod() ?? 'N/A';
        $uri    = $this->currentRequest?->getUri()?->__toString() ?? 'N/A';

        $fullMessage = sprintf(
            "[%s %s] %s in %s:%d | memory_peak_usage=%s\nStack trace:\n%s",
            $method,
            $uri,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            self::formatBytes(memory_get_peak_usage(true)),
            $e->getTraceAsString()
        );

        // context is available to processors, but only used by JSON formatter for final output
        $context = [
            'type'       => 'request',
            'request'    => $this->currentRequest,
            'request_id' => $this->currentRequest?->getAttribute('request_id'),
            'exception'  => $e,
        ];

        $this->errorLogger->{$severity}($fullMessage, $context);
        $this->debugLogger->{$severity}($fullMessage, $context);

        fwrite($this->stderr, $fullMessage . PHP_EOL);
    }
}
