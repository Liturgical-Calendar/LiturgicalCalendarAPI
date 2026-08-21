<?php

// Locate autoloader by walking up the directory tree
// We start from the folder the current script is running in
$projectFolder  = __DIR__;
$autoloaderPath = null;

// Walk up directories looking for vendor/autoload.php
$level = 0;
while (true) {
    $candidatePath = $projectFolder . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

    if (file_exists($candidatePath)) {
        $autoloaderPath = $candidatePath;
        break;
    }

    // Don't look more than 4 levels up
    if ($level > 4) {
        break;
    }

    $parentDir = dirname($projectFolder);
    if ($parentDir === $projectFolder) { // Reached the filesystem root
        break;
    }

    ++$level;
    $projectFolder = $parentDir;
}

if (null === $autoloaderPath) {
    die('Error: Unable to locate vendor/autoload.php. Please run `composer install` in the project root.');
}

require_once $autoloaderPath;

// Load Redis stubs when ext-redis is not installed (CI / dev environments
// without the extension). The stub file is guarded by class_exists() so it
// is safe to include unconditionally — in production the real extension wins.
if (!extension_loaded('redis')) {
    require_once dirname(__DIR__) . '/stubs/Redis.php';
}

use Dotenv\Dotenv;
use LiturgicalCalendar\Api\ApcuCache;

$dotenv = Dotenv::createMutable($projectFolder, ['.env', '.env.local', '.env.development', '.env.staging', '.env.production'], false);
$dotenv->safeLoad();

// Settle the APCu question once, here, before any test can change the answer (#836).
//
// `ApcuCache::isUsable()` memoises a store/fetch round trip, and it resolves the `apcu_*` names
// against `LiturgicalCalendar\Api` before the global ones. `phpunit_tests/Support/ApcuShim.php`
// declares stand-ins in exactly that namespace, and once any test has required it the declarations
// cannot be undone — so a suite that left the answer unresolved would hand `Utilities` a real,
// working cache from whichever test happened to load the shim first onwards, with a 300-second TTL
// spanning the rest of the run. That is precisely the cross-test coupling the CI workflow's
// `apc.enable_cli=0` exists to prevent ("one test's cache entry would change what a later test
// sees across the whole suite", .github/workflows/phpunit.yml).
//
// The answer pinned here is the one this process would genuinely give with no shim loaded, probed
// through the *global* `apcu_*` functions: false on a host without ext-apcu, false in CI where it is
// loaded but inert, true only where APCu really works under the CLI SAPI (`php -d apc.enable_cli=1`,
// the documented way to run UtilitiesJsonFileCacheTest). `ApcuCacheDetectionTest` resets and restores
// the memo around its own cases, which is the only place the shim is meant to reach these sites.
//
// Deliberately NOT written as a call to `ApcuCache::isUsable()`, however much shorter that would be.
// PHP's `INIT_NS_FCALL_BY_NAME` caches the function it resolved in the opline's run-time cache, so
// executing `ApcuCache`'s unqualified `apcu_store()` here — while the extension is loaded and the shim
// is not — binds that call site to the real function for the life of the process. A shim required
// later would then be ignored at exactly the sites it exists to stand in for, which is not a
// hypothetical: it turned every positive case in `ApcuCacheDetectionTest` red under a loaded ext-apcu
// while leaving them green on a host without one.
$litcalApcuUsable = false;
if (
    function_exists('apcu_store')
    && function_exists('apcu_fetch')
    && function_exists('apcu_exists')
    && function_exists('apcu_delete')
) {
    $litcalApcuProbeKey = 'litcal_bootstrap_apcu_probe_' . bin2hex(random_bytes(8));
    try {
        $litcalApcuUsable = true === apcu_store($litcalApcuProbeKey, 'probe', 10)
            && 'probe' === apcu_fetch($litcalApcuProbeKey);
        apcu_delete($litcalApcuProbeKey);
    } catch (\Throwable) {
        $litcalApcuUsable = false;
    }
}
( new ReflectionProperty(ApcuCache::class, 'usable') )->setValue(null, $litcalApcuUsable);
