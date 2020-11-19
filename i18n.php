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
    ]
];


?>