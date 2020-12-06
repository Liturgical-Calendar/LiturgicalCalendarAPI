<?php

$CalData = new stdClass();

//only allow AJAX requests from the referers that we decide
if(!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest' ) {
    $CalData->ERROR = "request is not an ajax request";
    $CalData->XRequestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'];
    $CalData->HTTPReferer = $_SERVER["HTTP_REFERER"];
    header('Content-Type: application/json');
    echo json_encode($CalData);
    exit;
}

$allowedDomains = [
    "https://www.johnromanodorazio.com/LiturgicalCalendar-staging/extending.php?choice=diocesan",
    "https://www.johnromanodorazio.com/LiturgicalCalendar/extending.php?choice=diocesan",
    "https://johnromanodorazio.com/LiturgicalCalendar-staging/extending.php?choice=diocesan",
    "https://johnromanodorazio.com/LiturgicalCalendar/extending.php?choice=diocesan"
];

if(in_array($_SERVER["HTTP_REFERER"],$allowedDomains) ){
    //TODO: add an nonce check for security?
    if(!isset($_POST['calendar']) || !isset($_POST['diocese']) || !isset($_POST['nation']) ){
        $CalData->ERROR = "we do not have the necessary data";
    } else {
        $CalData->Nation = $_POST["nation"];
        $CalData->Diocese = $_POST['diocese'];
        $CalData->Calendar = $_POST['calendar'];
        $path = "nations/{$CalData->Nation}";

        unlink($path . "/{$CalData->Diocese}.json");

        $index = json_decode(file_get_contents("nations/index.json"));
        $key = strtoupper(preg_replace("/[^a-zA-Z]/","",$CalData->Diocese));
        
        unset($index->$key);

        file_put_contents("nations/index.json",json_encode($index) . PHP_EOL);
    }
    header('Content-Type: application/json');
    echo json_encode($CalData);
    exit;
} else {
    $CalData->ERROR = "request not issued from a whitelisted domain";
    $CalData->XRequestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'];
    $CalData->HTTPReferer = $_SERVER["HTTP_REFERER"];
    $CalData->Nation = $_POST["nation"];
    $CalData->Diocese = $_POST['diocese'];
    $CalData->Calendar = $_POST['calendar'];
    if(isset($_POST['group'])){
        $CalData->Group = $_POST['group'];
    }
    header('Content-Type: application/json');
    echo json_encode($CalData);
    exit;
}

?>