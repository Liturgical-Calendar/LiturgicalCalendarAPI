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

    public static function i18n( string $value, string $locale ) : string {
        switch( $value ) {
            case self::GREEN:
                /**translators: context = liturgical color */
                return $locale === 'LA' ? 'viridis'     : _( "green" );
            case self::PURPLE:
                /**translators: context = liturgical color */
                return $locale === 'LA' ? 'purpura'     : _( "purple" );
            case self::WHITE:
                /**translators: context = liturgical color */
                return $locale === 'LA' ? 'albus'       : _( "white" );
            case self::RED:
                /**translators: context = liturgical color */
                return $locale === 'LA' ? 'ruber'       : _( "red" );
            case self::PINK:
                /**translators: context = liturgical color */
                return $locale === 'LA' ? 'rosea'       : _( "pink" );
        }
    }
}
