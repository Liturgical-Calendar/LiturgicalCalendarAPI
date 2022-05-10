<?php

class RomanMissal {

    const EDITIO_TYPICA_1970                    = "VATICAN_1970";
    const REIMPRESSIO_EMENDATA_1971             = "VATICAN_1971";
    const EDITIO_TYPICA_SECUNDA_1975            = "VATICAN_1975";
    const EDITIO_TYPICA_TERTIA_2002             = "VATICAN_2002";
    const EDITIO_TYPICA_TERTIA_EMENDATA_2008    = "VATICAN_2008";

    const USA_EDITION_2011                      = "USA_2011";
    const ITALY_EDITION_1983                    = "ITALY_1983";
    const ITALY_EDITION_2020                    = "ITALY_2020";

    public static array $values = [ 
        "VATICAN_1970",
        "VATICAN_1971",
        "VATICAN_1975",
        "VATICAN_2002",
        "VATICAN_2008",
        "USA_2011",
        "ITALY_1983",
        "ITALY_2020"
    ];

    public static array $names = [
        self::EDITIO_TYPICA_1970                    => "Editio Typica 1970",
        self::REIMPRESSIO_EMENDATA_1971             => "Reimpressio Emendata 1971",
        self::EDITIO_TYPICA_SECUNDA_1975            => "Editio Typica Secunda 1975",
        self::EDITIO_TYPICA_TERTIA_2002             => "Editio Typica Tertia 2002",
        self::EDITIO_TYPICA_TERTIA_EMENDATA_2008    => "Editio Typica Tertia Emendata 2008",
        self::USA_EDITION_2011                      => "2011 Roman Missal issued by the USCCB",
        self::ITALY_EDITION_1983                    => "Messale Romano ed. 1983 pubblicata dalla CEI",
        self::ITALY_EDITION_2020                    => "Messale Romano ed. 2020 pubblicata dalla CEI"
    ];

    public static array $jsonFiles = [
        self::EDITIO_TYPICA_1970                    => "data/propriumdesanctis_1970/propriumdesanctis_1970.json",
        self::REIMPRESSIO_EMENDATA_1971             => false,
        self::EDITIO_TYPICA_SECUNDA_1975            => false,
        self::EDITIO_TYPICA_TERTIA_2002             => "data/propriumdesanctis_2002/propriumdesanctis_2002.json",
        self::EDITIO_TYPICA_TERTIA_EMENDATA_2008    => "data/propriumdesanctis_2008/propriumdesanctis_2008.json",
        self::USA_EDITION_2011                      => "data/propriumdesanctis_USA_2011/propriumdesanctis_USA_2011.json",
        self::ITALY_EDITION_1983                    => "data/propriumdesanctis_ITALY_1983/propriumdesanctis_ITALY_1983.json",
        self::ITALY_EDITION_2020                    => false
    ];

    public static array $i18nPath = [
        self::EDITIO_TYPICA_1970                    => "data/propriumdesanctis_1970/i18n/",
        self::REIMPRESSIO_EMENDATA_1971             => false,
        self::EDITIO_TYPICA_SECUNDA_1975            => false,
        self::EDITIO_TYPICA_TERTIA_2002             => "data/propriumdesanctis_2002/i18n/",
        self::EDITIO_TYPICA_TERTIA_EMENDATA_2008    => "data/propriumdesanctis_2008/i18n/",
        self::USA_EDITION_2011                      => false,
        self::ITALY_EDITION_1983                    => false,
        self::ITALY_EDITION_2020                    => false
    ];

    public static array $yearLimits = [
        self::EDITIO_TYPICA_1970                    => [ "sinceYear" => 1970 ],
        self::REIMPRESSIO_EMENDATA_1971             => [ "sinceYear" => 1971 ],
        self::EDITIO_TYPICA_SECUNDA_1975            => [ "sinceYear" => 1975 ],
        self::EDITIO_TYPICA_TERTIA_2002             => [ "sinceYear" => 2002 ],
        self::EDITIO_TYPICA_TERTIA_EMENDATA_2008    => [ "sinceYear" => 2008 ],
        self::USA_EDITION_2011                      => [ "sinceYear" => 2011 ],
        //the festivities applied in the '83 edition were incorporated into the Latin 2002 edition,
        //therefore we no longer need to apply them after the year 2002 since the Latin edition takes precedence
        self::ITALY_EDITION_1983                    => [ "sinceYear" => 1983, "untilYear" => 2002 ],
        self::ITALY_EDITION_2020                    => [ "sinceYear" => 2020 ]
    ];


    public static function isValid( $value ) : bool {
        return in_array( $value, self::$values );
    }

    public static function isLatinMissal( $value ) : bool {
        return in_array( $value, self::$values ) && strpos( $value, "VATICAN_" );
    }

    public static function getName( $value ) : string {
        return self::$names[ $value ];
    }

    public static function getSanctoraleFileName( $value ) : string|false {
        return self::$jsonFiles[ $value ];
    }

    public static function getSanctoraleI18nFilePath( $value ) : string|false {
        return self::$i18nPath[ $value ];
    }

    public static function getYearLimits( $value ) : object {
        return (object) self::$yearLimits[ $value ];
    }

    public static function produceMetadata() : array {
        $reflectionClass = new ReflectionClass(static::class);
        $metadata = $reflectionClass->getConstants();
        array_walk($metadata, function(string &$v){ $v = [ "value" => $v, "name" => self::getName( $v ), "sanctoraleFileName" => self::getSanctoraleFileName( $v ), "yearLimits" => self::$yearLimits[ $v ] ]; });
        return $metadata;
    }

}
