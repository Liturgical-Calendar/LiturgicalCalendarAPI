<?php

namespace LiturgicalCalendar\Api\Http\Logs;

use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Http\Message\ResponseInterface;

class PrettyLineFormatter extends LineFormatter
{
    private const RED           = "\033[0;31m"; // Red
    private const GREEN         = "\033[0;32m"; // Green
    private const YELLOW        = "\033[0;33m"; // Yellow
    private const BLUE          = "\033[0;34m"; // Blue
    private const CYAN          = "\033[0;36m"; // Cyan
    private const BRIGHT_RED    = "\033[1;31m"; // Bright Red
    private const BRIGHT_YELLOW = "\033[1;33m"; // Bright Yellow
    private const RED_BG        = "\033[1;41m"; // Red background
    private const NC            = "\033[0m"; // No Color

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
            if (is_bool($value)) {
                $text = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $text = 'null';
            } else {
                $text = (string) $value;
            }
            return $indent . $text;
        }

        if (is_array($value)) {
            $lines = [];
            foreach ($value as $k => $v) {
                $prefix = $indent . $k . ': ';
                if (is_array($v) || is_object($v)) {
                    $lines[] = $indent . $k . ':';
                    $lines[] = $this->prettyPrint($v, $level + 1, $maxDepth);
                } else {
                    $lines[] = $prefix . $this->prettyPrint($v, $level, $maxDepth);
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
            Level::Debug     => self::BLUE,          // Blue
            Level::Info      => self::GREEN,         // Green
            Level::Notice    => self::CYAN,          // Cyan
            Level::Warning   => self::YELLOW,        // Yellow
            Level::Error     => self::RED,           // Red
            Level::Critical  => self::BRIGHT_RED,    // Bright Red
            Level::Alert     => self::BRIGHT_YELLOW, // Bright Yellow
            Level::Emergency => self::RED_BG,        // Red background
        };
    }

    private static function applyColorToMessage(LogRecord $record): LogRecord
    {
        // If message already contains ANSI, assume it's colored by a processor.
        if (str_contains($record->message, "\033[")) {
            return $record;
        }
        if (
            isset($record->context)
            && isset($record->context['type'])
            && $record->context['type'] === 'response'
            && isset($record->context['response'])
            && $record->context['response'] instanceof ResponseInterface
        ) {
            // If we are dealing with a response, we color the message according to the response status
            $coloredMessage = $record->message;
            $status         = $record->context['response']->getStatusCode();
            if ($status >= 500) {
                $coloredMessage = self::RED . $record->message . self::NC;
            } elseif ($status >= 400) {
                $coloredMessage = self::YELLOW . $record->message . self::NC;
            } else {
                $coloredMessage = self::GREEN . $record->message . self::NC;
            }
        } else {
            // otherwise we color the message according to the log level
            $color          = self::logLevelToColor($record->level);
            $coloredMessage = $color . $record->message . self::NC;
        }
        return $record->with(message: $coloredMessage);
    }
}
