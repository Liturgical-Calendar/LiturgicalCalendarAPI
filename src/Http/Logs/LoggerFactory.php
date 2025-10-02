<?php

namespace LiturgicalCalendar\Api\Http\Logs;

use LiturgicalCalendar\Api\Http\Logs\PrettyLineFormatter;
use LiturgicalCalendar\Api\Router;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Processor\WebProcessor;

class LoggerFactory
{
    /** @var Logger[] $apiLoggers */
    private static array $apiLoggers = [];
    private static string $logsFolder;

    private static function validateLogsFolder(string $logsFolder): void
    {
        if (empty($logsFolder) || !is_dir($logsFolder) || !is_writable($logsFolder)) {
            throw new \InvalidArgumentException("Logs folder must be a valid, writable directory: {$logsFolder}");
        }
    }

    private static function resolveLogsFolder(?string $logsFolder): string
    {
        if (is_string($logsFolder)) {
            self::validateLogsFolder($logsFolder);
            self::$logsFolder = $logsFolder;
        } elseif (isset(self::$logsFolder) && is_string(self::$logsFolder)) {
            $logsFolder = self::$logsFolder;
            self::validateLogsFolder($logsFolder);
        } else {
            self::$logsFolder = Router::$apiFilePath . 'logs';
            if (!is_dir(self::$logsFolder)) {
                if (!@mkdir(self::$logsFolder, 0755, true) && !is_dir(self::$logsFolder)) {
                    throw new \RuntimeException('Failed to create logs directory: ' . self::$logsFolder);
                }
            }
            $logsFolder = self::$logsFolder;
        }
        return $logsFolder;
    }

    /**
     * Creates (or retrieves if already created) a Monolog logger instance for the API.
     *
     * @param bool $debug Whether to enable debug level logging.
     * @param string $logName The base name for the log files (e.g., 'api' will create 'api.log', and 'api.json.log' if $includeJsonHandler is true).
     * @param string|null $logsFolder The folder where log files will be stored. If null, defaults to 'logs' directory in project root.
     * @param int $maxFiles The maximum number of log files to keep (for rotation).
     * @param bool $includeJsonHandler Whether to include a JSON formatted log handler.
     * @param bool $includeProcessors Whether to include processors for adding extra context to log entries.
     * @return Logger The configured Monolog logger instance.
     * @throws \InvalidArgumentException If the provided logs folder is invalid or not writable.
     * @throws \RuntimeException If unable to create the logs folder.
     */
    public static function create(
        string $logName = 'api',
        ?string $logsFolder = null,
        int $maxFiles = 30,
        bool $debug = false,
        bool $includeJsonHandler = true,
        bool $includeProcessors = true
    ): Logger {
        if (isset(self::$apiLoggers[$logName]) && self::$apiLoggers[$logName] instanceof Logger) {
            return self::$apiLoggers[$logName];
        }

        // Validate/create logs folder
        $logsFolder = self::resolveLogsFolder($logsFolder);
        $logger     = new Logger('litcalapi');

        // --- Plain text rotating file ---
        $plainHandler   = new RotatingFileHandler("{$logsFolder}/{$logName}.log", $maxFiles, $debug ? Level::Debug : Level::Info);
        $plainFormatter = new PrettyLineFormatter(
            "[%datetime%] %level_name%: %message%\n",
            'Y-m-d H:i:s',
            true,
            true,
            false
        );
        $plainHandler->setFormatter($plainFormatter);
        $logger->pushHandler($plainHandler);

        if ($includeJsonHandler) {
            $jsonHandler   = new RotatingFileHandler("{$logsFolder}/{$logName}.json.log", $maxFiles, $debug ? Level::Debug : Level::Info);
            $jsonFormatter = new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true);
            $jsonHandler->setFormatter($jsonFormatter);
            $logger->pushHandler($jsonHandler);
        }

        if ($includeProcessors) {
            $logger->pushProcessor(new WebProcessor());
            $logger->pushProcessor(new RequestResponseProcessor());
        }

        self::$apiLoggers[$logName] = $logger;
        return $logger;
    }
}
