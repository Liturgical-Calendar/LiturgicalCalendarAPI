<?php 
//turn on error reporting for the staging site
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$LOCALE = Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);
$LOCALE = !empty($_COOKIE["currentLocale"]) ? $_COOKIE["currentLocale"] : $LOCALE;
if($LOCALE !== null){
    //we only need the two letter ISO code, not the national extension
    if(strpos($LOCALE,"_")){
        $LOCALE = explode("_", $LOCALE)[0];
    } else if (strpos($LOCALE,"-")){
        $LOCALE = explode("-", $LOCALE)[0];
    }
} else {
    $LOCALE = "en";
}
define("LITCAL_LOCALE", $LOCALE );

/**
 * Translation function __()
 */

function __($key, $locale = LITCAL_LOCALE)
{
    global $messages;
    $lcl = strtolower($locale);
    if (isset($messages)) {
        if (isset($messages[$key])) {
            if (isset($messages[$key][$lcl])) {
                return $messages[$key][$lcl];
            } else {
                return $messages[$key]["en"];
            }
        } else {
            return $key;
        }
    } else {
        return $key;
    }
}

/**
 * Translation function _e()
 */

function _e($key, $locale = LITCAL_LOCALE)
{
    global $messages;
    $lcl = strtolower($locale);
    if (isset($messages)) {
        if (isset($messages[$key])) {
            if (isset($messages[$key][$lcl])) {
                echo $messages[$key][$lcl];
            } else {
                echo $messages[$key]["en"];
            }
        } else {
            echo $key;
        }
    } else {
        echo $key;
    }
}

/**
 * Function _C
 * Gets a translated human readable string with the Common or the Proper
 */
function _C($common, $locale = LITCAL_LOCALE){
    $locale = strtolower($locale);
    if ($common !== "" && $common !== "Proper") {
        $commons = explode(",", $common);
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
                case __("Dedication of a Church", $locale):
                    $commonKey = "of (SING_FEMM)";
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


$messages = [
    "Usage" => [
        "de" => "Verwendung",
        "en" => "Usage",
        "es" => "Uso",
        "fr" => "Usage",
        "it" => "Utilizzo",
        "pt" => "Uso"
    ],
    "Extending the API" => [
        "de" => "API erweitern",
        "en" => "Extending the API",
        "es" => "Ampliando la API",
        "fr" => "Extension de l'API",
        "it" => "Estendere l'API",
        "pt" => "Extensão da API"
    ],
    "About us" => [
        "de" => "Wer wir sind",
        "en" => "About us",
        "es" => "Quienes somos",
        "fr" => "Qui nous sommes",
        "it" => "Chi siamo",
        "pt" => "Quem nós somos"
    ],
    "green" => [
        "de" => "grün",
        "en" => "green",
        "es" => "verde",
        "fr" => "vert",
        "it" => "verde",
        "lat" => "viridis",
        "pt" => "verde"
    ],
    "purple" => [
        "de" => "violett",
        "en" => "purple",
        "es" => "violeta",
        "fr" => "violet",
        "it" => "viola",
        "lat" => "purpura",
        "pt" => "violeta"
    ],
    "white" => [
        "de" => "weiß",
        "en" => "white",
        "es" => "blanco",
        "fr" => "blanc",
        "it" => "bianco",
        "lat" => "albus",
        "pt" => "branco"
    ],
    "red" => [
        "de" => "rot",
        "en" => "red",
        "es" => "rojo",
        "fr" => "rouge",
        "it" => "rosso",
        "lat" => "ruber",
        "pt" => "vermelho"
    ],
    "pink" => [
        "de" => "rosa",
        "en" => "pink",
        "es" => "rosa",
        "fr" => "rose",
        "it" => "rosa",
        "lat" => "rosea",
        "pt" => "rosa"
    ],
    "Month" => [
        "de" => "Monat",
        "en" => "Month",
        "es" => "Mes",
        "fr" => "Mois",
        "it" => "Mese",
        "lat" => "Mensis",
        "pt" => "Mês"
    ],
    "Day" => [
        "de" => "Tag",
        "en" => "Day",
        "es" => "Día",
        "fr" => "Jour",
        "it" => "Giorno",
        "lat" => "Dies",
        "pt" => "Dia"
    ],
    "Name" => [
        "de" => "Name",
        "en" => "Name",
        "es" => "Nombre",
        "fr" => "Nom",
        "it" => "Nome",
        "lat" => "Nomen",
        "pt" => "Nome"
    ],
    "Liturgical color" => [
        "de" => "Liturgische Farbe",
        "en" => "Liturgical color",
        "es" => "Color litúrgico",
        "fr" => "Couleur liturgique",
        "it" => "Colore liturgico",
        "lat" => "Color liturgicum",
        "pt" => "Cor litúrgica"
    ],
    "Solemnities" => [
        "de" => "Feierlichkeiten",
        "en" => "Solemnities",
        "es" => "Solemnidades",
        "fr" => "Solennités",
        "it" => "Solennità",
        "lat" => "Sollemnitates",
        "pt" => "Solenidades"
    ],
    "Feasts" => [
        "de" => "Feste",
        "en" => "Feasts",
        "es" => "Fiestas",
        "fr" => "Fêtes",
        "it" => "Feste",
        "lat" => "Festuum",
        "pt" => "Festas"
    ],
    "Memorials" => [
        "de" => "Gedenkfeiern",
        "en" => "Memorials",
        "es" => "Memorias",
        "fr" => "Mémoires",
        "it" => "Memorie obbligatorie",
        "lat" => "Memoriae",
        "pt" => "Memórias"
    ],
    "Optional memorials" => [
        "de" => "Optionale Gedenkfeiers",
        "en" => "Optional memorials",
        "es" => "Memorias opcionales",
        "fr" => "Mémoires optionnelles",
        "it" => "Memorie facoltative",
        "lat" => "Memoriae ad libitum",
        "pt" => "Memórias opcionais"
    ],
    "From the Common" => [
        "en" => "From the Common",
        "it" => "Dal Comune",
        "la" => "De Communi"
    ],
    "Proper" => [
        "en" => "Proper",
        "it" => "Proprio",
        "la" => "Proprium"
    ],
    "Common" => [
        "en" => "Common",
        "it" => "Comune",
        "la" => "Commune"
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
    "Dedication of a Church" => [
        "en" => "Dedication of a Church",
        "it" => "Dedicazione di una Chiesa",
        "la" => "Dedicationis Ecclesiæ"
    ],
    "or" => [
        "en" => "or",
        "it" => "oppure",
        "la" => "vel"
    ]
];


?>