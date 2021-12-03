<?php

class LIT_LOCALE {
    const EN                = "EN";
    const IT                = "IT";
    const LA                = "LA";
    public static array $values = [ "EN", "IT", "LA" ];

    public static function isValid( $value ) {
        return in_array( $value, self::$values );
    }
}
