<?php

namespace LiturgicalCalendar\Api\Http\Logs;

use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

class PrettyLineFormatter extends LineFormatter
{
    private const COLORS = [
        'DEBUG'     => "\033[0;34m", // Blue
        'INFO'      => "\033[0;32m", // Green
        'NOTICE'    => "\033[0;36m", // Cyan
        'WARNING'   => "\033[0;33m", // Yellow
        'ERROR'     => "\033[0;31m", // Red
        'CRITICAL'  => "\033[1;31m", // Bright Red
        'ALERT'     => "\033[1;33m", // Bright Yellow
        'EMERGENCY' => "\033[1;41m", // Red background
    ];

    private const NC = "\033[0m"; // Reset

    public function format(LogRecord $record): string
    {
        $levelName = strtoupper($record->level->name);

        $color          = self::COLORS[$levelName] ?? self::NC;
        $coloredMessage = $color . $record->message . self::NC;
        $record         = $record->with(message: $coloredMessage);

        // Format main message + stack trace
        $output = parent::format($record);

        // Append extra fields in pretty-printed form
        if (!empty($record->extra)) {
            $output .= "Extra:\n" . $this->prettyPrint($record->extra, 1) . "\n";
        }

        return $output;
    }

    private function prettyPrint(mixed $value, int $level = 0): string
    {
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
                    $lines[] = $this->prettyPrint($v, $level + 1);
                } else {
                    $lines[] = $prefix . $this->prettyPrint($v, 0);
                }
            }
            return implode("\n", $lines);
        }

        if (is_object($value)) {
            // convert to array for logging
            return $this->prettyPrint(get_object_vars($value), $level);
        }

        return $indent . '[unserializable]';
    }
}
