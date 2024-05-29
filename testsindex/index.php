<?php

require_once '../vendor/autoload.php';
require_once '../includes/enums/RequestContentType.php';
require_once '../includes/enums/StatusCode.php';
require_once '../includes/TestsIndex.php';

use LitCal\TestsIndex;

echo TestsIndex::handleRequest();
exit(0);
