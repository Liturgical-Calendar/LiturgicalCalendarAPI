<?php

// tests/bootstrap.php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..', ['.env', '.env.local', '.env.development', '.env.production']);
$dotenv->safeLoad();

// Optional validation example:
if (!isset($_ENV['APP_ENV']) || !in_array($_ENV['APP_ENV'], ['development', 'production'], true)) {
    throw new RuntimeException('APP_ENV must be set to "development" or "production"');
}
