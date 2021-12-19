<?php

class LitLocale {
    const ENGLISH               = "EN";
    const FRENCH                = "FR";
    const GERMAN                = "DE";
    const ITALIAN               = "IT";
    const LATIN                 = "LA";
    const PORTUGUESE            = "PT";
    const SPANISH               = "ES";
    public static array $values = [ "EN", "FR", "DE", "IT", "LA", "PT", "ES" ];

    public static function isValid( $value ) {
        return in_array( $value, self::$values );
    }
}
