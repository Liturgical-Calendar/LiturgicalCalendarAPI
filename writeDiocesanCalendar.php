<?php

//only allow AJAX requests from the referers that we decide
if(!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest' || $_SERVER["HTTP_REFERER"] != "https://www.johnromanodorazio.com/LiturgicalCalendar-staging/extending.php?choice=diocesan" ) {
    exit;
}

//TODO: add an nonce check for security?
if(!isset($_POST['calendar']) || !isset($_POST['diocese']) || !isset($_POST['nation']) ){
    echo "ERROR: we do not have the necessary data";
} else {
    $CalData = new stdClass();
    $CalData->Nation = $_POST["nation"];
    $CalData->Diocese = $_POST['diocese'];
    $CalData->Calendar = $_POST['calendar'];
    $path = "nations/{$CalData->Nation}";
    if(!file_exists($path) ){
        mkdir($path,0755,true);
    }
    
    file_put_contents($path . "/{$CalData->Diocese}.json",$CalData->Calendar);

    header('Content-Type: application/json');
    echo json_encode($CalData);
}
die();

?>