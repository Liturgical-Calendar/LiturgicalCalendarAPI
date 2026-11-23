<?php

/**
 * Probe the bishops' conference readings sites for the range they publish (#1074), and
 * propose (or, with --apply, write) new first_date/last_date values in lectionary_sources.json.
 *
 * Run it from a machine the site does not refuse: the USCCB refuses scripted requests from most
 * server addresses, which is why the monthly task described in LECTIONARY_SOURCES_PROMPT.md may
 * read the USCCB through a browser instead. The script never retries past a refusal and sends no
 * browser headers: a refusal stops that source, keeps its dates, and makes the exit code 2.
 *
 * Usage:
 *   php scripts/probe-lectionary-sources.php                  dry run, every source
 *   php scripts/probe-lectionary-sources.php --source cei     dry run, one source
 *   php scripts/probe-lectionary-sources.php --apply          write the changed dates
 *   options: --max-days N (default 62), --pause-ms N (default 3000)
 *
 * Exit codes: 0 done (changed or not); 1 bad input, or proposed dates that would leave the file
 * inconsistent; 2 a source stopped the probe (a refusal, or no day published near last_date).
 */

declare(strict_types=1);

// Refuse any entry that is not the CLI (see lint-missals.php for why this is inlined).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use GuzzleHttp\Client;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\JsonFormatter;
use LiturgicalCalendar\Api\Models\Lectionary\LectionarySources;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\Lectionary\LectionarySourceProbe;

Router::$apiFilePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;

$options = getopt('', ['apply', 'source:', 'max-days:', 'pause-ms:']);
$apply   = isset($options['apply']);
$only    = isset($options['source']) && is_string($options['source']) ? $options['source'] : null;
$maxDays = isset($options['max-days']) && is_string($options['max-days']) ? max(1, (int) $options['max-days']) : 62;
$pauseMs = isset($options['pause-ms']) && is_string($options['pause-ms']) ? max(0, (int) $options['pause-ms']) : 3000;

$path    = JsonData::LECTIONARY_SOURCES_FILE->path();
$sources = json_decode((string) file_get_contents($path), true);
if (!is_array($sources)) {
    fwrite(STDERR, "{$path} is not a JSON object\n");
    exit(1);
}
if ($only !== null && !isset($sources[$only])) {
    fwrite(STDERR, "No source {$only} in {$path}\n");
    exit(1);
}

$client = new Client([
    'http_errors'     => false,
    'timeout'         => 30,
    'allow_redirects' => false,
    'headers'         => ['User-Agent' => 'LiturgicalCalendarAPI lectionary-sources probe (+https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI)'],
]);
$fetch  = static function (string $url) use ($client): array {
    $response = $client->get($url);
    return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
};
$probe  = new LectionarySourceProbe($fetch, $pauseMs);

$changed = false;
$refused = false;
foreach ($sources as $key => $source) {
    if (( $only !== null && $key !== $only ) || !is_array($source)) {
        continue;
    }
    /** @var array{day_page:string, first_date:string, last_date:string} $source */
    $result = $probe->probe((string) $key, $source, $maxDays);
    echo "== {$key}\n";
    foreach ($result->log as $line) {
        echo "   {$line}\n";
    }
    if ($result->stoppedAt !== null) {
        echo "   STOPPED: {$result->stoppedAt}; dates kept\n";
        $refused = true;
        continue;
    }
    foreach (['first_date' => $result->firstDate, 'last_date' => $result->lastDate] as $field => $value) {
        if ($value === null) {
            echo "   {$field}: {$source[$field]} (unchanged)\n";
            continue;
        }
        echo "   {$field}: {$source[$field]} -> {$value}\n";
        $source[$field] = $value;
        $changed        = true;
    }
    $sources[$key] = $source;
}

$problems = LectionarySources::problems($sources);
if ($changed && $problems !== []) {
    fwrite(STDERR, "The proposed dates leave {$path} inconsistent; nothing written:\n  " . implode("\n  ", $problems) . "\n");
    exit(1);
}

if ($changed && $apply) {
    file_put_contents($path, JsonFormatter::encode($sources) . PHP_EOL);
    echo "Wrote {$path}\n";
} elseif ($changed) {
    echo "Dry run: pass --apply to write {$path}\n";
}
exit($refused ? 2 : 0);
