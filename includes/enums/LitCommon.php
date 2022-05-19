<?php

include_once( "includes/pgettext.php" );
include_once( "includes/enums/LitLocale.php" );

class LitCommon {

    const PROPRIO                   = "Proper";
    const DEDICATIONIS_ECCLESIAE    = "Dedication of a Church";
    const BEATAE_MARIAE_VIRGINIS    = "Blessed Virgin Mary";
    const MARTYRUM                  = "Martyrs";
    const PASTORUM                  = "Pastors";
    const DOCTORUM                  = "Doctors";
    const VIRGINUM                  = "Virgins";
    const SANCTORUM_ET_SANCTARUM    = "Holy Men and Women";

    /** MARTYRUM */
    const PRO_UNO_MARTYRE                       = "For One Martyr";
    const PRO_PLURIBUS_MARTYRIBUS               = "For Several Martyrs";
    const PRO_MISSIONARIIS_MARTYRIBUS           = "For Missionary Martyrs";
    const PRO_UNO_MISSIONARIO_MARTYRE           = "For One Missionary Martyr";
    const PRO_PLURIBUS_MISSIONARIIS_MARTYRIBUS  = "For Several Missionary Martyrs";
    const PRO_VIRGINE_MARTYRE                   = "For a Virgin Martyr";
    const PRO_SANCTA_MULIERE_MARTYRE            = "For a Holy Woman Martyr";

    /** PASTORUM */
    const PRO_PAPA                              = "For a Pope";
    const PRO_EPISCOPO                          = "For a Bishop";
    const PRO_UNO_PASTORE                       = "For One Pastor";
    const PRO_PLURIBUS_PASTORIBUS               = "For Several Pastors";
    const PRO_FUNDATORIBUS_ECCLESIARUM          = "For Founders of a Church";
    const PRO_UNO_FUNDATORE                     = "For One Founder";
    const PRO_PLURIBUS_FUNDATORIBUS             = "For Several Founders";
    const PRO_MISSIONARIIS                      = "For Missionaries";

    /** VIRGINUM */
    const PRO_UNA_VIRGINE                       = "For One Virgin";
    const PRO_PLURIBUS_VIRGINIBUS               = "For Several Virgins";

    /** SANCTORUM_ET_SANCTARUM */
    const PRO_PLURIBUS_SANCTIS                  = "For Several Saints";
    const PRO_UNO_SANCTO                        = "For One Saint";
    const PRO_ABBATE                            = "For an Abbot";
    const PRO_MONACHO                           = "For a Monk";
    const PRO_MONIALI                           = "For a Nun";
    const PRO_RELIGIOSIS                        = "For Religious";
    const PRO_IIS_QUI_OPERA_MISERICORDIAE_EXERCUERUNT = "For Those Who Practiced Works of Mercy";
    const PRO_EDUCATORIBUS                      = "For Educators";
    const PRO_SANCTIS_MULIERIBUS                = "For Holy Women";

    private string $locale;
    private array $GTXT;

    public function __construct( string $locale ) {
        $this->locale = strtoupper( $locale );
        $this->GTXT = [
            self::PROPRIO                           => _( "Proper" ),
            /**translators: context = from the Common of nn */
            self::DEDICATIONIS_ECCLESIAE            => _( "Dedication of a Church" ),
            /**translators: context = from the Common of nn */
            self::BEATAE_MARIAE_VIRGINIS            => _( "Blessed Virgin Mary" ),
            /**translators: context = from the Common of nn */
            self::MARTYRUM                          => _( "Martyrs" ),
            /**translators: context = from the Common of nn */
            self::PASTORUM                          => _( "Pastors" ),
            /**translators: context = from the Common of nn */
            self::DOCTORUM                          => _( "Doctors" ),
            /**translators: context = from the Common of nn */
            self::VIRGINUM                          => _( "Virgins" ),
            /**translators: context = from the Common of nn */
            self::SANCTORUM_ET_SANCTARUM            => _( "Holy Men and Women" ),
    
            /**translators: context = from the Common of nn: nn */
            self::PRO_UNO_MARTYRE                       => _( "For One Martyr" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_PLURIBUS_MARTYRIBUS               => _( "For Several Martyrs" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_MISSIONARIIS_MARTYRIBUS           => _( "For Missionary Martyrs" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_UNO_MISSIONARIO_MARTYRE           => _( "For One Missionary Martyr" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_PLURIBUS_MISSIONARIIS_MARTYRIBUS  => _( "For Several Missionary Martyrs" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_VIRGINE_MARTYRE                   => _( "For a Virgin Martyr" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_SANCTA_MULIERE_MARTYRE            => _( "For a Holy Woman Martyr" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_PAPA                              => _( "For a Pope" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_EPISCOPO                          => _( "For a Bishop" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_UNO_PASTORE                       => _( "For One Pastor" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_PLURIBUS_PASTORIBUS               => _( "For Several Pastors" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_FUNDATORIBUS_ECCLESIARUM          => _( "For Founders of a Church" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_UNO_FUNDATORE                     => _( "For One Founder" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_PLURIBUS_FUNDATORIBUS             => _( "For Several Founders" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_MISSIONARIIS                      => _( "For Missionaries" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_UNA_VIRGINE                       => _( "For One Virgin" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_PLURIBUS_VIRGINIBUS               => _( "For Several Virgins" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_PLURIBUS_SANCTIS                  => _( "For Several Saints" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_UNO_SANCTO                        => _( "For One Saint" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_ABBATE                            => _( "For an Abbot" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_MONACHO                           => _( "For a Monk" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_MONIALI                           => _( "For a Nun" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_RELIGIOSIS                        => _( "For Religious" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_IIS_QUI_OPERA_MISERICORDIAE_EXERCUERUNT => _( "For Those Who Practiced Works of Mercy" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_EDUCATORIBUS                      => _( "For Educators" ),
            /**translators: context = from the Common of nn: nn */
            self::PRO_SANCTIS_MULIERIBUS                => _( "For Holy Women" )
        ];
    }

    const LATIN = [
        self::PROPRIO                               => "Proprio",
        self::DEDICATIONIS_ECCLESIAE                => "Dedicationis ecclesiæ",
        self::BEATAE_MARIAE_VIRGINIS                => "Beatæ Mariæ Virginis",
        self::MARTYRUM                              => "Martyrum",
        self::PASTORUM                              => "Pastorum",
        self::DOCTORUM                              => "Doctorum",
        self::VIRGINUM                              => "Virginum",
        self::SANCTORUM_ET_SANCTARUM                => "Sanctorum et Sanctarum",
        self::PRO_UNO_MARTYRE                       => "Pro uno martyre",
        self::PRO_PLURIBUS_MARTYRIBUS               => "Pro pluribus martyribus",
        self::PRO_MISSIONARIIS_MARTYRIBUS           => "Pro missionariis martyribus",
        self::PRO_UNO_MISSIONARIO_MARTYRE           => "Pro uno missionario martyre",
        self::PRO_PLURIBUS_MISSIONARIIS_MARTYRIBUS  => "Pro pluribus missionariis martyribus",
        self::PRO_VIRGINE_MARTYRE                   => "Pro virgine martyre",
        self::PRO_SANCTA_MULIERE_MARTYRE            => "Pro sancta muliere martyre",
        self::PRO_PAPA                              => "Pro papa",
        self::PRO_EPISCOPO                          => "Pro episcopo",
        self::PRO_UNO_PASTORE                       => "Pro uno pastore",
        self::PRO_PLURIBUS_PASTORIBUS               => "Pro pluribus pastoribus",
        self::PRO_FUNDATORIBUS_ECCLESIARUM          => "Pro fundatoribus ecclesiarum",
        self::PRO_UNO_FUNDATORE                     => "Pro uno fundatore",
        self::PRO_PLURIBUS_FUNDATORIBUS             => "Pro pluribus fundatoribus",
        self::PRO_MISSIONARIIS                      => "Pro missionariis",
        self::PRO_UNA_VIRGINE                       => "Pro una virgine",
        self::PRO_PLURIBUS_VIRGINIBUS               => "Pro pluribus virginibus",
        self::PRO_PLURIBUS_SANCTIS                  => "Pro pluribus sanctis",
        self::PRO_UNO_SANCTO                        => "Pro uno sancto",
        self::PRO_ABBATE                            => "Pro abbate",
        self::PRO_MONACHO                           => "Pro monacho",
        self::PRO_MONIALI                           => "Pro moniali",
        self::PRO_RELIGIOSIS                        => "Pro religiosis",
        self::PRO_IIS_QUI_OPERA_MISERICORDIAE_EXERCUERUNT => "Pro iis qui opera misericordiae exercuerunt",
        self::PRO_EDUCATORIBUS                      => "Pro educatoribus",
        self::PRO_SANCTIS_MULIERIBUS                => "Pro sanctis mulieribus"
    ];

    public static function POSSESSIVE( string $value ) : string {
        switch( $value ) {
            case "Blessed Virgin Mary":
                /**translators: (singular feminine) glue between "From the Common" and the actual common. Latin: leave empty! */
                return pgettext( "(SING_FEMM)", "of" );
            case "Virgins":
                /**translators: (plural feminine) glue between "From the Common" and the actual common. Latin: leave empty! */
                return pgettext( "(PLUR_FEMM)", "of" );
            case "Martyrs":
            case "Pastors":
            case "Doctors":
            case "Holy Men and Women":
                /**translators: (plural masculine) glue between "From the Common" and the actual common. Latin: leave empty! */
                return pgettext( "(PLUR_MASC)", "of" );
            case "Dedication of a Church":
                /**translators: (singular feminine) glue between "From the Common" and the actual common. Latin: leave empty! */
                return pgettext( "(SING_FEMM)", "of" );
            default:
                /**translators: (singular masculine) glue between "From the Common" and the actual common. Latin: leave empty! */
                return pgettext( "(SING_MASC)", "of" );
        }
    }

    public static array $values = [ 
        "Proper",
        "Dedication of a Church",
        "Blessed Virgin Mary",
        "Martyrs",
        "Pastors",
        "Doctors",
        "Virgins",
        "Holy Men and Women",
        "For One Martyr",
        "For Several Martyrs",
        "For Missionary Martyrs",
        "For One Missionary Martyr",
        "For Several Missionary Martyrs",
        "For a Virgin Martyr",
        "For a Holy Woman Martyr",
        "For a Pope",
        "For a Bishop",
        "For One Pastor",
        "For Several Pastors",
        "For Founders of a Church",
        "For One Founder",
        "For Several Founders",
        "For Missionaries",
        "For One Virgin",
        "For Several Virgins",
        "For Several Saints",
        "For One Saint",
        "For an Abbot",
        "For a Monk",
        "For a Nun",
        "For Religious",
        "For Those Who Practiced Works of Mercy",
        "For Educators",
        "For Holy Women"
    ];

    public static array $MARTYRUM = [
        self::PRO_UNO_MARTYRE,
        self::PRO_PLURIBUS_MARTYRIBUS,
        self::PRO_MISSIONARIIS_MARTYRIBUS,
        self::PRO_UNO_MISSIONARIO_MARTYRE,
        self::PRO_PLURIBUS_MISSIONARIIS_MARTYRIBUS,
        self::PRO_VIRGINE_MARTYRE,
        self::PRO_SANCTA_MULIERE_MARTYRE
    ];

    public static array $PASTORUM = [
        self::PRO_PAPA,
        self::PRO_EPISCOPO,
        self::PRO_UNO_PASTORE,
        self::PRO_PLURIBUS_PASTORIBUS,
        self::PRO_FUNDATORIBUS_ECCLESIARUM,
        self::PRO_UNO_FUNDATORE,
        self::PRO_PLURIBUS_FUNDATORIBUS,
        self::PRO_MISSIONARIIS
    ];

    public static array $VIRGINUM = [
        self::PRO_UNA_VIRGINE,
        self::PRO_PLURIBUS_VIRGINIBUS
    ];

    public static array $SANCTORUM = [
        self::PRO_PLURIBUS_SANCTIS,
        self::PRO_UNO_SANCTO,
        self::PRO_ABBATE,
        self::PRO_MONACHO,
        self::PRO_MONIALI,
        self::PRO_RELIGIOSIS,
        self::PRO_IIS_QUI_OPERA_MISERICORDIAE_EXERCUERUNT,
        self::PRO_EDUCATORIBUS,
        self::PRO_SANCTIS_MULIERIBUS
    ];

    public static function isValid( string $value ) {
        if( strpos($value, ',') || strpos($value, ':') ) {
            $values = preg_split('/[,:]/', $value);
            return self::areValid( $values );
        }
        return in_array( $value, self::$values );
    }

    public static function areValid( array $values ) {
        return empty( array_diff( $values, self::$values ) );
    }

    /*
    public static function AB( string|array $value ) : string {
        if( is_array( $value ) ) {
            $mapped = array_map('self::AB', $value);
            return implode( ',', $mapped );
        } else {
            if( in_array($value, self::$MARTYRUM) ) {
                return self::MARTYRUM . ':' . $value;
            }
            if( in_array($value, self::$PASTORUM) ) {
                return self::PASTORUM . ':' . $value;
            }
            if( in_array($value, self::$VIRGINUM) ) {
                return self::VIRGINUM . ':' . $value;
            }
            if( in_array($value, self::$SANCTORUM) ) {
                return self::SANCTORUM_ET_SANCTARUM . ':' . $value;
            }
        }
    }
    */

    public function i18n( string|array $value ) : string|array {
        if( is_array( $value ) && self::areValid( $value ) ) {
            return array_map( [$this, 'i18n'], $value );
        }
        else if( self::isValid( $value ) ) {
            if( $this->locale === LitLocale::LATIN ) {
                return self::LATIN[ $value ];
            } else{
                return $this->GTXT[ $value ];
            }
        }
        return $value;
    }

    public function getPossessive( string|array $value ) : string|array {
        if( is_array( $value ) ) {
            return array_map( [$this, 'getPossessive'], $value );
        }
        return $this->locale === LitLocale::LATIN ? "" : self::POSSESSIVE( $value );
    }

    /**
     * Function C
     * Returns a translated human readable string of the Common or the Proper
     */
    public function C( string|array $common="" ) : string|array {
        if ( ( is_string( $common ) && $common !== "" ) || is_array( $common ) ) {
            if( (is_string( $common ) && $common === LitCommon::PROPRIO) || ( is_array( $common ) && in_array( LitCommon::PROPRIO, $common ) ) ) {
                $common = $this->i18n( $common );
            } else {
                if( is_string( $common ) ) {
                    $commons = explode(",", $common);
                } else {
                    $commons = $common;
                }
                $commons = array_map(function ($txt) {
                    if( strpos($txt, ":") !== false ){
                        [$commonGeneral, $commonSpecific] = explode(":", $txt);
                    } else {
                        $commonGeneral = $txt;
                        $commonSpecific = "";
                    }
                    $fromTheCommon = $this->locale === LitLocale::LATIN ? "De Commune" : _( "From the Common" );
                    return $fromTheCommon . " " . $this->getPossessive( $commonGeneral ) . " " . $this->i18n( $commonGeneral ) . ($commonSpecific != "" ? ": " . $this->i18n( $commonSpecific ) : "");
                }, $commons);
                /**translators: when there are multiple possible commons, this will be the glue "or from the common of..." */
                $common = implode( "; " . _( "or" ) . " ", $commons );
            }
        }
        return $common;
    }

}
