<?php

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Http\Exception\ApiException;
use LiturgicalCalendar\Api\Http\Logs\PrettyLineFormatter;
use LiturgicalCalendar\Api\Router;
use Monolog\Logger;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ErrorHandlingMiddleware implements MiddlewareInterface
{
    private ResponseFactoryInterface $responseFactory;
    private bool $debug;
    private static string $logsFolder;
    private Logger $logger;

    public function __construct(ResponseFactoryInterface $responseFactory, bool $debug = false)
    {
        $this->responseFactory = $responseFactory;
        $this->debug           = $debug;

        if (false === isset(self::$logsFolder)) {
            self::$logsFolder = Router::$apiFilePath . 'logs';
            if (!file_exists(self::$logsFolder)) {
                if (!mkdir(self::$logsFolder, 0755, true)) {
                    throw new \RuntimeException('Failed to create logs directory: ' . self::$logsFolder);
                }
            }
        }

        // === Rotating file handler with plain text ===
        $plainHandler  = new RotatingFileHandler(self::$logsFolder . DIRECTORY_SEPARATOR . 'api-error.log', 30, Level::Info);
        $lineFormatter = new PrettyLineFormatter(
            "[%datetime%] %level_name%: %message%\n", // custom format
            'Y-m-d H:i:s',                            // date format
            true,                                     // allow inline breaks
            true,                                     // ignore empty context/extra
            false                                     // include stacktraces
        );
        $plainHandler->setFormatter($lineFormatter);

        // === Rotating file handler with JSON formatting (better for log aggregation systems like ELK / Loki / CloudWatch) ===
        $jsonHandler   = new RotatingFileHandler(self::$logsFolder . DIRECTORY_SEPARATOR . 'api-error.json.log', 30, Level::Debug);
        $jsonFormatter = new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true);
        $jsonHandler->setFormatter($jsonFormatter);

        // === Logger setup ===
        $this->logger = new Logger('litcalapi');
        $this->logger->pushHandler($plainHandler);
        $this->logger->pushHandler($jsonHandler);

        // Catch fatal errors
        $logger = $this->logger;
        register_shutdown_function(function () use ($logger) {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                try {
                    $logger->critical(sprintf(
                        'Fatal error: %s in %s:%d',
                        $error['message'],
                        $error['file'],
                        $error['line']
                    ));
                } catch (\Throwable $e) {
                    // Fallback to error_log if logger fails during shutdown
                    error_log('Fatal error logging failed: ' . $e->getMessage());
                }
            }
        });
    }


    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            $this->logger->pushProcessor(function (LogRecord $record) use ($request): LogRecord {
                $extra               = $record->extra;
                $extra['request_id'] = $request->getAttribute('request_id');
                $extra['route']      = $request->getUri()->getPath();
                $extra['method']     = $request->getMethod();
                $extra['query']      = $request->getUri()->getQuery();
                $extra['pid']        = getmypid();

                // Selected headers
                $headersToLog     = ['Accept', 'Accept-Language', 'User-Agent']; // 'Authorization'
                $extra['headers'] = [];
                foreach ($headersToLog as $name) {
                    $extra['headers'][$name] = $request->getHeaderLine($name);
                }

                return $record->with(extra: $extra);
            });

            $this->logger->error(
                sprintf(
                    "%s in %s:%d\nStack trace:\n%s",
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    $e->getTraceAsString()
                ),
                [ 'exception' => $e ] // 👈 only used by JSON handler
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
                $problem['trace'] = $e->getTrace();
            }

            $response = $this->responseFactory->createResponse($status);
            $response
                ->getBody()
                ->write(json_encode($problem, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $response
                ->withHeader('Content-Type', 'application/problem+json')
                ->withHeader('Access-Control-Allow-Origin', '*');
        }
    }
}
