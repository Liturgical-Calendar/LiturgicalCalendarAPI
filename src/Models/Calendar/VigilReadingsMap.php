<?php

namespace LiturgicalCalendar\Api\Models\Calendar;

final class VigilReadingsMap
{
    /**
     * @var array<string,ReadingsFestive>
     */
    private static array $vigilReadingsMap = [];

    public static function add(string $key, ReadingsFestive $readings): void
    {
        if (false === array_key_exists($key, static::$vigilReadingsMap)) {
            static::$vigilReadingsMap[$key] = $readings;
        }
    }

    public static function get(string $key): ?ReadingsFestive
    {
        return static::$vigilReadingsMap[$key] ?? null;
    }
}
