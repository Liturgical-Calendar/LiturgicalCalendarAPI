<?php

namespace LiturgicalCalendar\Api\Http\Logs;

use LiturgicalCalendar\Api\Http\Logs\PrettyLineFormatter;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Processor\WebProcessor;

class LoggerFactory
{
    public static function createApiLogger(string $logsFolder, bool $debug = false, ?RequestResponseProcessor $processor = null, int $maxFiles = 30): Logger
    {
        if (empty($logsFolder) || !is_dir($logsFolder) || !is_writable($logsFolder)) {
            throw new \InvalidArgumentException("Logs folder must be a valid, writable directory: {$logsFolder}");
        }

        $logger = new Logger('litcalapi');

        // --- Plain text rotating file ---
        $plainHandler   = new RotatingFileHandler($logsFolder . '/api.log', $maxFiles, $debug ? Level::Debug : Level::Info);
        $plainFormatter = new PrettyLineFormatter(
            "[%datetime%] %level_name%: %message%\n",
            'Y-m-d H:i:s',
            true,
            true,
            false
        );
        $plainHandler->setFormatter($plainFormatter);
        $logger->pushHandler($plainHandler);

        // --- JSON rotating file (for aggregation) ---
        $jsonHandler   = new RotatingFileHandler($logsFolder . '/api.json.log', $maxFiles, $debug ? Level::Debug : Level::Info);
        $jsonFormatter = new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true);
        $jsonHandler->setFormatter($jsonFormatter);
        $logger->pushHandler($jsonHandler);

        // --- WebProcessor adds request info automatically ---
        $logger->pushProcessor(new WebProcessor()); // adds url, method, server params, etc.

        if ($processor !== null) {
            $logger->pushProcessor($processor);
        }

        return $logger;
    }
}
