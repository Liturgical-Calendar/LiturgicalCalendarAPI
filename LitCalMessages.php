<?php

function __($key,$locale="la"){
    global $MESSAGES;
    $locale = strtolower($locale);
    if(isset($MESSAGES[$key])){
        if(isset($MESSAGES[$key][$locale])){
            return $MESSAGES[$key][$locale];
        }
        else{
            return $key;
        }
    }
    else return $key;
}

/**
 * Function _G
 * Returns a translated string with the Grade (Rank) of the Festivity
 */
function _G($key,$locale="la",$html=true){
    $locale = strtolower($locale);
    $key = (int)$key;
    $grade = __("FERIA",$locale);
    switch($key){
        case 0: 
            $grade = __("FERIA",$locale);
        break;
        case 1: 
            $grade = __("COMMEMORATION",$locale);
        break;
        case 2: 
            $grade = __("OPTIONAL MEMORIAL",$locale);
        break;
        case 3: 
            $grade = __("MEMORIAL",$locale);
        break;
        case 4: 
            $grade = __("FEAST",$locale);
        break;
        case 5: 
            $grade = __("FEAST OF THE LORD",$locale);
        break;
        case 6: 
            $grade = __("SOLEMNITY",$locale);
        break;
        case 7: 
            $grade = __("HIGHER RANKING SOLEMNITY",$locale);
        break;
    }
    return $html === true ? $grade : strip_tags($grade);
}

/**
 * Function _C
 * Gets a translated human readable string with the Common or the Proper
 */
function _C($common,$locale="la"){
    $locale = strtolower($locale);
    if ($common !== "" && $common !== "Proper") {
        $commons = explode("|", $common);
        $commons = array_map(function ($txt) use ($locale) {
            $commonArr = explode(":", $txt);
            $commonGeneral = __($commonArr[0], $locale);
            $commonSpecific = isset($commonArr[1]) && $commonArr[1] != "" ? __($commonArr[1], $locale) : "";
            //$txt = str_replace(":", ": ", $txt);
            switch ($commonGeneral) {
                case __("Blessed Virgin Mary", $locale):
                    $commonKey = "of (SING_FEMM)";
                    break;
                case __("Virgins", $locale):
                    $commonKey = "of (PLUR_FEMM)";
                    break;
                case __("Martyrs", $locale):
                case __("Pastors", $locale):
                case __("Doctors", $locale):
                case __("Holy Men and Women", $locale):
                    $commonKey = "of (PLUR_MASC)";
                    break;
                default:
                    $commonKey = "of (SING_MASC)";
            }
            return __("From the Common", $locale) . " " . __($commonKey, $locale) . " " . $commonGeneral . ($commonSpecific != "" ? ": " . $commonSpecific : "");
        }, $commons);
        $common = implode("; " . __("or", $locale) . " ", $commons);
    } else if ($common == "Proper") {
        $common = __("Proper", $locale);
    }
    return $common;
}

function ColorToHex($color){
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

$MESSAGES = [
    "%s day before Epiphany" => [
        "en" => "%s day before Epiphany",
        "it" => "%s giorno prima dell'Epifania",
        "la" => "Dies %s ante Epiphaniam"
    ],
    "%s day after Epiphany" => [
        "en" => "%s day after Epiphany",
        "it" => "%s giorno dopo l'Epifania",
        "la" => "Dies %s post Epiphaniam"
    ],
    "of the %s Week of Ordinary Time" => [
        "en" => "of the %s Week of Ordinary Time",
        "it" => "della %s Settimana del Tempo Ordinario",
        "la" => "Hebdomadæ %s Tempi Ordinarii"
    ],
    "of the %s Week of Easter" => [
        "en" => "of the %s Week of Easter",
        "it" => "della %s Settimana di Pasqua",
        "la" => "Hebdomadæ %s Tempi Paschali"
    ],
    "of the %s Week of Advent" => [
        "en" => "of the %s Week of Advent",
        "it" => "della %s Settimana dell'Avvento",
        "la" => "Hebdomadæ %s Adventus"
    ],
    "%s Day of the Octave of Christmas" => [
        "en" => "%s Day of the Octave of Christmas",
        "it" => "%s Giorno dell'Ottava di Natale",
        "la" => "Dies %s Octavæ Nativitatis"
    ],
    "of the %s Week of Lent" => [
        "en" => "of the %s Week of Lent",
        "it" => "della %s Settimana di Quaresima",
        "la" => "Hebdomadæ %s Quadragesimæ"
    ],
    "after Ash Wednesday" => [
        "en" => "after Ash Wednesday",
        "it" => "dopo il Mercoledì delle Ceneri",
        "la" => "post Feria IV Cinerum"
    ],
    /* The following strings would usually be used by a user-facing application, 
     *  however I decided to add them here seeing they are just as useful for generating
     *  the ICS calendar output, which is pretty final as it is...
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
        "la" => "Doctorum Ecclesiae"
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
    "For a Virgin Martyr" => [
        "en" => "For a Virgin Martyr",
        "it" => "Per una vergine martire",
        "la" => "Pro virgine martyre"
    ],
    "For Several Pastors" => [
        "en" => "For Several Pastors",
        "it" => "Per i pastori",
        "la" => "Pro Pastoribus"
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
        "it" => "Per un Pastore",
        "la" => "Pro Pastoribus"
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
        "la" => "Memoria facoltativa"
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
        "la" => "<B>SOLEMNITAS</B>"
    ],
    "HIGHER RANKING SOLEMNITY" => [
        "en" => "<B><I>precedence over solemnities</I></B>",
        "it" => "<B><I>precedenza sulle solennità</I></B>",
        "la" => "<B><I>praecellentia ante solemnitates</I></B>"
    ]
];

$LATIN_ORDINAL = [
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

$LATIN_ORDINAL_FEM_GEN = [
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

$LATIN_DAYOFTHEWEEK = [
    "Feria I",     //0=Sunday
    "Feria II",    //1=Monday
    "Feria III",   //2=Tuesday
    "Feria IV",    //3=Wednesday
    "Feria V",     //4=Thursday
    "Feria VI",    //5=Friday
    "Feria VII"    //6=Saturday
];


?>