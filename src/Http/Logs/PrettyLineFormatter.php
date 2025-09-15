<?php

namespace LiturgicalCalendar\Api\Http\Logs;

use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;

class PrettyLineFormatter extends LineFormatter
{
    private const NC = "\033[0m"; // Reset

    public function format(LogRecord $record): string
    {
        $record = $this->applyColorToMessage($record);

        // Format main message + stack trace
        $output = parent::format($record);

        // Append extra fields in pretty-printed form
        if (!empty($record->extra)) {
            $output .= "Extra:\n" . $this->prettyPrint($record->extra, 1) . "\n";
        }

        return $output;
    }

    private function prettyPrint(mixed $value, int $level = 0, int $maxDepth = 10): string
    {
        if ($level > $maxDepth) {
            return str_repeat('    ', $level) . '[max depth reached]';
        }
        $indent = str_repeat('    ', $level);

        if (is_scalar($value) || $value === null) {
            return $indent . (string) $value;
        }

        if (is_array($value)) {
            $lines = [];
            foreach ($value as $k => $v) {
                $prefix = $indent . $k . ': ';
                if (is_array($v) || is_object($v)) {
                    $lines[] = $indent . $k . ':';
                    $lines[] = $this->prettyPrint($v, $level + 1, $maxDepth);
                } else {
                    $lines[] = $prefix . $this->prettyPrint($v, 0, $maxDepth);
                }
            }
            return implode("\n", $lines);
        }

        if (is_object($value)) {
            // convert to array for logging
            return $this->prettyPrint(get_object_vars($value), $level, $maxDepth);
        }

        return $indent . '[unserializable]';
    }

    private static function logLevelToColor(Level $level): string
    {
        return match ($level) {
            Level::Debug     => "\033[0;34m", // Blue
            Level::Info      => "\033[0;32m", // Green
            Level::Notice    => "\033[0;36m", // Cyan
            Level::Warning   => "\033[0;33m", // Yellow
            Level::Error     => "\033[0;31m", // Red
            Level::Critical  => "\033[1;31m", // Bright Red
            Level::Alert     => "\033[1;33m", // Bright Yellow
            Level::Emergency => "\033[1;41m", // Red background
        };
    }

    private static function applyColorToMessage(LogRecord $record): LogRecord
    {
        $color          = self::logLevelToColor($record->level);
        $coloredMessage = $color . $record->message . self::NC;
        return $record->with(message: $coloredMessage);
    }
}
