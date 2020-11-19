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
];


?>