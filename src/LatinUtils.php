<?php

namespace LiturgicalCalendar\Api;

use LiturgicalCalendar\Api\Enum\LitColor;

class LatinUtils
{
    public const LATIN_ORDINAL = [
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

    public const LATIN_ORDINAL_FEM_GEN = [
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

    public const LATIN_DAYOFTHEWEEK = [
        "Dominica",    //0=Sunday
        "Feria II",    //1=Monday
        "Feria III",   //2=Tuesday
        "Feria IV",    //3=Wednesday
        "Feria V",     //4=Thursday
        "Feria VI",    //5=Friday
        "Sabbato"      //6=Saturday
    ];

    public const LATIN_MONTHS = [
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
}
