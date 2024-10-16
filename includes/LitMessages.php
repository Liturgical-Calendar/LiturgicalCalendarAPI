<?php

include_once( 'includes/enums/LitColor.php' );
include_once( 'includes/enums/LitLocale.php' );

class LitMessages {

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

    public static function ParseColorString( string|array $colors, string $LOCALE, bool $html=false) : string {
        if( is_string( $colors ) ) {
            $colors = explode( ",", $colors );
        }
        if( $html === true ) {
            $colors = array_map( function($txt) use ($LOCALE) {
                return '<B><I><SPAN LANG=' . strtolower($LOCALE) . '><FONT FACE="Calibri" COLOR="' . self::ColorToHex( $txt ) . '">' . LitColor::i18n( $txt, $LOCALE ) . '</FONT></SPAN></I></B>';
            }, $colors );
            return implode( ' <I><FONT FACE="Calibri">' . _( "or" ) . "</FONT></I> ", $colors );
        } else{
            $colors = array_map( function($txt) use($LOCALE) {
                return LitColor::i18n( $txt, $LOCALE );
            }, $colors );
            return implode( " " . _( "or" ) . " ", $colors );
        }
        return ""; //should never get here
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
        $baseLocale = $LOCALE !== "LA" && $LOCALE !== "la" ? Locale::getPrimaryLanguage($LOCALE) : "LA";
        switch(strtoupper($baseLocale)) {
            case LitLocale::LATIN:
                $ordinal = $latinOrdinals[$num];
            break;
            case "EN":
                $ordinal = $num . self::ordSuffix($num);
            break;
            default:
                $ordinal = $formatter->format($num);
        }
        return $ordinal;
    }

}
