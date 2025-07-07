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
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        $tz = json_decode(json_encode($this->getTimezone()), true);
        return [
            'date' => $this->format('c'), //serialize the DateTime object as a PHP timestamp
            ...$tz
        ];
    }
}
