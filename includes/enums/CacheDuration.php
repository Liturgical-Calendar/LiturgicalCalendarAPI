<?php

class CacheDuration {
    const DAY       = "DAY";
    const WEEK      = "WEEK";
    const MONTH     = "MONTH";
    const YEAR      = "YEAR";
    public static array $values = [ "DAY", "WEEK", "MONTH", "YEAR" ];

    public static function isValid( $value ) {
        return in_array( $value, self::$values );
    }
}
