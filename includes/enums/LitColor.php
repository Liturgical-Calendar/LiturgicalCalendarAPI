<?php

class LitColor {
    const GREEN     = "green";
    const PURPLE    = "purple";
    const WHITE     = "white";
    const RED       = "red";
    const PINK      = "pink";
    public static array $values = [ "green", "purple", "white", "red", "pink" ];

    public static function isValid( string $value ) {
        return in_array( $value, self::$values );
    }

    public static function areValid( array $values ){
        return empty( array_diff( $values, self::$values ) );
    }
}
