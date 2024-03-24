<?php

class LitSeason {
    const ADVENT        = "ADVENT";
    const CHRISTMAS     = "CHRISTMAS";
    const LENT          = "LENT";
    const EASTER        = "EASTER";
    const ORDINARY_TIME = "ORDINARY_TIME";
    public static array $values = [ "ADVENT", "CHRISTMAS", "LENT", "EASTER", "ORDINARY_TIME" ];

    public static function isValid( string $value ) {
        return in_array( $value, self::$values );
    }

    public static function i18n( string $value, string $locale ) : string {
        switch( $value ) {
            case self::ADVENT:
                /**translators: context = liturgical season */
                return $locale === LitLocale::LATIN ? 'Tempus Adventus'     : _( "Advent" );
            case self::CHRISTMAS:
                /**translators: context = liturgical season */
                return $locale === LitLocale::LATIN ? 'Tempus Nativitatis'  : _( "Christmas" );
            case self::LENT:
                /**translators: context = liturgical season */
                return $locale === LitLocale::LATIN ? 'Tempus Quadragesima' : _( "Lent" );
            case self::EASTER:
                /**translators: context = liturgical season */
                return $locale === LitLocale::LATIN ? 'Tempus Paschale'     : _( "Easter" );
            case self::ORDINARY_TIME:
                /**translators: context = liturgical season */
                return $locale === LitLocale::LATIN ? 'Tempus per Annum'    : _( "Ordinary Time" );
        }
    }
}
