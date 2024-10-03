<?php

namespace LiturgicalCalendar\Api;

class DateTime extends \DateTime implements \JsonSerializable
{
    public function jsonSerialize(): array
    {
        $tz = json_decode(json_encode($this->getTimezone()), true);
        $returnArr = [
            'date' => $this->format('c'), //serialize the DateTime object as a PHP timestamp
            ...$tz
        ];
        return $returnArr;
    }
}
