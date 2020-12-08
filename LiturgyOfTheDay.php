<?php

/** 
 *  CLASS FESTIVITY
 *  SIMILAR TO THE CLASS USED IN THE LITCAL PHP ENGINE, 
 *  EXCEPT THAT IT CONVERTS PHP TIMESTAMP TO DATETIME OBJECT 
 *  AND DOES NOT IMPLEMENT JSONSERIALIZABLE OR COMPARATOR FUNCTION
 **/
class Festivity
{
    /**
     * @var string
     */
    public $name;

    /**
     * @var DateTime object
     */
    public $date;

    /**
     * @var string
     */
    public $color;

    /**
     * @var string
     */
    public $type;

    /**
     * @var int
     */
    public $grade;

    /**
     * @var string
     */
    public $displayGrade;

    /**
     * @var string
     */
    public $common;

    /**
     * @var string
     */
    public $liturgicalyear;

    function __construct($name, $date, $color, $type, $grade = 0, $common = '', $liturgicalyear = null, $displayGrade)
    {
        $this->name = (string) $name;
        $this->date = (object) DateTime::createFromFormat('U', $date, new DateTimeZone('UTC')); //
        $this->color = (string) $color;
        $this->type = (string) $type;
        $this->grade = (int) $grade;
        $this->common = (string) $common;
        if($liturgicalyear !== null){
            $this->liturgicalyear = (string) $liturgicalyear;
        }
        $this->displayGrade = (string) $displayGrade;
    }
}

function __($key,$locale=LITCAL_LOCALE){
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
    return $key;
}

/**
 * Function _G
 * Returns a translated string with the Grade (Rank) of the Festivity
 */
function _G($key,$locale=LITCAL_LOCALE){
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
    return $grade;
}

/**
 * Function _C
 * Gets a translated human readable string with the Common or the Proper
 */
function _C($common,$locale=LITCAL_LOCALE){
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

$MESSAGES = [
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
        "en" => "weekday",
        "it" => "feria",
        "la" => "feria"
    ],
    "COMMEMORATION" => [
        "en" => "Commemoration",
        "it" => "Commemorazione",
        "la" => "Commemoratio"
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
        "en" => "FEAST OF THE LORD",
        "it" => "FESTA DEL SIGNORE",
        "la" => "FESTUM DOMINI"
    ],
    "SOLEMNITY" => [
        "en" => "SOLEMNITY",
        "it" => "SOLENNITÀ",
        "la" => "SOLLEMNITAS"
    ],
    "This evening there will be a Vigil Mass for the %s %s." => [
        "en" => "This evening there will be a Vigil Mass for the %s %s.",
        "it" => "Questa sera ci sarà la Messa nella Vigilia per la %s %s.",
        "la" => "Haec vespera erit Missa Vigiliae in %s %s."
    ],
    "Vigil Mass" => [
        "en" => "Vigil Mass",
        "it" => "Messa nella Vigilia",
        "la" => "Missa Vigiliæ"
    ],
    "also" => [
        "en" => "also",
        "it" => "anche",
        "la" => "etiam"
    ],
    "Today is %s the %s of %s." => [
        "en" => "Today is %s the %s of %s.",
        "it" => "Oggi è %s la %s di %s.",
        "la" => "Hodie est %s %s %s."
    ],
    "Today is" => [
        "en" => "Today is",
        "it" => "Oggi è",
        "la" => "Hodie est"
    ]
];

$SUPPORTED_NATIONS = ["ITALY","USA"];
$SUPPORTED_DIOCESES = [];

$LOCALE = isset($_GET["locale"]) ? strtoupper($_GET["locale"]) : null;
$NATIONALPRESET = isset($_GET["nationalpreset"]) ? strtoupper($_GET["nationalpreset"]) : null;
$DIOCESANPRESET = isset($_GET["diocesanpreset"]) ? strtoupper($_GET["diocesanpreset"]) : null;
$TIMEZONE = isset($_GET["timezone"]) ? $_GET["timezone"] : null;

$prefix = $_SERVER['HTTPS'] ? 'https://' : 'http://';
$domain = $_SERVER['HTTP_HOST'];
$query = $_SERVER['PHP_SELF'];
$dir_level = explode("/",$query);
$URL =  $prefix . $domain . "/" . $dir_level[1] . "/LitCalMetadata.php";

$ch = curl_init($URL);
// Disable SSL verification
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
// Will return the response, if false it print the response
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// Execute
$result = curl_exec($ch);

if (curl_errno($ch)) {
    // this would be your first hint that something went wrong
    die("Could not send request. Curl error: " . curl_error($ch));
} else {
    // check the HTTP status code of the request
    $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resultStatus != 200) {
        // the request did not complete as expected. common errors are 4xx
        // (not found, bad request, etc.) and 5xx (usually concerning
        // errors/exceptions in the remote script execution)
        if($resultStatus == 412){
            //the index.json file simply doesn't exist yet
        } else {
            die("Request failed. HTTP status code: " . $resultStatus);
        }
    } else {
        //we have results from the metadata endpoint
        $SUPPORTED_DIOCESES = json_decode($result,true);
    }
}

// Closing
curl_close($ch);

$queryArray = [];
if($LOCALE !== null){
    $queryArray["locale"] = $LOCALE;
}
if($NATIONALPRESET !== null && in_array($NATIONALPRESET,$SUPPORTED_NATIONS) ){
    $queryArray["nationalpreset"] = $NATIONALPRESET;
    switch($NATIONALPRESET){
        case "ITALY":
            $queryArray["locale"] = "IT";
        break;
        case "USA":
            $queryArray["locale"] = "EN";
        break;
    }
}
if($DIOCESANPRESET !== null && array_key_exists($DIOCESANPRESET,$SUPPORTED_DIOCESES)){
    $queryArray["diocesanpreset"] = $DIOCESANPRESET;
    $queryArray["nationalpreset"] = $SUPPORTED_DIOCESES[$DIOCESANPRESET]["nation"];
    switch($SUPPORTED_DIOCESES[$DIOCESANPRESET]["nation"]){
        case "ITALY":
            $queryArray["locale"] = "IT";
        break;
        case "USA":
            $queryArray["locale"] = "EN";
        break;
    }
}

//last resort is Latin for the Universal Calendar
if(!isset($queryArray["locale"])){
    $queryArray["locale"] = "LA";
}
define("LITCAL_LOCALE", $queryArray["locale"]);

if($TIMEZONE == null){
    ini_set('date.timezone', 'Europe/Vatican');
} else {
    ini_set('date.timezone', $TIMEZONE);
}
//ini_set('date.timezone', 'UTC');
setlocale(LC_TIME, strtolower($LOCALE) . '_' . $LOCALE);

$dateTimeToday = (new DateTime( 'now' ))->format("Y-m-d") . " 00:00:00";
$dateToday = DateTime::createFromFormat('Y-m-d H:i:s', $dateTimeToday, new DateTimeZone('UTC') );
$dateTodayTimestamp = $dateToday->format("U");

$prefix = $_SERVER['HTTPS'] ? 'https://' : 'http://';
$domain = $_SERVER['HTTP_HOST'];
$query = $_SERVER['PHP_SELF'];
//echo $query . PHP_EOL;
$dir_level = explode("/",$query);
$URL =  $prefix . $domain . "/" . $dir_level[1] . "/LitCalEngine.php";
//echo $URL;

$ch1 = curl_init();
// Disable SSL verification
curl_setopt($ch1, CURLOPT_SSL_VERIFYPEER, false);
// Will return the response, if false it print the response
curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
// Set the url
curl_setopt($ch1, CURLOPT_URL, $URL);
// Set request method to POST
curl_setopt($ch1, CURLOPT_POST, 1);
// Define the POST field data    
curl_setopt($ch1, CURLOPT_POSTFIELDS, http_build_query($queryArray));
// Execute
$result = curl_exec($ch1);

if (curl_errno($ch1)) {
    // this would be your first hint that something went wrong
    die("Could not send request. Curl error: " . curl_error($ch1));
} else {
    // check the HTTP status code of the request
    $resultStatus = curl_getinfo($ch1, CURLINFO_HTTP_CODE);
    if ($resultStatus != 200) {
        // the request did not complete as expected. common errors are 4xx
        // (not found, bad request, etc.) and 5xx (usually concerning
        // errors/exceptions in the remote script execution)

        die("Request failed. HTTP status code: " . $resultStatus);
    }
}

// Closing
curl_close($ch1);

// Gather the json results from the server into $LitCal array similar to the PHP Engine
$LitCal = array();
$LitCalFeed = array();
$idx = 0;
$dateToday->add(new DateInterval('PT10M'));
$LitCalData = json_decode($result, true); // decode as associative array rather than stdClass object
if (isset($LitCalData["LitCal"])) {
    $LitCal = $LitCalData["LitCal"];
    foreach ($LitCal as $key => $value) {
        if($LitCal[$key]["date"] === $dateTodayTimestamp){
            $publishDate = $dateToday->sub(new DateInterval('PT1M'))->format("Y-m-d\TH:i:s\Z");
            // retransform each entry from an associative array to a Festivity class object
            $LitCal[$key] = new Festivity($LitCal[$key]["name"], $LitCal[$key]["date"], $LitCal[$key]["color"], $LitCal[$key]["type"], $LitCal[$key]["grade"], $LitCal[$key]["common"], (isset($LitCal[$key]["liturgicalyear"]) ? $LitCal[$key]["liturgicalyear"] : null), $LitCal[$key]["displaygrade"] );
            if($LitCal[$key]->grade === 0){
                $mainText = __("Today is") . " " . $LitCal[$key]->name . ".";
            } else{ 
                if(strpos($LitCal[$key]->name,"Vigil")){
                    $mainText = sprintf(__("This evening there will be a Vigil Mass for the %s %s."),_G($LitCal[$key]->grade),trim(str_replace(__("Vigil Mass"),"",$LitCal[$key]->name)));
                } else if($LitCal[$key]->grade < 7) {
                    $mainText = sprintf(__("Today is %s the %s of %s."),($idx > 0 ? __("also") : ""),_G($LitCal[$key]->grade),$LitCal[$key]->name);
                    if($LitCal[$key]->grade < 4 && $LitCal[$key]["common"] != "Proper"){
                        $mainText = $mainText . " " . _C($LitCal[$key]["common"]);
                    }
                }
            }
            $LitCalFeed[] = new stdClass();
            $LitCalFeed[count($LitCalFeed)-1]->uid = "urn:uuid:" . md5("LITCAL-" . $key . '-' . $LitCal[$key]->date->format('Y'));
            $LitCalFeed[count($LitCalFeed)-1]->updateDate = $publishDate;
            $LitCalFeed[count($LitCalFeed)-1]->titleText = "Liturgy of the Day " . $LitCal[$key]->date->format('F jS');
            $LitCalFeed[count($LitCalFeed)-1]->mainText = $mainText;
            $LitCalFeed[count($LitCalFeed)-1]->redirectionUrl = "https://johnromanodorazio.com/LiturgicalCalendar/";
            ++$idx;
        }
    }

    header('Content-Type: application/json');
    if(count($LitCalFeed) === 1){
        echo json_encode($LitCalFeed[0]);
    } else if(count($LitCalFeed) > 1){
        echo json_encode($LitCalFeed);
    }
}

die();

?>