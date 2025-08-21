<?php

// search for the first composer.json file in the parent directories
// and load the environment variables from any `.env` files in the same directory
// We start from the folder the current script is running in
$projectFolder = __DIR__;

// And if composer.json is not there, we start to look for it in the parent directories
$level = 0;
while (true) {
    if (file_exists($projectFolder . DIRECTORY_SEPARATOR . 'composer.json')) {
        break;
    }

    // Don't look more than 4 levels up
    if ($level > 4) {
        $projectFolder = null;
        break;
    }

    $parentDir = dirname($projectFolder);
    if ($parentDir === $projectFolder) { // reached the system root folder
        $projectFolder = null;
        break;
    }

    ++$level;
    $projectFolder = $parentDir;
}

if (null === $projectFolder) {
    throw new Exception('Unable to find project root folder, cannot load scripts or environment variables.');
}

require_once $projectFolder . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable($projectFolder, ['.env', '.env.local', '.env.development', '.env.production']);
$dotenv->safeLoad();
