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

    /**
     * @param ?string $logsFolder If a string is passed, it is expected to be a valid and existing folder;
     *                            if null, will default to `Router::$apiFilePath . 'logs'`
     */
    public static function createApiLogger(bool $debug = false, string $logName = 'api', ?string $logsFolder = null, int $maxFiles = 30): Logger
    {
        if (isset(self::$apiLoggers[$logName]) && self::$apiLoggers[$logName] instanceof Logger) {
            return self::$apiLoggers[$logName];
        }

        if (is_string($logsFolder)) {
            if (empty($logsFolder) || !is_dir($logsFolder) || !is_writable($logsFolder)) {
                throw new \InvalidArgumentException("Logs folder must be a valid, writable directory: {$logsFolder}");
            }
            self::$logsFolder = $logsFolder;
        } elseif (isset(self::$logsFolder) && is_string(self::$logsFolder)) {
            $logsFolder = self::$logsFolder;
            if (empty($logsFolder) || !is_dir($logsFolder) || !is_writable($logsFolder)) {
                throw new \InvalidArgumentException("Logs folder must be a valid, writable directory: {$logsFolder}");
            }
        } else {
            self::$logsFolder = Router::$apiFilePath . 'logs';
            if (!is_dir(self::$logsFolder)) {
                if (!@mkdir(self::$logsFolder, 0755, true) && !is_dir(self::$logsFolder)) {
                    throw new \RuntimeException('Failed to create logs directory: ' . self::$logsFolder);
                }
            }
            $logsFolder = self::$logsFolder;
        }

        $logger = new Logger('litcalapi');

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

        // --- JSON rotating file (for aggregation) ---
        $jsonHandler   = new RotatingFileHandler("{$logsFolder}/{$logName}.json.log", $maxFiles, $debug ? Level::Debug : Level::Info);
        $jsonFormatter = new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true);
        $jsonHandler->setFormatter($jsonFormatter);
        $logger->pushHandler($jsonHandler);

        // --- WebProcessor adds request / response info automatically ---
        $logger->pushProcessor(new WebProcessor()); // adds url, method, server params, etc.
        $logger->pushProcessor(new RequestResponseProcessor());

        self::$apiLoggers[$logName] = $logger;
        return $logger;
    }

    public static function createPidLogger(bool $debug = false, string $logName = 'api-pid', ?string $logsFolder = null, int $maxFiles = 30): Logger
    {
        if (isset(self::$apiLoggers[$logName]) && self::$apiLoggers[$logName] instanceof Logger) {
            return self::$apiLoggers[$logName];
        }

        if (is_string($logsFolder)) {
            if (empty($logsFolder) || !is_dir($logsFolder) || !is_writable($logsFolder)) {
                throw new \InvalidArgumentException("Logs folder must be a valid, writable directory: {$logsFolder}");
            }
            self::$logsFolder = $logsFolder;
        } elseif (isset(self::$logsFolder) && is_string(self::$logsFolder)) {
            $logsFolder = self::$logsFolder;
            if (empty($logsFolder) || !is_dir($logsFolder) || !is_writable($logsFolder)) {
                throw new \InvalidArgumentException("Logs folder must be a valid, writable directory: {$logsFolder}");
            }
        } else {
            self::$logsFolder = Router::$apiFilePath . 'logs';
            if (!is_dir(self::$logsFolder)) {
                if (!@mkdir(self::$logsFolder, 0755, true) && !is_dir(self::$logsFolder)) {
                    throw new \RuntimeException('Failed to create logs directory: ' . self::$logsFolder);
                }
            }
            $logsFolder = self::$logsFolder;
        }

        $logger = new Logger('litcalapi');

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
        return $logger;
    }
}
