<?php

// tests/bootstrap.php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..', ['.env', '.env.local', '.env.development', '.env.production']);
$dotenv->safeLoad();
