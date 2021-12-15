<?php

include_once( 'includes/enums/LitGrade.php' );

class LITCAL_MESSAGES {

    const MESSAGES = [
       /* The following strings would usually be used by a user-facing application, 
         *  however I decided to add them here seeing they are just as useful for generating
         *  the ICS calendar output, which is pretty final as it is, 
         *  there are no client applications that take care of localization...
         */
        "YEAR" => [
            "en" => "YEAR",
            "it" => "ANNO",
            "la" => "ANNUM"
        ],
        "green" => [
            "en" => "green",
            "it" => "verde",
            "la" => "viridis"
        ],
        "purple" => [
            "en" => "purple",
            "it" => "viola",
            "la" => "purpura"
        ],
        "white" => [
            "en" => "white",
            "it" => "bianco",
            "la" => "albus"
        ],
        "red" => [
            "en" => "red",
            "it" => "rosso",
            "la" => "ruber"
        ],
        "pink" => [
            "en" => "pink",
            "it" => "rosa",
            "la" => "rosea"
        ],
        "Month" => [
            "en" => "Month",
            "it" => "Mese",
            "la" => "Mensis"
        ],
        "Vigil Mass" => [
            "en" => "Vigil Mass",
            "it" => "Messa nella Vigilia",
            "la" => "Missa Vigiliæ"
        ]
    ];
    
    const LATIN_ORDINAL = [
        "",
        "primus",
        "secundus",
        "tertius",
        "quartus",
        "quintus",
        "sextus",
        "septimus",
        "octavus",
        "nonus",
        "decimus",
        "undecimus",
        "duodecimus",
        "decimus tertius",
        "decimus quartus",
        "decimus quintus",
        "decimus sextus",
        "decimus septimus",
        "duodevicesimus",
        "undevicesimus",
        "vigesimus",
        "vigesimus primus",
        "vigesimus secundus",
        "vigesimus tertius",
        "vigesimus quartus",
        "vigesimus quintus",
        "vigesimus sextus",
        "vigesimus septimus",
        "vigesimus octavus",
        "vigesimus nonus",
        "trigesimus",
        "trigesimus primus",
        "trigesimus secundus",
        "trigesimus tertius",
        "trigesimus quartus",
    ];
    
    const LATIN_ORDINAL_FEM_GEN = [
        "",
        "primæ",
        "secundæ",
        "tertiæ",
        "quartæ",
        "quintæ",
        "sextæ",
        "septimæ",
        "octavæ",
        "nonæ",
        "decimæ",
        "undecimæ",
        "duodecimæ",
        "decimæ tertiæ",
        "decimæ quartæ",
        "decimæ quintæ",
        "decimæ sextæ",
        "decimæ septimæ",
        "duodevicesimæ",
        "undevicesimæ",
        "vigesimæ",
        "vigesimæ primæ",
        "vigesimæ secundæ",
        "vigesimæ tertiæ",
        "vigesimæ quartæ",
        "vigesimæ quintæ",
        "vigesimæ sextæ",
        "vigesimæ septimæ",
        "vigesimæ octavæ",
        "vigesimæ nonæ",
        "trigesimæ",
        "trigesimæ primæ",
        "trigesimæ secundæ",
        "trigesimæ tertiæ",
        "trigesimæ quartæ",
    ];
    
    const LATIN_DAYOFTHEWEEK = [
        "Feria I",     //0=Sunday
        "Feria II",    //1=Monday
        "Feria III",   //2=Tuesday
        "Feria IV",    //3=Wednesday
        "Feria V",     //4=Thursday
        "Feria VI",    //5=Friday
        "Feria VII"    //6=Saturday
    ];
    
    const LATIN_MONTHS = [
        "",
        "Ianuarius",
        "Februarius",
        "Martius",
        "Aprilis",
        "Maius",
        "Iunius",
        "Iulius",
        "Augustus",
        "September",
        "October",
        "November",
        "December"
    ];
    
    public static function __( string $key, string $locale="la" ) : string {
        $locale = strtolower($locale);
        if( isset( self::MESSAGES[$key] ) ) {
            if( isset( self::MESSAGES[$key][$locale] ) ) {
                return self::MESSAGES[$key][$locale];
            }
            else{
                return $key;
            }
        }
        return $key;
    }

    public static function _CG( string $commonGeneral ) : string {
        switch( $commonGeneral ){
            case 'Martyrs':
                return _( 'Martyrs' );
            case 'Pastors':
                return _( 'Pastors' );
            case 'Doctors':
                return _( 'Doctors' );
            case 'Virgins':
                return _( 'Virgins' );
            case 'Holy Men and Women':
                return _( 'Holy Men and Women' );
            case 'Blessed Virgin Mary':
                return _( 'Blessed Virgin Mary' );
            case 'Dedication of a Church':
                return _( 'Dedication of a Church' );
        }
    }

    public static function _CS( string $commonSpecific ) : string {
        switch( $commonSpecific ) {
            case "For One Martyr":
                return _( "For One Martyr" );
            case "For Several Martyrs":
                return _( "For Several Martyrs" );
            case "For Missionary Martyrs":
                return _( "For Missionary Martyrs" );
            case "For One Missionary Martyr":
                return _( "For One Missionary Martyr" );
            case "For Several Missionary Martyrs":
                return _( "For Several Missionary Martyrs" );
            case "For a Virgin Martyr":
                return _( "For a Virgin Martyr" );
            case "For a Holy Woman Martyr":
                return _( "For a Holy Woman Martyr" );
            case "For a Pope":
                return _( "For a Pope" );
            case "For a Bishop":
                return _( "For a Bishop" );
            case "For One Pastor":
                return _( "For One Pastor" );
            case "For Several Pastors":
                return _( "For Several Pastors" );
            case "For Founders of a Church":
                return _( "For Founders of a Church" );
            case "For One Founder":
                return _( "For One Founder" );
            case "For Several Founders":
                return _( "For Several Founders" );
            case "For Missionaries":
                return _( "For Missionaries" );
            case "For One Virgin":
                return _( "For One Virgin" );
            case "For Several Virgins":
                return _( "For Several Virgins" );
            case "For Religious":
                return _( "For Religious" );
            case "For Those Who Practiced Works of Mercy":
                return _( "For Those Who Practiced Works of Mercy" );
            case "For an Abbot":
                return _( "For an Abbot" );
            case "For a Monk":
                return _( "For a Monk" );
            case "For a Nun":
                return _( "For a Nun" );
            case "For Educators":
                return _( "For Educators" );
            case "For Holy Women":
                return _( "For Holy Women" );
            case "For One Saint":
                return _( "For One Saint" );
            case "For Several Saints":
                return _( "For Several Saints" );
        }
    }

    /**
     * Function _C
     * Gets a translated human readable string with the Common or the Proper
     */
    public static function _C( string $common, string $locale="la" ) : string {
        $locale = strtolower($locale);
        if ($common !== "" && $common !== "Proper") {
            $commons = explode(",", $common);
            $commons = array_map(function ($txt) {
                if( strpos($txt, ":") !== false ){
                    [$commonGeneral, $commonSpecific] = explode(":", $txt);
                } else {
                    $commonGeneral = $txt;
                    $commonSpecific = "";
                }
                switch ($commonGeneral) {
                    case "Blessed Virgin Mary":
                        /**translators: (singular feminine) glue between "From the Common" and the actual common. Latin: leave empty! */
                        $commonKey = _( "(SING_FEMM)" . "\004" . "of" );
                        break;
                    case "Virgins":
                        /**translators: (plural feminine) glue between "From the Common" and the actual common. Latin: leave empty! */
                        $commonKey = _( "(PLUR_FEMM)" . "\004" . "of" );
                        break;
                    case "Martyrs":
                    case "Pastors":
                    case "Doctors":
                    case "Holy Men and Women":
                        /**translators: (plural masculine) glue between "From the Common" and the actual common. Latin: leave empty! */
                        $commonKey = _( "(PLUR_MASC)" . "\004" . "of" );
                        break;
                    case "Dedication of a Church":
                        /**translators: (singular feminine) glue between "From the Common" and the actual common. Latin: leave empty! */
                        $commonKey = _( "(SING_FEMM)" . "\004" . "of" );
                        break;
                    default:
                        /**translators: (singular masculine) glue between "From the Common" and the actual common. Latin: leave empty! */
                        $commonKey = _( "(SING_MASC)" . "\004" . "of" );
                }
                return _( "From the Common" ) . " " . $commonKey . " " . self::_CG( $commonGeneral ) . ($commonSpecific != "" ? ": " . self::_CS( $commonSpecific ) : "");
            }, $commons);
            /**translators: when there are multiple possible commons, this will be the glue "or from the common of..." */
            $common = implode( "; " . _( "or" ) . " ", $commons );
        } else if ($common == "Proper") {
            /**translators: context = the Proper as opposed to the Common */
            $common = _( "Proper" );
        }
        return $common;
    }

    public static function ColorToHex( string $color ) : string {
        $hex = "#";
        switch($color){
            case "red":
                $hex .= "FF0000";
            break;
            case "green":
                $hex .= "00AA00";
            break;
            case "white":
                $hex .= "AAAAAA";
            break;
            case "purple":
                $hex .= "AA00AA";
            break;
            case "pink":
                $hex .= "FFAAAA";
            break;
            default:
                $hex .= "000000";
        }
        return $hex;
    }

    public static function ParseColorString( string $string, string $LOCALE, bool $html=false) : string {
        if( $html === true ) {
            if( strpos( $string, "," ) ) {
                $colors = explode( ",", $string );
                $colors = array_map( function($txt) use ($LOCALE) {
                    return '<B><I><SPAN LANG=' . strtolower($LOCALE) . '><FONT FACE="Calibri" COLOR="' . self::ColorToHex( $txt ) . '">' . LitColor::i18n( $txt ) . '</FONT></SPAN></I></B>';
                }, $colors );
                return implode( ' <I><FONT FACE="Calibri">' . self::__( "or", $LOCALE ) . "</FONT></I> ", $colors );
            }
            else{
                return '<B><I><SPAN LANG=' . strtolower($LOCALE) . '><FONT FACE="Calibri" COLOR="' . self::ColorToHex( $string ) . '">' . LitColor::i18n( $string ) . '</FONT></SPAN></I></B>';
            }
        } else{
            if( strpos( $string, "," ) ) {
                $colors = explode( ",", $string );
                $colors = array_map( function($txt) {
                    return LitColor::i18n( $txt );
                }, $colors );
                return implode( " " . _( "or" ) . " ", $colors );
            }
            else{
                return LitColor::i18n( $string );
            }
        }
        return $string; //should never get here
    }

    /**
     * Ordinal Suffix function
     * Useful for choosing the correct suffix for ordinal numbers
     * in the English language
     * @Author: John Romano D'Orazio
     */
    public static function ordSuffix(int $ord) : string {
        $ord_suffix = ''; //st, nd, rd, th
        if ($ord === 1 || ($ord % 10 === 1  && $ord <> 11)) {
        $ord_suffix = 'st';
        } else if ($ord === 2 || ($ord % 10 === 2  && $ord <> 12)) {
        $ord_suffix = 'nd';
        } else if ($ord === 3 || ($ord % 10 === 3  && $ord <> 13)) {
        $ord_suffix = 'rd';
        } else {
        $ord_suffix = 'th';
        }
        return $ord_suffix;
    }

    /*public static function ordinal( int $number ) : string {
        $ends = array('th','st','nd','rd','th','th','th','th','th','th');
        if ((($number % 100) >= 11) && (($number%100) <= 13))
            return $number. 'th';
        else
            return $number. $ends[$number % 10];
    }*/


    /**
     * @param int $num
     * @param string $LOCALE
     * @param NumberFormatter $formatter
     * @param string[] $latinOrdinals
     */
    public static function getOrdinal(int $num, string $LOCALE, NumberFormatter $formatter, array $latinOrdinals) : string {
        $ordinal = "";
        switch($LOCALE){
            case 'LA':
                $ordinal = $latinOrdinals[$num];
            break;
            case 'EN':
                $ordinal = $num . self::ordSuffix($num);
            break;
            default:
                $ordinal = $formatter->format($num);
        }
        return $ordinal;
    }

}
