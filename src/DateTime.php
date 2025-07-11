<?php

namespace LiturgicalCalendar\Api;

class DateTime extends \DateTime implements \JsonSerializable
{
    /**
     * Json Serialize
     *
     * When json encoding a DateTime object, serialize it as an array with a date key
     * containing the ISO-8601 formatted date and time, and keys for the timezone
     *
     * @see https://www.php.net/manual/en/class.datetime.php
     * @return array{date: string, timezone: string, timezone_type: int}
     */
    public function jsonSerialize(): array
    {
        $timezone = $this->getTimezone();
        if ($timezone === false) {
            throw new \RuntimeException('Failed to get timezone from DateTime object');
        }

        $tzJson = json_encode($timezone);
        if ($tzJson === false) {
            throw new \RuntimeException('Failed to encode timezone to JSON: ' . json_last_error_msg());
        }

        /** @var array{timezone: string, timezone_type: int} */
        $tz = json_decode($tzJson, true);
        return [
            'date' => $this->format('c'), //serialize the DateTime object as a PHP timestamp
            ...$tz
        ];
    }
}
