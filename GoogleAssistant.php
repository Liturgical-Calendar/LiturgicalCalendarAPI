<?php
header('Content-Type: application/json');

$entityBody = file_get_contents('php://input');
$data = json_decode($entityBody);
$requestHeaders = getallheaders();
$logFile = 'logs/GoogleAssistant.log';
file_put_contents($logFile,date("Y-m-d H:i:s") . "  Google Assistant interaction request received with request method = " . $_SERVER['REQUEST_METHOD'] . PHP_EOL,FILE_APPEND);
file_put_contents($logFile,serialize($requestHeaders) . PHP_EOL,FILE_APPEND);
file_put_contents($logFile,$entityBody . PHP_EOL . PHP_EOL,FILE_APPEND);

$device = $data->device;
$capabilities = $device->capabilities; //["SPEECH", "LONG_FORM_AUDIO"]
$timeZone = $device->timeZone->id; //{id: "Europe/Rome", version: ""}

$handler = $data->handler; //{name: "GoogleHomeLiturgyToday"}

$home = $data->home; //{params: {}}

$intent = $data->intent; //
$intentName = $intent->name; //"actions.intent.MAIN"
$intentParams = $intent->params; // {}
$query = $intent->query; //"Parla con Liturgia del giorno"

$scene = $data->scene;
$sceneName = $scene->name; //"actions.scene.START_CONVERSATION"
$slotFillingStatus = $scene->slotFillingStatus; // "UNSPECIFIED"
$slots = $scene->slots; // {}

$session = $data->session;
$sessionid = $session->id; //"ABwppHEH7kmCG4wDLIRNhQULUO_ocNL5q9VK2Ro0YP8oL4NmVgpjkHTXGUBmYr0det97nqU_Cr_U2xhvdvnmetn5"
$languageCode = $session->languageCode; // ""
$sessionParams = $session->params; // {}
$typeOverrides = $session->typeOverrides; // []

$user = $data->user;
$accountLinkingStatus = $user->accountLinkingStatus; // "ACCOUNT_LINKING_STATUS_UNSPECIFIED"
$gaiamint = $user->gaiamint; //""
$lastSeenTime = $user->lastSeenTime; // "2020-12-12T01:03:41Z"
$locale = $user->locale; //"it-IT"
$packageEntitlements = $user->packageEntitlements; // []
$userParams = $user->params; //{}
$permissions = $user->permissions; // []
$verificationStatus = $user->verificationStatus; //"VERIFIED"

$responseText = "";
$prefix = $_SERVER['HTTPS'] ? 'https://' : 'http://';
$domain = $_SERVER['HTTP_HOST'];
$query = $_SERVER['PHP_SELF'];
$dir_level = explode("/",$query);
$URL =  $prefix . $domain . "/" . $dir_level[1] . "/LiturgyOfTheDay.php";

$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

switch($locale){
  case "it-IT":
    $dataArray = [
      "nationalpreset" => "ITALY",
      "timezone" => $timeZone
    ];
    break;
  default:
    $dataArray = [
      "nationalpreset" => "USA",
      "timezone" => $timeZone
    ];
}
curl_setopt($ch, CURLOPT_URL, $URL . "?" . http_build_query($dataArray));

$liturgyOfTheDay = curl_exec($ch);
$liturgyOfTheDayJSON = json_decode($liturgyOfTheDay);
$responseText = $liturgyOfTheDayJSON->mainText;

$responseObj = [
  "session" => [
    "id" => $sessionid,
    "params" => new stdClass()
  ],
  "prompt" => [
    "override" => false,
    "firstSimple" => [
      "speech" => $responseText,
      "text" => ""
    ],
  ],
  "scene" => [
    "name" => $sceneName,
    "slots" => new stdClass(),
    "next" => [
      "name" => "actions.scene.END_CONVERSATION"
    ]
  ]
];
echo json_encode($responseObj);
die();

?>