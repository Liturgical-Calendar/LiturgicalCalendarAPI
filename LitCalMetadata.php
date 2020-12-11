<?php

header('Content-Type: application/json');
if(file_exists('nations/index.json') ){
    echo file_get_contents('nations/index.json');
    die();
} else {
    http_response_code(412);
    die();
}
?>