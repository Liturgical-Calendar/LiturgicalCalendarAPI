<?php

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
        "From the Common" => [
            "en" => "From the Common",
            "it" => "Dal Comune",
            "la" => "De Communi"
        ],
        "of (SING_MASC)" => [
            "en" => "of",
            "it" => "del",
            "la" => ""
        ],
        "of (SING_FEMM)" => [
            "en" => "of the",
            "it" => "della",
            "la" => "" //latin expresses the genitive in the declination of the noun, no need for a preposition, leave empty
        ],
        "of (PLUR_MASC)" => [
            "en" => "of",
            "it" => "dei",
            "la" => "" //latin expresses the genitive in the declination of the noun, no need for a preposition, leave empty
        ],
        "of (PLUR_MASC_ALT)" => [
            "en" => "of",
            "it" => "degli",
            "la" => "" //latin expresses the genitive in the declination of the noun, no need for a preposition, leave empty
        ],
        "of (PLUR_FEMM)" => [
            "en" => "of",
            "it" => "delle",
            "la" => "" //latin expresses the genitive in the declination of the noun, no need for a preposition, leave empty
        ],
        /*translators: in reference to the Common of the Blessed Virgin Mary */
        "Blessed Virgin Mary" => [
            "en" => "Blessed Virgin Mary",
            "it" => "Beata Vergine Maria",
            "la" => "Beatæ Virginis Mariæ"
        ],
        /*translators: all of the following are in the genitive case, in reference to "from the Common of %s" */
        "Martyrs" => [
            "en" => "Martyrs",
            "it" => "Martiri",
            "la" => "Martyrum"
        ],
        "Pastors" => [
            "en" => "Pastors",
            "it" => "Pastori",
            "la" => "Pastorum"
        ],
        "Doctors" => [
            "en" => "Doctors",
            "it" => "Dottori della Chiesa",
            "la" => "Doctorum Ecclesiæ"
        ],
        "Virgins" => [
            "en" => "Virgins",
            "it" => "Vergini",
            "la" => "Virginum"
        ],
        "Holy Men and Women" => [
            "en" => "Holy Men and Women",
            "it" => "Santi e delle Sante",
            "la" => "Sanctorum et Sanctarum"
        ],
        "For One Martyr" => [
            "en" => "For One Martyr",
            "it" => "Per un martire",
            "la" => "Pro uno martyre"
        ],
        "For Several Martyrs" => [
            "en" => "For Several Martyrs",
            "it" => "Per più martiri",
            "la" => "Pro pluribus martyribus"
        ],
        "For Missionary Martyrs" => [
            "en" => "For Missionary Martyrs",
            "it" => "Per i martiri missionari",
            "la" => "Pro missionariis martyribus"
        ],
        "For One Missionary Martyr" => [
            "en" => "For One Missionary Martyr",
            "it" => "Per un martire missionario",
            "la" => "Pro uno missionario martyre"
        ],
        "For Several Missionary Martyrs" => [
            "en" => "For Several Missionary Martyrs",
            "it" => "Per più martiri missionari",
            "la" => "Pro pluribus missionariis martyribus"
        ],
        "For a Virgin Martyr" => [
            "en" => "For a Virgin Martyr",
            "it" => "Per una vergine martire",
            "la" => "Pro virgine martyre"
        ],
        "For a Holy Woman Martyr" => [
            "en" => "For a Holy Woman Martyr",
            "it" => "Per una santa martire",
            "la" => "Pro una martyre muliere",
        ],
        "For a Pope" => [
            "en" => "For a Pope",
            "it" => "Per i papi",
            "la" => "Pro Papa"
        ],
        "For a Bishop" => [
            "en" => "For a Bishop",
            "it" => "Per i vescovi",
            "la" => "Pro Episcopis"
        ],
        "For One Pastor" => [
            "en" => "For One Pastor",
            "it" => "Per un pastore",
            "la" => "Pro Pastoribus"
        ],
        "For Several Pastors" => [
            "en" => "For Several Pastors",
            "it" => "Per i pastori",
            "la" => "Pro Pastoribus"
        ],
        "For Founders of a Church" => [
            "en" => "For Founders of a Church",
            "it" => "Per i fondatori delle chiese",
            "la" => "Pro Fundatoribus ecclesiarum"
        ],
        "For One Founder" => [
            "en" => "For One Founder",
            "it" => "Per un fondatore",
            "la" => "Pro Uno Fundatore"
        ],
        "For Several Founders" => [
            "en" => "For Several Founders",
            "it" => "Per più fondatori",
            "la" => "Pro Pluribus Fundatoribus"
        ],
        "For Missionaries" => [
            "en" => "For Missionaries",
            "it" => "Per i missionari",
            "la" => "Pro missionariis"
        ],
        "For One Virgin" => [
            "en" => "For One Virgin",
            "it" => "Per una vergine",
            "la" => "Pro una virgine"
        ],
        "For Several Virgins" => [
            "en" => "For Several Virgins",
            "it" => "Per più vergini",
            "la" => "Pro pluribus virginibus"
        ],
        "For Religious" => [
            "en" => "For Religious",
            "it" => "Per i religiosi",
            "la" => "Pro Religiosis"
        ],
        "For Those Who Practiced Works of Mercy" => [
            "en" => "For Those Who Practiced Works of Mercy",
            "it" => "Per gli operatori di misericordia",
            "la" => "Pro iis qui opera Misericordiæ Exercuerunt"
        ],
        "For an Abbot" => [
            "en" => "For an Abbot",
            "it" => "Per un abate",
            "la" => "Pro abbate"
        ],
        "For a Monk" => [
            "en" => "For a Monk",
            "it" => "Per un monaco",
            "la" => "Pro monacho"
        ],
        "For a Nun" => [
            "en" => "For a Nun",
            "it" => "Per i religiosi",
            "la" => "Pro moniali"
        ],
        "For Educators" => [
            "en" => "For Educators",
            "it" => "Per gli educatori",
            "la" => "Pro Educatoribus"
        ],
        "For Holy Women" => [
            "en" => "For Holy Women",
            "it" => "Per le sante",
            "la" => "Pro Sanctis Mulieribus"
        ],
        "For One Saint" => [
            "en" => "For One Saint",
            "it" => "Per un Santo",
            "la" => "Pro uno Sancto"
        ],
        "For Several Saints" => [
            "en" => "For Several Saints",
            "it" => "Per più Santi",
            "la" => "Pro pluribus Sanctos"
        ],
        "or" => [
            "en" => "or",
            "it" => "oppure",
            "la" => "vel"
        ],
        "Proper" => [
            "en" => "Proper",
            "it" => "Proprio",
            "la" => "Proprium"
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
        "FERIA" => [
            "en" => "<I>weekday</I>",
            "it" => "<I>feria</I>",
            "la" => "<I>feria</I>"
        ],
        "COMMEMORATION" => [
            "en" => "<I>Commemoration</I>",
            "it" => "<I>Commemorazione</I>",
            "la" => "<I>Commemoratio</I>"
        ],
        "OPTIONAL MEMORIAL" => [
            "en" => "Optional memorial",
            "it" => "Memoria facoltativa",
            "la" => "Memoria ad libitum"
        ],
        "MEMORIAL" => [
            "en" => "Memorial",
            "it" => "Memoria",
            "la" => "Memoria"
        ],
        "FEAST" => [
            "en" => "FEAST",
            "it" => "FESTA",
            "la" => "FESTUM"
        ],
        "FEAST OF THE LORD" => [
            "en" => "<B>FEAST OF THE LORD</B>",
            "it" => "<B>FESTA DEL SIGNORE</B>",
            "la" => "<B>FESTUM DOMINI</B>"
        ],
        "SOLEMNITY" => [
            "en" => "<B>SOLEMNITY</B>",
            "it" => "<B>SOLENNITÀ</B>",
            "la" => "<B>SOLLEMNITAS</B>"
        ],
        "HIGHER RANKING SOLEMNITY" => [
            "en" => "<B><I>celebration with precedence over solemnities</I></B>",
            "it" => "<B><I>celebrazione con precedenza sulle solennità</I></B>",
            "la" => "<B><I>celebratione cum præcellentiam super sollemnitates</I></B>"
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
    
    public static function __( string $key, string $locale="LA" ) : string {
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

    /**
     * Function _G
     * Returns a translated string with the Grade (Rank) of the Festivity
     */
    public static function _G( int $key, string $locale="LA", bool $html=true ) : string {
        $locale = strtolower( $locale );
        $grade = self::__( "FERIA", $locale );
        switch($key){
            case 0: 
                $grade = self::__( "FERIA", $locale );
            break;
            case 1: 
                $grade = self::__( "COMMEMORATION", $locale );
            break;
            case 2: 
                $grade = self::__( "OPTIONAL MEMORIAL", $locale );
            break;
            case 3: 
                $grade = self::__( "MEMORIAL", $locale );
            break;
            case 4: 
                $grade = self::__( "FEAST", $locale );
            break;
            case 5: 
                $grade = self::__( "FEAST OF THE LORD", $locale );
            break;
            case 6: 
                $grade = self::__( "SOLEMNITY", $locale );
            break;
            case 7: 
                $grade = self::__( "HIGHER RANKING SOLEMNITY", $locale );
            break;
        }
        return $html === true ? $grade : strip_tags($grade);
    }

    /**
     * Function _C
     * Gets a translated human readable string with the Common or the Proper
     */
    public static function _C( string $common, string $locale="la" ) : string {
        $locale = strtolower($locale);
        if ($common !== "" && $common !== "Proper") {
            $commons = explode(",", $common);
            $commons = array_map(function ($txt) use ($locale) {
                $commonArr = explode(":", $txt);
                $commonGeneral = self::__( $commonArr[0], $locale );
                $commonSpecific = isset($commonArr[1]) && $commonArr[1] != "" ? self::__( $commonArr[1], $locale ) : "";
                //$txt = str_replace(":", ": ", $txt);
                switch ($commonGeneral) {
                    case self::__( "Blessed Virgin Mary", $locale ):
                        $commonKey = "of (SING_FEMM)";
                        break;
                    case self::__( "Virgins", $locale ):
                        $commonKey = "of (PLUR_FEMM)";
                        break;
                    case self::__( "Martyrs", $locale ):
                    case self::__( "Pastors", $locale ):
                    case self::__( "Doctors", $locale ):
                    case self::__( "Holy Men and Women", $locale ):
                        $commonKey = "of (PLUR_MASC)";
                        break;
                    case self::__( "Dedication of a Church", $locale ):
                        $commonKey = "of (SING_FEMM)";
                        break;
                    default:
                        $commonKey = "of (SING_MASC)";
                }
                return self::__( "From the Common", $locale ) . " " . self::__( $commonKey, $locale ) . " " . $commonGeneral . ($commonSpecific != "" ? ": " . $commonSpecific : "");
            }, $commons);
            $common = implode( "; " . self::__( "or", $locale) . " ", $commons );
        } else if ($common == "Proper") {
            $common = self::__( "Proper", $locale );
        }
        return $common;
    }

    public function ColorToHex( string $color ) : string {
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

    public function ParseColorString( string $string, string $LOCALE, bool $html=false) : string {
        if($html === true) {
            if( strpos( $string, "," ) ) {
                $colors = explode( ",", $string );
                $colors = array_map( function($txt) use ($LOCALE) {
                    return '<B><I><SPAN LANG=' . strtolower($LOCALE) . '><FONT FACE="Calibri" COLOR="' . self::ColorToHex( $txt ) . '">' . self::__( $txt, $LOCALE ) . '</FONT></SPAN></I></B>';
                }, $colors );
                return implode( ' <I><FONT FACE="Calibri">' . self::__( "or", $LOCALE ) . "</FONT></I> ", $colors );
            }
            else{
                return '<B><I><SPAN LANG=' . strtolower($LOCALE) . '><FONT FACE="Calibri" COLOR="' . self::ColorToHex( $string ) . '">' . self::__( $string, $LOCALE ) . '</FONT></SPAN></I></B>';
            }
        } else{
            if(strpos($string,",")){
                $colors = explode(",",$string);
                $colors = array_map(function($txt) use ($LOCALE){
                    return self::__( $txt, $LOCALE );
                },$colors);
                return implode(" " . self::__( "or", $LOCALE ) . " ",$colors);
            }
            else{
                return self::__( $string, $LOCALE );
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
