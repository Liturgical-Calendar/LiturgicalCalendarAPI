<?php

class ROMANMISSAL {

    const EDITIO_TYPICA_1970                    = "VATICAN_1970";
    const REIMPRESSIO_EMENDATA_1971             = "VATICAN_1971";
    const EDITIO_TYPICA_SECUNDA_1975            = "VATICAN_1975";
    const EDITIO_TYPICA_TERTIA_2002             = "VATICAN_2002";
    const EDITIO_TYPICA_TERTIA_EMENDATA_2008    = "VATICAN_2008";

    const USA_EDITION_2011                      = "USA_2011";
    const ITALY_EDITION_1983                    = "ITALY_1983";
    const ITALY_EDITION_2020                    = "ITALY_2020";

    public static array $values = [ 
        "LITURGY__calendar_propriumdesanctis"               => "VATICAN_1970",
        "LITURGY__calendar_propriumdesanctis_1971"          => "VATICAN_1971",
        "LITURGY__calendar_propriumdesanctis_1975"          => "VATICAN_1975",
        "LITURGY__calendar_propriumdesanctis_2002"          => "VATICAN_2002",
        "LITURGY__calendar_propriumdesanctis_2008"          => "VATICAN_2008",
        "LITURGY__USA_calendar_propriumdesanctis_2011"      => "USA_2011",
        "LITURGY__ITALY_calendar_propriumdesanctis_1983"    => "ITALY_1983",
        "LITURGY__ITALY_calendar_propriumdesanctis_2020"    => "ITALY_2020"
    ];

    public static array $names = [
        "VATICAN_1970"  => "Editio Typica 1970",
        "VATICAN_1971"  => "Reimpressio Emendata 1971",
        "VATICAN_1975"  => "Editio Typica Secunda 1975",
        "VATICAN_2002"  => "Editio Typica Tertia 2002",
        "VATICAN_2008"  => "Editio Typica Tertia Emendata 2008",
        "USA_2011"      => "Roman Missal 2011 Edition",
        "ITALY_1983"    => "Messale Romano ed. 1983",
        "ITALY_2020"    => "Messale Romano ed. 2020"
    ];

    public static function isValid( $value ) : bool {
        return in_array( $value, self::$values );
    }

    public static function isLatinMissal( $value ) : bool {
        return in_array( $value, self::$values ) && strpos( $value, "VATICAN_" );
    }

    public static function getSanctoraleTableName( $value ) : string|int|false {
        return array_search( $value, self::$values );
    }

    public static function getName( $value ) : string {
        return self::$names[ $value ];
    }
}
