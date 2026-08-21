<?php

namespace LiturgicalCalendar\Api;

use Swaggest\JsonSchema\Schema;
use Sabre\VObject;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlMultiHandler;
use React\Promise;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Filesystem\Factory;
use React\EventLoop\Loop;
use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Enum\FrameFamily;
use LiturgicalCalendar\Api\Enum\ICSErrorLevel;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\ProtocolErrorCode;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Enum\Status;
use LiturgicalCalendar\Api\Enum\Step;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableItem;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\WebSocketMessageValidator;
use Symfony\Component\Yaml\Yaml;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Models\Metadata\MetadataDiocesanCalendarItem;
use LiturgicalCalendar\Api\Test\LitTestRunner;
use Psr\Http\Message\ResponseInterface;

/**
 * This class provides a WebSocket-based interface for executing various tests
 * of the Liturgical Calendar API, such as JSON schema validation and unit tests.
 *
 * @phpstan-type DiocesanCalendarCollectionItem \stdClass&object{
 *      calendar_id: string,
 *      diocese: string,
 *      nation: string,
 *      locales: string[],
 *      timezone: string,
 *      group?: string
 * }
 *
 * `executeValidation` accepts exactly three categories, and they are NOT interchangeable — each names a different
 * schema-resolution strategy, and the wrong one yields a null schema rather than a loud failure:
 *
 *   - `universalcalendar`  — the schema is resolved from the `sourceFile` **path**, via {@see Health::getPathToSchemaFile()}.
 *                            `validate` is a display/CSS label only (PascalCase), never a schema key.
 *   - `sourceDataCheck`    — the schema is resolved from the `validate` **slug** (anchored lowercase patterns such as
 *                            `national-calendar-IT`). The data path is `sourceFile`/`sourceFolder` **as supplied**, except for the
 *                            `wider-region-…`, `national-calendar-…`, `diocesan-calendar-…` and `proprium-de-sanctis-…-i18n` slugs,
 *                            where the server reconstructs the path from the slug — which is why messages carrying those send a bare
 *                            id (`IT`, `Europe`) while the rest send a real path (`jsondata/tests/roman/…`).
 *   - `resourceDataCheck`  — the schema is resolved from the `sourceFile` **URL** of an API endpoint.
 *
 * Do not confuse these with the *calendar type* named by `category` on `validateCalendar` and `executeUnitTest` below;
 * that is an unrelated vocabulary that merely shares the property name. See issue #806.
 *
 * `validateCalendar` accepts two message shapes, and the property `calendar` is what tells them apart. A **string**
 * `calendar` is the legacy (v1) form aliased as `ValidateCalendar`: the identity is spread across `calendar` plus
 * `category`, and `rite` is an optional hint. An **object** `calendar` is the reshaped (v2) form aliased as
 * `ValidateTypedCalendar`: one `CalendarIdentity` carrying `kind`, `id` and `rite`, no `category`, and `responsetype`
 * respelled `responseFormat`. The action name is the same in both because the action is the same; only the identity
 * became typed — and with it the standing of `rite`, which stops being a hint and becomes an assertion the server
 * checks; {@see Health::resolveCalendarIdentity()} is where that is argued and enforced.
 * See {@see Health::isTypedCalendarMessage()} and issue #806 section D.
 *
 * `runTest` is the reshaped `executeUnitTest`, aliased as `RunTest`: a `test` name, the same
 * `CalendarIdentity` — resolved by the same {@see Health::resolveCalendarIdentity()}, so the mapping
 * and the rite check exist once for both actions — and a year. It carries no `responseFormat`
 * because a test runs against the parsed calendar rather than against a chosen representation of it.
 * Unlike `validateCalendar` it took a new *name*, which is the whole of its discrimination: a v1
 * client cannot emit a name it does not know. `executeUnitTest` is untouched and stays reachable
 * until UnitTestInterface#42 ships. See issue #806 section E.
 *
 * All three reshaped messages — `validateSource`, `validateCalendar` and `runTest` — reject a legacy
 * property their own shape retired, rather than ignoring it. See {@see Health::RETIRED_PROPERTIES}
 * for the rule and {@see Health::rejectRetiredProperties()} for the one implementation of it.
 *
 * **`requestId` is accepted on any message and echoed on every frame that message produces**,
 * rejections included. It is the client's own handle for one request — validated against
 * {@see Health::CORRELATION_ID_PATTERN}, so junk never reaches the wire, and omitted from the frames
 * entirely when the client sends none, which is every v1 message. It replaces attribution by CSS
 * selector: until now a client's only statement of which of its cards a frame belonged to was the
 * `classes` string the server had built for it.
 *
 * It is **not** a second `runToken`, and the difference decides where each is kept. A token scopes a
 * *run*, so it is stored per connection and any frame may take the connection's current one; an id
 * scopes a single *request*, and `Health` is asynchronous, so the connection's most recent request is
 * very often not the one a late frame answers. Each handler therefore captures the id it was called
 * with and threads it through its closures. See {@see Health::onMessage()}, where that is argued in
 * full. `runId` is published alongside `runToken` with the same value, so a client adopting the newer
 * spelling migrates once rather than twice.
 *
 * Do not confuse `runTest` with the inventory item `test:{rite}:{Name}` that `validateSource`
 * addresses. That item is a *source check*: does the test definition exist, parse, and validate
 * against `LitCalTest.json`. `runTest` *runs* the test that definition describes against a computed
 * calendar. A definition can be valid while the test it describes fails, and vice versa, so the two
 * are separate operations with separate addresses.
 *
 * @phpstan-type ExecuteValidationCategory 'universalcalendar'|'sourceDataCheck'|'resourceDataCheck'
 * @phpstan-type ExecuteValidationSourceFolder \stdClass&object{action:'executeValidation',category:'sourceDataCheck',validate:string,sourceFolder:string,responsetype?:string}
 * @phpstan-type ExecuteValidationSourceFile \stdClass&object{action:'executeValidation',category:'universalcalendar'|'sourceDataCheck',validate:string,sourceFile:string,responsetype?:string}
 * @phpstan-type ExecuteValidationResource \stdClass&object{action:'executeValidation',category:'resourceDataCheck',validate:string,sourceFile:string,responsetype?:string}
 * @phpstan-type ValidateCalendar \stdClass&object{action:'validateCalendar',calendar:string,year:int,category:'nationalcalendar'|'diocesancalendar'|'ritecalendar',responsetype:'JSON'|'XML'|'ICS'|'YML',rite?:string}
 * @phpstan-type CalendarIdentity \stdClass&object{kind:'general'|'national'|'diocesan'|'rite',id?:string,rite:string}
 * @phpstan-type ValidateTypedCalendar \stdClass&object{action:'validateCalendar',calendar:CalendarIdentity,year:int,responseFormat:'JSON'|'XML'|'ICS'|'YML',runToken?:string,requestId?:string}
 * @phpstan-type ExecuteUnitTest \stdClass&object{action:'executeUnitTest',calendar:string,year:int,category:'nationalcalendar'|'diocesancalendar'|'ritecalendar',test:string,rite?:string}
 * @phpstan-type RunTest \stdClass&object{action:'runTest',test:string,calendar:CalendarIdentity,year:int,runToken?:string,requestId?:string}
 * @phpstan-type CancelRun \stdClass&object{action:'cancelRun',runToken:string}
 * @phpstan-type ValidateSource \stdClass&object{action:'validateSource',target:\stdClass&object{id:string},runToken?:string,requestId?:string}
 *
 * @phpstan-import-type LiturgicalEvent from \LiturgicalCalendar\Api\Test\LitTestRunner
 */
class Health implements MessageComponentInterface
{
    /**
     * A collection of connected clients.
     *
     * @var \SplObjectStorage<ConnectionInterface, null> $clients
     */
    protected \SplObjectStorage $clients;

    /**
     * The legacy properties each reshaped message *replaced*, and what replaced them.
     *
     * **A v2 message that also carries a legacy property its own shape retired is rejected**, on all
     * three reshaped actions, by maintainer ruling. Not a breaking change: a v1 client sends a string
     * `calendar` or an old action name and never reaches these checks. What it catches is the
     * half-migrated client — one that has adopted the new shape and is still sending the old fields —
     * which otherwise gets behaviour that looks correct, because a retired property is simply never
     * read, and breaks on the day the legacy branch is removed. A loud error while the two still
     * agree is the whole value.
     *
     * Uniform on purpose. Making a client's mistake loud on one action and silent on two would be
     * worse than either answer applied consistently, because the client cannot tell which it is
     * getting.
     *
     * `runTest` retires `category` and `rite`: the `executeUnitTest` shape in `WebSocketMessage.json`
     * requires `category`, `calendar`, `year` and `test`, so there was never a `responsetype` on it
     * to retire. `runToken` is retired by nothing — it is shared, current, and valid on all three.
     *
     * **The retired set is not derivable from `WebSocketMessage.json`'s `required` lists**, and an
     * audit that assumed it was is how `rite` came to be missed on both calendar actions: those
     * lists name only *required* properties, so every optional v1 property — `rite` on
     * `validateCalendar` and `executeUnitTest`, `responsetype` on `executeValidation` — was
     * structurally invisible to it.
     * When adding a shape here, read the v1 predecessor's `@phpstan-type` alias at the top of this
     * file, where the optional properties are the ones marked `?`. (`responsetype` on
     * `executeValidation` is the one optional property deliberately *not* retired: `validateSource`
     * has no response representation to choose, `executeValidation()` never read it, and retiring it
     * would answer a question no client is asking.)
     *
     * Keyed by action; `shape` completes the sentence "… is not part of a %s" and is why
     * `validateCalendar`'s reads "a validateCalendar message with an object calendar": on a *string*
     * calendar `category` is required, not retired, and a message that said otherwise would be lying
     * to a v1 client. `retired` maps each retired property to the clause naming its replacement, so
     * the rejection tells a migrating client what to do rather than only that it is wrong.
     *
     * @var array<string, array{shape: string, retired: array<string, string>}>
     */
    private const RETIRED_PROPERTIES = [
        'validateSource'   => [
            'shape'   => 'validateSource message',
            'retired' => [
                // `executeValidation` spread the address across a schema-resolution strategy and a
                // path; an inventory id is the whole address, and the server resolves both from it.
                'category'     => 'target.id replaces it.',
                'validate'     => 'target.id replaces it.',
                'sourceFile'   => 'target.id replaces it.',
                'sourceFolder' => 'target.id replaces it.'
            ]
        ],
        'validateCalendar' => [
            'shape'   => 'validateCalendar message with an object calendar',
            'retired' => [
                'category'     => 'calendar.kind replaces it.',
                // Task 3 left this one accepted, reasoning that alongside a correct `responseFormat`
                // it was stale noise from a client that had already done the rename right. The
                // uniform rule overrules that: it is precisely the half-migration signal worth
                // seeing. Sent *instead of* `responseFormat` it never reaches here at all — the
                // property list turns the message away for the missing required property, and that
                // reading is unchanged.
                'responsetype' => 'responseFormat replaces it.',
                // Optional on v1, and therefore invisible to an audit that read the schema's
                // required-property list only. It is the one retired property whose silent
                // acceptance would be actively dangerous: a half-migrated client that objectified
                // `calendar` but kept its old top-level `rite` gets a *rite disagreement* ignored,
                // which is exactly what the typed identity went out of its way to make loud.
                'rite'         => 'calendar.rite replaces it.'
            ]
        ],
        'runTest'          => [
            'shape'   => 'runTest message',
            'retired' => [
                'category' => 'calendar.kind replaces it.',
                // Same optional property, same predecessor treatment: `executeUnitTest` read a
                // top-level `rite` through readRiteHint(); `runTest` reads only `calendar.rite`.
                'rite'     => 'calendar.rite replaces it.'
            ]
        ]
    ];

    /**
     * The response formats {@see Health::validateCalendar()} has a validation branch for.
     *
     * Narrower than {@see ReturnTypeParam}, on purpose: `ReturnTypeParam::from()` throws a
     * `\ValueError` on anything it does not know, and a `\ValueError` is an `\Error`, which Ratchet's
     * `IoServer::handleData` does not catch — so an unusable format on a reshaped message would kill
     * the whole WebSocket process rather than being answered. Same hazard {@see Health::cancelRun()}
     * documents, reached by a different door. A format outside this list is rejected instead.
     *
     * @var string[]
     */
    private const VALIDATABLE_RESPONSE_FORMATS = ['JSON', 'XML', 'ICS', 'YML'];

    /**
     * The shape a correlation identifier must have: 1 to 64 characters of `A-Za-z0-9_-`.
     *
     * One pattern for `runToken` and `requestId` alike, because they are the same kind of thing —
     * an opaque client-minted handle the server echoes back — and a client that minted one of them
     * must not have to discover that the other accepts a different alphabet. Junk is refused at the
     * door rather than echoed onto every frame of a run.
     *
     * **The `D` modifier is load-bearing, not decoration.** Without it PHP's `$` also matches *before*
     * a final newline, so `"req-alpha\n"` satisfies an alphabet that lists no newline, and 64
     * characters followed by one satisfy a `{1,64}` bound at 65 bytes. That latitude was pre-existing
     * on `runToken`; sharing the constant would have handed it to `requestId` as well, which is the
     * argument for fixing it here rather than living with it — one constant, one fix, both fields. The
     * docblock above describes the alphabet exactly, and now so does the pattern.
     */
    private const CORRELATION_ID_PATTERN = '/^[A-Za-z0-9_\-]{1,64}$/D';

    private const RED    = "\033[0;31m";
    private const GREEN  = "\033[0;32m";
    private const YELLOW = "\033[0;33m";
    private const BLUE   = "\033[0;34m";
    private const NC     = "\033[0m"; // No Color

    private static MetadataCalendars $metadata;

    private Client $http;

    private CurlMultiHandler $multiHandler;

    /**
     * Delay in seconds between batches of staggered cache responses.
     * Override via WS_STAGGER_INTERVAL env var.
     */
    private float $staggerInterval;
    private int $maxConcurrency;
    private int $inFlight = 0;
    /** @var list<array{url:string,options:array{headers?:array<string, string>,stream?:bool},resolve:\Closure(ResponseInterface):void,reject:\Closure(\Throwable):void,resourceId:int|null,runToken:string|null}> */
    private array $queue  = [];
    private bool $ticking = false;

    /**
     * Maximum number of outbound API requests to dispatch per rolling 1-second window.
     * Keeps the WS server under the public API's per-IP rate limit (nginx limit_req) without
     * relying on server-side IP exemptions. Override via WS_MAX_REQUEST_RATE env var.
     */
    private int $maxRequestRate;
    /** @var list<float> microtime(true) timestamps of dispatches within the trailing 1-second window */
    private array $dispatchTimes       = [];
    private bool $rateLimitTimerActive = false;

    private static bool $cacheInitialized = false;
    private static bool $cacheEnabled     = false;
    private static string $cacheBackend   = 'none';
    private static ?\Redis $redis         = null;
    /** @var array<int, int> Per-connection cache hit counters, keyed by resourceId */
    private array $cacheHitCounters = [];
    /** @var array<int, string> Per-connection run tokens, keyed by resourceId */
    private array $runTokens = [];
    //private static PromiseInterface $metadataPromise;

    private WebSocketMessageValidator $messageValidator;

    /**
     * Initializes the Health object with an empty SplObjectStorage.
     *
     * The SplObjectStorage is used to store client connections.
     */
    public function __construct()
    {
        $this->clients = new \SplObjectStorage();

        // Before anything that resolves a path. `Router::$apiFilePath` is a *typed* static, so
        // reading it before assignment is a fatal `Error`, not a null — and the validator below
        // resolves the message schema's path as it is constructed. `public/LitCalTestServer.php`
        // calls `new Health()` with nothing initialised, so without this the WebSocket server dies
        // at startup. No test caught it: PHPUnit's bootstrap initialises the paths long before any
        // test builds a Health, so every test ran an order production never uses.
        //
        // **Guarded, and the guard is load-bearing.** Calling `getApiPaths()` unconditionally
        // *overwrites* the path, and several tests deliberately point it at a fixture tree before
        // building a Health — see `BrokenInventoryTrait`. Stomping that override made a passing
        // suite fail. `isset()` on an uninitialised typed static is false and does not throw, which
        // is exactly the "initialise only if nobody has" test needed here.
        if (false === isset(Router::$apiFilePath)) {
            Router::getApiPaths();
        }

        // A server that cannot validate is misconfigured, and should fail here — where an operator
        // sees it, before a client ever connects — rather than answering every message with an
        // internal error. See {@see WebSocketMessageValidator::warm()}.
        $this->messageValidator = new WebSocketMessageValidator();
        $this->messageValidator->warm();

        // Create shared multi handler
        $multiHandler       = new CurlMultiHandler(['max_handles' => 50]);
        $this->multiHandler = $multiHandler;

        $stack = HandlerStack::create($this->multiHandler);

        $this->http = new Client([
            'handler'         => $stack,
            'timeout'         => 60,
            'connect_timeout' => 5,
            'http_errors'     => false,
            'headers'         => [ 'Connection' => 'keep-alive' ],
            'curl'            => [ CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0 ]
        ]);

        if (isset($_ENV['WS_MAX_CONCURRENCY']) && is_numeric($_ENV['WS_MAX_CONCURRENCY'])) {
            $this->maxConcurrency = max(1, (int) $_ENV['WS_MAX_CONCURRENCY']);
        } elseif (Router::isLocalhost() || ( isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development' )) {
            $this->maxConcurrency = 4;
        } else {
            $this->maxConcurrency = 10; // Conservative default for production
        }

        $this->staggerInterval = isset($_ENV['WS_STAGGER_INTERVAL']) && is_numeric($_ENV['WS_STAGGER_INTERVAL'])
            ? max(0.01, (float) $_ENV['WS_STAGGER_INTERVAL'])
            : 0.05;

        // Cap outbound requests to stay under the public API's per-IP rate limit (nginx limit_req).
        $this->maxRequestRate = isset($_ENV['WS_MAX_REQUEST_RATE']) && is_numeric($_ENV['WS_MAX_REQUEST_RATE'])
            ? max(1, (int) $_ENV['WS_MAX_REQUEST_RATE'])
            : 3;
    }

    /**
     * Called when a new client connection is established.
     *
     * This stores the new connection to send messages to later.
     */
    public function onOpen(ConnectionInterface $conn): void
    {
        // Store the new connection to send messages to later
        $this->clients[$conn] = null;
        if (false === is_int($conn->resourceId)) {
            echo 'Error onOpen: expected an integer resourceId, got ' . gettype($conn->resourceId) . "\n";
            return;
        } else {
            echo "New connection! ({$conn->resourceId}) and current working directory is " . getcwd() . "\n";
        }

        // Initialize Router paths before creating logger (LoggerFactory needs Router::$apiFilePath)
        Router::getApiPaths();

        // Initialize cache backend only once (not on every connection)
        // Note: This check-then-set pattern is safe because Ratchet/ReactPHP WebSocket
        // servers are single-threaded (event-loop based), so concurrent connections
        // are processed sequentially within the same process.
        if (!self::$cacheInitialized) {
            self::$cacheInitialized = true;

            // Create logger for cache initialization (no HTTP processors for WebSocket context)
            $logger = LoggerFactory::create('health', null, 30, false, true, false);

            // Try Redis first, fall back to APCu
            if (extension_loaded('redis')) {
                try {
                    self::$redis = new \Redis();
                    // Support Unix socket (REDIS_SOCKET) or TCP connection (REDIS_HOST/REDIS_PORT)
                    $redisSocket = isset($_ENV['REDIS_SOCKET']) && is_string($_ENV['REDIS_SOCKET'])
                        ? $_ENV['REDIS_SOCKET']
                        : null;
                    if ($redisSocket !== null && $redisSocket !== '') {
                        // Unix socket connection
                        $connected      = self::$redis->connect($redisSocket, 0, 2.0); // 2 second timeout
                        $connectionInfo = "socket: {$redisSocket}";
                    } else {
                        // TCP connection with configurable host/port
                        $redisHost      = isset($_ENV['REDIS_HOST']) && is_string($_ENV['REDIS_HOST'])
                            ? $_ENV['REDIS_HOST']
                            : '127.0.0.1';
                        $redisPort      = isset($_ENV['REDIS_PORT']) && is_numeric($_ENV['REDIS_PORT'])
                            ? (int) $_ENV['REDIS_PORT']
                            : 6379;
                        $connected      = self::$redis->connect($redisHost, $redisPort, 2.0); // 2 second timeout
                        $connectionInfo = "{$redisHost}:{$redisPort}";
                    }
                    if ($connected) {
                        // Optional authentication for production deployments
                        $redisPassword = isset($_ENV['REDIS_PASSWORD']) && is_string($_ENV['REDIS_PASSWORD'])
                            ? $_ENV['REDIS_PASSWORD']
                            : null;
                        if ($redisPassword !== null && $redisPassword !== '') {
                            try {
                                $authenticated = self::$redis->auth($redisPassword);
                                if (!$authenticated) {
                                    self::$redis = null;
                                    echo "Redis authentication failed, trying APCu fallback\n";
                                    $logger->warning('Redis authentication failed, trying APCu fallback');
                                }
                            } catch (\RedisException $e) {
                                self::$redis = null;
                                echo "Redis auth exception: {$e->getMessage()}, trying APCu fallback\n";
                                $logger->warning('Redis auth exception, trying APCu fallback', ['error' => $e->getMessage()]);
                            }
                        }

                        // Verify connection is actually usable with a ping (if still connected)
                        if (self::$redis !== null) {
                            try {
                                self::$redis->ping();
                                self::$cacheEnabled = true;
                                self::$cacheBackend = 'redis';
                                echo "Redis connected ({$connectionInfo}), will use for caching\n";
                                $logger->info('Redis connected, will use for caching', ['connection' => $connectionInfo]);
                            } catch (\RedisException $e) {
                                self::$redis = null;
                                echo "Redis ping failed: {$e->getMessage()}, trying APCu fallback\n";
                                $logger->warning('Redis ping failed, trying APCu fallback', ['error' => $e->getMessage()]);
                            }
                        }
                    } else {
                        self::$redis = null;
                        echo "Redis connection failed, trying APCu fallback\n";
                        $logger->warning('Redis connection failed, trying APCu fallback');
                    }
                } catch (\RedisException $e) {
                    self::$redis = null;
                    echo "Redis exception: {$e->getMessage()}, trying APCu fallback\n";
                    $logger->warning('Redis exception, trying APCu fallback', ['error' => $e->getMessage()]);
                }
            }

            // Fall back to APCu if Redis not available
            if (self::$cacheBackend === 'none') {
                $apcuAvailable = extension_loaded('apcu')
                    && function_exists('apcu_exists')
                    && function_exists('apcu_store')
                    && function_exists('apcu_fetch');
                if ($apcuAvailable) {
                    self::$cacheEnabled = true;
                    self::$cacheBackend = 'apcu';
                    echo "APCu extension loaded, will use for caching\n";
                    $logger->info('APCu extension loaded, will use for caching');
                } else {
                    echo "No cache backend available (Redis and APCu both unavailable)\n";
                    $logger->warning('No cache backend available (Redis and APCu both unavailable)');
                }
            }
        }

        if (false === isset(self::$metadata)) {
            echo 'Metadata not yet loaded, loading now from ' . Route::CALENDARS->path() . "\n";

            $opts = [
                'headers' => [
                    'Accept' => 'application/json'
                ]
            ];

            // #833 note: this internal /calendars fetch is deliberately left ungated on status. It
            // never emits a step frame — it is metadata bootstrapping, not a check reported to a
            // client — and the shape guards below (instanceof \stdClass, then the litcal_metadata
            // property) already treat a body that doesn't decode into the expected metadata shape,
            // which is exactly what a problem-document body would produce, as "not loaded yet": self::
            // $metadata is simply left unset and the next request retries. Nothing here claims success
            // for a refusal, so there is no false "exists" for #833 to fix.
            /** @var PromiseInterface<array{data: string, status: int, fromCache: bool, retryAfter: ?string}> $metadataPromise */
            $metadataPromise = $this->cachedGet(Route::CALENDARS->path(), $opts, 300, $conn);
            //self::$metadataPromise = $metadataPromise;

            $metadataPromise->then(
                function (array $result) {
                    /** @var array{data: string, status: int, fromCache: bool, retryAfter: ?string} $result */
                    $rawData = $result['data'];
                    echo 'Fetched metadata: got ' . strlen($rawData) . " bytes\n";

                    $metadataObj = json_decode($rawData);

                    if (false === ( $metadataObj instanceof \stdClass )) {
                        echo 'Error loading metadata: expected stdClass, got ' . gettype($metadataObj) . "\n";
                        return;
                    }

                    if (JSON_ERROR_NONE !== json_last_error()) {
                        echo 'Error loading metadata: ' . json_last_error_msg() . "\n";
                        return;
                    }

                    echo "Loaded metadata\n";

                    $litCalMetadata = $metadataObj->litcal_metadata;
                    if (false === ( $litCalMetadata instanceof \stdClass )) {
                        echo 'Error loading metadata: expected stdClass, got ' . gettype($litCalMetadata) . "\n";
                        return;
                    }
                    self::$metadata = MetadataCalendars::fromObject($litCalMetadata);
                },
                function (\Throwable $e) {
                    echo 'Error reading metadata: could not read data from ' . Route::CALENDARS->path() . ': ' . $e->getMessage() . "\n";
                }
            );
        } else {
            if (isset(self::$metadata->diocesan_calendars) && false === empty(self::$metadata->diocesan_calendars)) {
                echo "Metadata was already loaded and has required diocesan_calendars property\n";
            } else {
                echo "Error loading metadata: missing diocesan_calendars property\n";
                echo json_encode(self::$metadata, JSON_PRETTY_PRINT);
            }
        }
    }

    /**
     * Handle an incoming message.
     *
     * This function is called whenever a user sends a message to the WebSocket
     * server. It is responsible for parsing the message, validating it, and then
     * executing the action specified.
     *
     * @param ConnectionInterface $from The user who sent the message
     * @param string $msg The message that was sent
     */
    public function onMessage(ConnectionInterface $from, $msg): void
    {
        /** @var int $resourceId */
        $resourceId = $from->resourceId;
        // Reset per-connection cache hit counter for each new message (test run)
        $this->cacheHitCounters[$resourceId] = 0;
        echo sprintf('Receiving message from connection %d: %s', $resourceId, $msg . "\n");
        /** @var ExecuteValidationSourceFolder|ExecuteValidationSourceFile|ExecuteValidationResource|ValidateCalendar|ValidateTypedCalendar|ExecuteUnitTest|RunTest|CancelRun|ValidateSource $messageReceived */
        $messageReceived = json_decode($msg);
        // Store optional run token for response correlation. `cancelRun` is exempt: it names the run it
        // wants abandoned rather than the run this connection is on, and storing it here would install
        // the very token cancelRun() is about to clear — making even a stale cancel match, and dropping
        // the queue of the run that replaced it.
        if (
            $messageReceived instanceof \stdClass
            && property_exists($messageReceived, 'action')
            && $messageReceived->action !== 'cancelRun'
            && property_exists($messageReceived, 'runToken')
            && is_string($messageReceived->runToken)
            && preg_match(self::CORRELATION_ID_PATTERN, $messageReceived->runToken)
        ) {
            // A token this connection was not already on is a run *beginning*, and that is the one
            // moment the inventory may safely be rebuilt: `validateSource` addresses source data
            // solely through `CheckableInventory::byId()`, whose index is memoized for the lifetime
            // of this process rather than of a request, so a calendar added through `/data` would
            // otherwise stay unaddressable until the WebSocket server restarts. A write-path hook
            // cannot close that gap — `/data` writes happen in the HTTP process, which never runs
            // this code. Resetting on the token *change* rather than on every message bounds
            // staleness to one run while still costing one rebuild per run, not one per check;
            // a run issues one message per checked item, and there are dozens.
            //
            // Known bound, not an oversight: a *new* run that reuses the previous run's token on
            // the same connection reads as a continuation and skips the reset. Tokens are minted
            // per run by the client and onClose() drops the entry, so this is theoretical — but if
            // it ever stops being, the fix is a run-start signal in the protocol, not a reset here
            // on every message.
            //
            // Second bound, worth knowing before relying on this: `runToken` is optional, and a
            // client that omits it never reaches this block at all — so it never resets, and never
            // sees a calendar written through `/data` until the server restarts. The reset gives
            // freshness to clients that opt into run correlation; a tokenless client opts out of
            // both together.
            if (( $this->runTokens[$resourceId] ?? null ) !== $messageReceived->runToken) {
                CheckableInventory::reset();
            }
            $this->runTokens[$resourceId] = $messageReceived->runToken;
        }

        // The correlation id the client maps this request's answers by, read here and threaded
        // downward **as a value**. It is deliberately not stored per connection the way `runToken`
        // just was, and the difference is not a matter of taste: `runToken` scopes a *run*, so the
        // connection's current token is the right answer for any frame it emits, while `requestId`
        // scopes a single *request*. Health is asynchronous — frames are emitted from promise
        // closures long after `onMessage()` has returned, and `$this->queue` / `$this->inFlight`
        // exist precisely because several requests are in flight on one connection at once. A
        // per-connection "current requestId" would therefore stamp a late-arriving frame with
        // whichever request arrived most recently, which is the exact misattribution correlation
        // was added to prevent. Each handler captures the value it was called with in its closures.
        //
        // Read *after* the run token is stored, so that a message rejected below still carries the
        // token of the run the client says it is on.
        $requestId = null;
        if ($messageReceived instanceof \stdClass && property_exists($messageReceived, 'requestId')) {
            $candidateRequestId = $messageReceived->requestId;
            if (false === is_string($candidateRequestId) || 1 !== preg_match(self::CORRELATION_ID_PATTERN, $candidateRequestId)) {
                // Refused rather than quietly dropped. A client that sent an unusable id is about
                // to wait for frames it intends to attribute by it; answering with frames that
                // carry no id at all would look like success and correlate nothing.
                $this->rejectMessage($from, ProtocolErrorCode::INVALID_REQUEST_ID, 'requestId must be 1 to 64 characters of A-Z, a-z, 0-9, underscore or hyphen.');
                return;
            }
            $requestId = $candidateRequestId;
        }

        if (
            json_last_error() === JSON_ERROR_NONE
            && $messageReceived instanceof \stdClass
            && property_exists($messageReceived, 'action')
        ) {
            // `UNKNOWN_ACTION` must be checked ahead of schema validation, not derived from it: an
            // unrecognised action matches no arm of the schema's top-level `oneOf`, so validating
            // first would report it as `INVALID_MESSAGE` and collapse two error codes a client acts
            // on differently into one. See #806 section G.
            if (false === is_string($messageReceived->action)) {
                $this->rejectMessage($from, ProtocolErrorCode::INVALID_MESSAGE, 'action must be a string naming a known action.', requestId: $requestId);
                return;
            }
            if (null === WebSocketMessageValidator::shapeOf($messageReceived)) {
                // The connection id has no reader on the wire; it is server-internal state and
                // belongs in the log line, not in prose a client has to parse or display.
                echo sprintf('Unknown action from connection %1$d: %2$s', $resourceId, $msg);
                $this->rejectMessage(
                    $from,
                    ProtocolErrorCode::UNKNOWN_ACTION,
                    sprintf('Unknown action: %s', $msg),
                    requestId: $requestId
                );
                return;
            }

            // Deferred rather than reported here: `rejectRetiredProperties()` runs inside each v2
            // handler and can name what a retired property was replaced by, which this validator
            // cannot. See {@see Health::RETIRED_PROPERTIES} and {@see Health::rejectRetiredProperties()}.
            $retiredForAction = array_keys(self::RETIRED_PROPERTIES[$messageReceived->action]['retired'] ?? []);
            $invalid          = $this->messageValidator->validate($messageReceived, $retiredForAction);
            if (null !== $invalid) {
                echo sprintf('Invalid message from connection %1$d: %2$s (%3$s)', $resourceId, $invalid, $msg);
                $this->rejectMessage($from, ProtocolErrorCode::INVALID_MESSAGE, $invalid, requestId: $requestId);
                return;
            }
        }

        if (
            json_last_error() === JSON_ERROR_NONE
            && $messageReceived instanceof \stdClass
            && property_exists($messageReceived, 'action')
        ) {
            try {
                switch ($messageReceived->action) {
                    case 'executeValidation':
                        /** @var ExecuteValidationSourceFolder|ExecuteValidationSourceFile|ExecuteValidationResource $messageReceived */
                        $this->executeValidation($messageReceived, $from, requestId: $requestId);
                        break;
                    case 'validateCalendar':
                        // Same action, two shapes; see isTypedCalendarMessage(). The legacy arm below
                        // is untouched and stays reachable until UnitTestInterface#42 ships.
                        if (self::isTypedCalendarMessage($messageReceived)) {
                            /** @var ValidateTypedCalendar $messageReceived */
                            $this->validateTypedCalendar($messageReceived, $from, requestId: $requestId);
                            break;
                        }
                        /** @var ValidateCalendar $messageReceived */
                        // `year` is read through readYear() rather than trusted as `int`, even though
                        // the schema declares `"type": "integer"`: JSON Schema and PHP disagree about
                        // what that means. A number like 1e30 or a 30-digit literal is an integer *by
                        // value* under JSON Schema, so the schema accepts it correctly — but json_decode()
                        // hands PHP a float for it (it is outside PHP_INT_MAX), and this arm used to
                        // unpack that straight into validateCalendar(..., int $year, ...). PHP's
                        // coercive typing refuses an out-of-range float for an int parameter with a
                        // TypeError, an \Error Ratchet's IoServer::handleData does not catch, so it took
                        // the whole process down. readYear()'s is_int() check is exactly the guard the
                        // typed arms (validateTypedCalendar(), runTest()) already apply for the same
                        // reason; the schema cannot close this gap, because the schema is right.
                        try {
                            $year = self::readYear($messageReceived, 'validateCalendar');
                        } catch (\InvalidArgumentException $e) {
                            $this->rejectMessage($from, ProtocolErrorCode::INVALID_MESSAGE, $e->getMessage(), requestId: $requestId);
                            break;
                        }
                        $this->validateCalendar(
                            $messageReceived->calendar,
                            $year,
                            $messageReceived->category,
                            $messageReceived->responsetype,
                            $from,
                            self::readRiteHint($messageReceived),
                            requestId: $requestId
                        );
                        break;
                    case 'executeUnitTest':
                        /** @var ExecuteUnitTest $messageReceived */
                        // See the comment on the validateCalendar arm above: same hazard, same fix.
                        try {
                            $year = self::readYear($messageReceived, 'executeUnitTest');
                        } catch (\InvalidArgumentException $e) {
                            $this->rejectMessage($from, ProtocolErrorCode::INVALID_MESSAGE, $e->getMessage(), requestId: $requestId);
                            break;
                        }
                        $this->executeUnitTest(
                            $messageReceived->test,
                            $messageReceived->calendar,
                            $year,
                            $messageReceived->category,
                            $from,
                            self::readRiteHint($messageReceived),
                            requestId: $requestId
                        );
                        break;
                    case 'runTest':
                        /** @var RunTest $messageReceived */
                        $this->runTest($messageReceived, $from, requestId: $requestId);
                        break;
                    case 'cancelRun':
                        /** @var CancelRun $messageReceived */
                        $this->cancelRun($messageReceived->runToken, $from);
                        break;
                    case 'validateSource':
                        /** @var ValidateSource $messageReceived */
                        $this->validateSource($messageReceived, $from, requestId: $requestId);
                        break;
                    default:
                        // Unreachable: the block above already returned for any action
                        // `WebSocketMessageValidator::shapeOf()` does not recognise, and it recognises
                        // exactly the actions the case arms above handle. Kept as a cheap backstop
                        // against a future action being added to `shapeOf()` without a matching arm here.
                        //
                        // The connection id has no reader on the wire; it is server-internal state
                        // and belongs in the log line, not in prose a client has to parse or display.
                        echo sprintf('Unknown action from connection %1$d: %2$s', $resourceId, $msg);
                        $this->rejectMessage(
                            $from,
                            ProtocolErrorCode::UNKNOWN_ACTION,
                            sprintf('Unknown action: %s', $msg),
                            requestId: $requestId
                        );
                }
            } catch (\Throwable $e) {
                // Ratchet's IoServer::handleData catches \Exception, and TypeError, ValueError and
                // Error are not \Exception — so without this, anything a handler throws terminates
                // the process for every connected client rather than failing one request.
                //
                // Schema validation stands in front of this and is the real gate. The backstop is
                // here because a process-wide crash must not depend on a schema file being correct.
                // A catch-all can mask a bug, which is why this logs before it answers: the log line
                // is what keeps the masked bug findable.
                //
                // It does NOT cover #823. Those throws happen inside promise callbacks, after this
                // method has returned, where no try around the dispatch can see them.
                echo sprintf(
                    "Uncaught %s handling %s from connection %d: %s\n",
                    get_class($e),
                    (string) $messageReceived->action,
                    $resourceId,
                    $e->getMessage()
                );
                $this->rejectMessage(
                    $from,
                    ProtocolErrorCode::INTERNAL_ERROR,
                    'The server failed while handling this message. This is a bug; the run may be incomplete.',
                    requestId: $requestId
                );
            }
        } else {
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errorMsg  = json_last_error_msg();
                $errorCode = ProtocolErrorCode::INVALID_JSON;
            } elseif (!$messageReceived instanceof \stdClass) {
                $errorMsg  = 'Message is not an object';
                $errorCode = ProtocolErrorCode::NOT_AN_OBJECT;
            } elseif (!property_exists($messageReceived, 'action')) {
                $errorMsg  = 'No action specified';
                $errorCode = ProtocolErrorCode::MISSING_ACTION;
            } else {
                $errorMsg  = 'Unknown error';
                $errorCode = ProtocolErrorCode::INVALID_MESSAGE;
            }
            echo sprintf('Invalid message from connection %1$d: %2$s (%3$s)', $resourceId, $errorMsg, $msg);
            // The connection id above is server-internal state and stays in the log line; the
            // client gets the same reason and raw message, without it — it has no reader on the wire.
            $this->rejectMessage(
                $from,
                $errorCode,
                sprintf('Invalid message: %1$s (%2$s)', $errorMsg, $msg),
                requestId: $requestId
            );
        }
    }

    /**
     * Handles the closure of a connection.
     *
     * This method is invoked when a connection is closed.
     * It detaches the connection from the clients list and
     * logs a message indicating the disconnection.
     *
     * @param ConnectionInterface $conn The connection that was closed.
     * @return void
     */
    public function onClose(ConnectionInterface $conn): void
    {
        /** @var int $resourceId */
        $resourceId = $conn->resourceId;
        // The connection is closed, remove it, as we can no longer send it messages
        unset($this->clients[$conn]);
        unset($this->cacheHitCounters[$resourceId]);
        unset($this->runTokens[$resourceId]);
        echo "Connection {$resourceId} has disconnected\n";
    }

    /**
     * Handles errors that occur on a connection.
     *
     * Logs the error message and closes the connection.
     *
     * @param ConnectionInterface $conn The connection on which the error occurred
     * @param \Throwable $e The exception that was thrown
     */
    public function onError(ConnectionInterface $conn, \Throwable $e): void
    {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }

    /**
     * Sends a message to a client.
     *
     * Only the client that sent the original message will receive the response.
     *
     * @param ConnectionInterface $from The client that sent the original message.
     * @param string|\stdClass $msg The message to send back to the client.
     * @param string|null $runToken The originating request's run token; falls back to the per-connection stored token when null.
     * @param string|null $requestId The correlation id of the request that caused this frame, echoed back so a client can
     *        attribute the frame to its own state without parsing a CSS selector. Always passed in by the caller, from the
     *        value it captured when the request arrived; there is deliberately no per-connection fallback, because the
     *        connection's most recent request is not necessarily the one this frame answers. See {@see Health::onMessage()}.
     */
    private function sendMessage(ConnectionInterface $from, string|\stdClass $msg, ?string $runToken = null, ?string $requestId = null): void
    {
        /** @var int $resourceId */
        $resourceId = $from->resourceId;
        $token      = $runToken ?? ( $this->runTokens[$resourceId] ?? null );
        if ($msg instanceof \stdClass && $token !== null) {
            // `runId` is the same value under the name the protocol is moving to: published now, and
            // alongside `runToken` rather than instead of it, so UnitTestInterface#42 adopts the new
            // spelling in the same migration as everything else and never has to do it twice.
            $msg->runToken = $token;
            $msg->runId    = $token;
        }
        if ($msg instanceof \stdClass && null !== $requestId) {
            $msg->requestId = $requestId;
        }
        if (gettype($msg) !== 'string') {
            $msg = json_encode($msg, JSON_PRETTY_PRINT);
        }
        /** @var string $msg */
        $from->send($msg);
    }

    /**
     * Emit the result frame for one step of one check.
     *
     * The frame says what it is about structurally — `target`, `step`, `status` — and the legacy
     * `type` / `text` / `classes` are *derived* from that here rather than written out at each call
     * site. Until #806 a frame's only statement of its subject was `classes`, a CSS selector the
     * server built and the browser matched with `querySelectorAll()`, so attribution was string
     * matching and the server had to know Bootstrap existed.
     *
     * The legacy trio is assigned first and in the order every shipped frame has used, because the
     * clients that match on it are still out there: this is additive, and a v1 frame must come out
     * byte-identical.
     *
     * @param ConnectionInterface $to The connection to send the frame to.
     * @param string $classFragment The CSS class fragment the frame is addressed by, without the leading dot
     *        and without the trailing step; see {@see Health::cssClassFragmentForId()}.
     * @param ?\stdClass $target What the frame is about, as an object rather than a bare id: always an `id`, plus
     *        whatever else identifies the thing checked — see {@see Health::frameTarget()}. Null for a v1
     *        `executeValidation` message, which names no id. Never fabricated from the class fragment.
     * @param Step $step The step being reported. A step the family has no class for cannot be addressed and is
     *        refused by {@see FrameFamily::frameClasses()} rather than emitted: {@see Step::COMPLETE} is the case
     *        that exists today — {@see Health::sendComplete()} composes the terminal frame itself and routes
     *        around this method rather than relaxing that refusal (#821) — and a case added later would
     *        otherwise ship a `classes` of `.<fragment>.`, which matches zero cards — the silent mismatch this
     *        whole projection exists to end.
     * @param Status $status The outcome, which `type` is projected from.
     * @param string $text The human-facing message, passed through untouched.
     * @param ?list<string> $details The individual failures behind a `text` that summarises them, or null when there
     *        are none. Omitted from the frame when empty, so a client need not tell "no details" from "none given".
     *        The rule, which Tasks 2 and 3 carry forward: this carries structured data wherever the emitter already
     *        holds it — a folder check's per-file errors, a schema failure's two parts — and is never manufactured
     *        for a site that genuinely has nothing to say.
     * @param ?string $runToken The originating run token to echo back, or null to use the per-connection fallback.
     * @param ?string $classQualifier One further class segment, without the leading dot, or null when the address is
     *        the fragment and the step alone. Where it sits is the family's business, not the caller's: a calendar
     *        validation is `.calendar-{id}.{step}.year-{year}` and a test run is `.{test}.year-{year}.test-valid`, so
     *        the same year segment goes after the step in one grammar and before it in the other. Callers pass
     *        segments, never selectors and never positions — the dots, the ordering and the step class are this
     *        method's business, which is the whole point of the projection.
     * @param FrameFamily $family Which of the two legacy class grammars this frame belongs to; see {@see FrameFamily}.
     *        It selects the step's class and the place `$classQualifier` takes, so that `test-valid` and a
     *        year-before-step address need neither an override argument nor a second composing site. Defaults to
     *        `CHECK`, which is every frame the source-data and calendar clusters emit.
     * @param ?string $responseType The `responsetype` a calendar-validation failure frame has always carried, or null
     *        for every other frame. It belongs to the legacy half and is assigned with it, immediately after
     *        `classes`, so a v1 frame's key order is byte-for-byte what it has always been and only the new
     *        structured keys follow.
     * @param ?string $requestId The correlation id of the request that caused this frame, captured by the handler when
     *        the request arrived and passed explicitly rather than read from any per-connection store; see
     *        {@see Health::onMessage()} for why that distinction is load-bearing.
     */
    private function sendStepResult(
        ConnectionInterface $to,
        string $classFragment,
        ?\stdClass $target,
        Step $step,
        Status $status,
        string $text,
        ?array $details = null,
        ?string $runToken = null,
        ?string $classQualifier = null,
        FrameFamily $family = FrameFamily::CHECK,
        ?string $responseType = null,
        ?string $requestId = null
    ): void {
        // `classes` is composed by the family, not here: `frameClasses()` is the only selector composer in
        // the repository, and it is on `FrameFamily` rather than in this class because `LitTestRunner`
        // emits an address of the same grammar and must not own a second copy of it.
        $message          = new \stdClass();
        $message->type    = Status::PASS === $status ? 'success' : 'error';
        $message->text    = $text;
        $message->classes = $family->frameClasses($classFragment, $step, $classQualifier);
        if (null !== $responseType) {
            $message->responsetype = $responseType;
        }
        $message->target = $target;
        $message->step   = $step->value;
        $message->status = $status->value;
        if (null !== $details && [] !== $details) {
            $message->details = $details;
        }
        $this->sendMessage($to, $message, $runToken, requestId: $requestId);
    }

    /**
     * Emit the terminal frame: the work this request started has finished.
     *
     * A client stops on this frame instead of counting the ones before it. Both clients hardcode
     * three responses per check, and when this frame was added the published `steps` list could not
     * replace that constant because the count was not reliable — a file whose JSON failed to decode
     * emitted two step frames, not three (#821, since fixed). Making the end explicit did not fix
     * that; it made it stop mattering, and it still does: an arm that stops early is a possibility
     * the protocol should not need a client to predict.
     *
     * **Gated on `requestId`, and this is the one place in #806 where the additive envelope is not
     * enough.** A new frame changes the *stream*, not just a frame's contents. A v1 client survives
     * an unknown shape — `testResults.js` warns on a missing `classes` and carries on — but
     * `resources.js` sizes a phase as `checks * 3` and advances on `>=`, so it reaches its threshold
     * on the three real frames and the terminal frame then increments whichever counter is active by
     * then, finishing the *following* phase early too. A cascading miscount with nothing visibly
     * failing, which is worse than a crash. `requestId` is already the v2 opt-in signal and a client
     * adopting `complete` is adopting correlation anyway, so the gate costs a v2 client nothing and
     * keeps the byte-identity guarantee whole. The gate lives here rather than at the twelve call
     * sites so that it cannot be forgotten at a thirteenth.
     *
     * **No `status`.** The frame reports that the work finished, not that it passed; a run whose every
     * step failed still completes. The outcome is on the step frames, which is where a client reads it.
     *
     * **No `classes`, and therefore not built through {@see Health::sendStepResult()}.** There is no
     * legacy class for a step that never existed in the legacy protocol, and
     * {@see FrameFamily::frameClasses()} refuses {@see Step::COMPLETE} outright rather than inventing
     * one — a frame classed `.<fragment>.` matches zero cards, which is the silent mismatch the whole
     * projection exists to end. So this composes its own frame and goes straight to the funnel. That
     * refusal is deliberate and is pinned by a test; it is routed around here, never relaxed there.
     *
     * @param ConnectionInterface $to The connection that asked for the work.
     * @param ?\stdClass $target What was checked, per {@see Health::frameTarget()} — the same target its step frames
     *        carried, so a client attributes the terminal frame the same way it attributed the rest. Null where the
     *        step frames carried none: a v1 `executeValidation` message names no id, and nothing is fabricated for it.
     * @param ?string $runToken The originating run token to echo back, or null to use the per-connection fallback.
     * @param ?string $requestId The correlation id of the request that has finished. Null means the client never opted
     *        in, and nothing is sent at all — see above.
     */
    private function sendComplete(
        ConnectionInterface $to,
        ?\stdClass $target,
        ?string $runToken = null,
        ?string $requestId = null
    ): void {
        if (null === $requestId) {
            return;
        }

        $message         = new \stdClass();
        $message->type   = 'success';
        $message->text   = 'All checks for this request are complete';
        $message->target = $target;
        $message->step   = Step::COMPLETE->value;
        $this->sendMessage($to, $message, $runToken, requestId: $requestId);
    }

    /**
     * Reject a message that cannot be acted on, saying why in a form a client can branch on.
     *
     * Ungated, unlike the terminal `complete` frame, and the difference is real rather than an
     * inconsistency: a new frame changes the stream a v1 client counts, while a new *type* on a
     * frame it was already going to receive changes nothing for it. Since UnitTestInterface#46 an
     * unrecognised type is painted as a visible failed check — which is what `echobot` already
     * became — so `protocolError` reads to a v1 client exactly as its predecessor did.
     *
     * `text` carries the prose, as every other frame in this protocol does. #806's sketch spells it
     * `message`; a second name for an existing field is the duplication that issue exists to remove.
     */
    private function rejectMessage(ConnectionInterface $to, ProtocolErrorCode $code, string $text, ?string $requestId = null): void
    {
        $message            = new \stdClass();
        $message->type      = 'protocolError';
        $message->errorCode = $code->value;
        $message->text      = $text;
        $this->sendMessage($to, $message, requestId: $requestId);
    }

    /**
     * Capture the run token currently associated with a connection. Called synchronously at the
     * start of each request handler (before any async work), so the value is the originating
     * request's token; that token is then threaded into the handler's async responses so they
     * are stamped correctly even after a later run overwrites the per-connection stored token.
     */
    private function resolveRunToken(ConnectionInterface $conn): ?string
    {
        return is_int($conn->resourceId) ? ( $this->runTokens[$conn->resourceId] ?? null ) : null;
    }

    /**
     * Abandon a run: forget its token, so its queued requests stop being work worth doing.
     *
     * Sent by the client when the user stops a run, so the server does not keep fetching calendars and
     * validating files for a run nobody is watching. Only the queued backlog is dropped — requests
     * already in flight are capped at maxConcurrency and their frames are discarded client-side, so
     * chasing them would buy little for a great deal of plumbing.
     *
     * The token must match what this connection is currently running. A cancel naming a run the
     * connection has already left — the user stopped and restarted faster than the frame travelled —
     * is a no-op: acting on it would clear the token of the run that *replaced* it and drop that run's
     * queue instead, which is a worse bug than the one this fixes.
     *
     * Nothing is sent back; see #806 section H.
     *
     * `WebSocketMessageValidator` now rejects a non-string `runToken` — `cancelRun`'s schema entry
     * types it via `#/definitions/correlationId` — before dispatch ever reaches here. `$runToken` is
     * kept `mixed` and re-checked below anyway: a cancel the server cannot act on is already a
     * documented no-op (see above), so treating an unexpected type as "not this connection's run"
     * rather than trusting the schema is the cheaper of two ways to stay correct, and costs nothing.
     *
     * @param mixed $runToken The run the client wants abandoned. Expected to be a string; re-checked
     *                        here rather than trusted, for the reason above.
     * @param ConnectionInterface $from The connection that asked.
     */
    private function cancelRun(mixed $runToken, ConnectionInterface $from): void
    {
        if (false === is_string($runToken)) {
            return;
        }
        $resourceId = $from->resourceId;
        if (false === is_int($resourceId) || ( $this->runTokens[$resourceId] ?? null ) !== $runToken) {
            return;
        }
        unset($this->runTokens[$resourceId]);
        $this->dropSupersededQueuedRequests();
    }

    /**
     * Find diocese metadata by calendar ID.
     *
     * @param string $calendarId The diocese calendar ID to look up.
     * @return MetadataDiocesanCalendarItem The diocese metadata.
     * @throws \RuntimeException If metadata is not loaded yet.
     * @throws NotFoundException If no diocese is found for the given calendar ID.
     */
    private function findDioceseMetadata(string $calendarId): MetadataDiocesanCalendarItem
    {
        if (false === isset(self::$metadata)) {
            throw new \RuntimeException('Metadata not loaded yet; it is fetched asynchronously on WebSocket connection');
        }
        $dioceseMetadata = array_find(
            self::$metadata->diocesan_calendars,
            function (MetadataDiocesanCalendarItem $el) use ($calendarId): bool {
                return $el->calendar_id === $calendarId;
            }
        );
        if ($dioceseMetadata === null) {
            throw new NotFoundException("No diocese found for calendar id: {$calendarId}");
        }
        return $dioceseMetadata;
    }

    /**
     * Check one source-data artifact, named by the id `GET /validations` published for it.
     *
     * This is the addressing half of {@see Health::executeValidation()}, done the other way round.
     * A v1 message carries a hyphenated `validate` slug that the server has to recover a path and a
     * schema from, through eight anchored patterns that are each a second copy of a naming
     * convention written down somewhere else — which is the drift #806 exists to remove. An id is
     * opaque: the server minted it, published it, and looks it up. One lookup, no grammar.
     *
     * Nothing here is subtracted from `executeValidation()`; both address the same execution phase,
     * and the slug arms stay reachable until clients have moved over (UnitTestInterface#42). What is
     * refused is a message that tries to be both: `category`, `validate`, `sourceFile` and
     * `sourceFolder` are the four properties an id replaces outright, so carrying one alongside a
     * `target` is a half-migrated client naming its check twice and being read once. See
     * {@see Health::RETIRED_PROPERTIES}.
     *
     * The six-argument call is deliberate: an inventory entry is a source-data check whose data path
     * and quoted folder are the same path, and whose schema was resolved from its own id — exactly
     * the three defaults {@see Health::runValidationSteps()} supplies.
     *
     * @param ValidateSource $message The message naming the target to check.
     * @param ConnectionInterface $to The connection to send the result frames to.
     * @param ?string $requestId The correlation id this request's frames must carry back, captured by the caller.
     */
    private function validateSource(\stdClass $message, ConnectionInterface $to, ?string $requestId = null): void
    {
        if ($this->rejectRetiredProperties($message, 'validateSource', $to, requestId: $requestId)) {
            return;
        }

        // `WebSocketMessageValidator` has already established that `target` is a `{id: string}`
        // object, per the `validateSource` shape in `WebSocketMessage.json`. Re-checked here anyway,
        // rather than trusted, so this method reads correctly on its own and stays correct if the
        // schema and the dispatch ever drift.
        $target = property_exists($message, 'target') ? $message->target : null;
        if (false === ( $target instanceof \stdClass ) || false === property_exists($target, 'id')) {
            $this->rejectMessage($to, ProtocolErrorCode::INVALID_MESSAGE, 'validateSource requires a target object with an id.', requestId: $requestId);
            return;
        }

        $id = $target->id;
        if (false === is_string($id)) {
            $this->rejectMessage($to, ProtocolErrorCode::INVALID_MESSAGE, 'validateSource target id must be a string.', requestId: $requestId);
            return;
        }

        $inventoryError = null;
        try {
            $item = CheckableInventory::byId($id);
        } catch (\Throwable $e) {
            // The same containment, and the same static-half retry, as in getPathToSchemaFile() and
            // retrieveSchemaForCategory(): building the index reads and parses every calendar source
            // file, so one malformed file must not cost every other check in a process that stays
            // up. It matters more here than there, because an exception escaping onMessage() is
            // caught by Ratchet's IoServer, which closes the client's connection mid-run — the
            // whole run lost to one unreadable file.
            $inventoryError = $e;
            $item           = CheckableInventory::staticById($id);
        }

        if (null === $item) {
            // The two misses are not the same thing and must not read as though they were: an id
            // nobody published is a client bug, while an id that could not be looked up is a server
            // one, and reporting the second as "unknown" would send the reader hunting the wrong
            // one — the #800 blindness in miniature.
            $this->rejectMessage(
                $to,
                ProtocolErrorCode::UNKNOWN_TARGET_ID,
                null === $inventoryError
                    ? "Unknown validation target: {$id}"
                    : "Could not resolve validation target {$id}: the source data inventory could not be built ({$inventoryError->getMessage()})",
                requestId: $requestId
            );
            return;
        }

        $this->runValidationSteps(
            $item->path,
            $item->kind,
            $item->schema->path(),
            $item->label,
            $to,
            $this->resolveRunToken($to),
            // The label is prose — `National calendar: US` — and is fine as the human half of a
            // frame, but it is not a selector. The address comes from the id instead.
            classFragment: self::cssClassFragmentForId($item->id),
            // The id itself rides along on the frames, so a client attributes a result by the
            // value it asked with rather than by unpicking the selector the server built.
            target: self::frameTarget($item->id),
            requestId: $requestId
        );
    }

    /**
     * Build the `target` object a result frame names its subject with.
     *
     * An object, never a bare id, and the same shape for every cluster: `id` always, plus whatever
     * else it takes to identify the thing that was checked. A source check is identified by its
     * inventory id alone; a calendar validation is a calendar *and* a year, and a test run is a test,
     * a calendar and a year. A bare string could only ever carry the first of those, and widening it
     * later would be a breaking change for every client that had learned to read it.
     *
     * What this is **not** is a correlation id. `target` says what was checked; `requestId` (#806
     * section C) says which request asked, and identity is not duplicated in here to do that job.
     * That is why a calendar's target names the calendar and the year and stops: the category and
     * the rite are in scope at the emitter, and threading them through every call site to restate
     * something `requestId` will carry properly would buy nothing.
     *
     * @param string $id The stable id of the thing checked: an inventory id, a calendar id, a test name.
     * @param array<string, string|int> $extra The further fields that identify it, e.g. `['year' => 2024]`.
     */
    private static function frameTarget(string $id, array $extra = []): \stdClass
    {
        return (object) ['id' => $id, ...$extra];
    }

    /**
     * Derive the CSS class fragment a `validateSource` result frame is addressed by, from an inventory id.
     *
     * **The rule, in full: replace every character outside `[A-Za-z0-9_-]` with `-`.** Nothing else —
     * no case folding, no collapsing of runs, no trimming. `temporale:roman` becomes
     * `temporale-roman`, `nation:roman:US` becomes `nation-roman-US`, and
     * `diocese:ambrosian:lugano_ch` becomes `diocese-ambrosian-lugano_ch`. A client holding an id
     * computes the same string with one substitution and matches `.<fragment>.<step>`; this is
     * written down as a client-implementable rule in the design spec (`Id vocabulary` → *the frame
     * class fragment*) precisely because UnitTestInterface#42 has to reproduce it, and a derivation
     * only the server knows would be no better than not publishing the id.
     *
     * Derived from the **id**, never from the label. The id is stable and opaque and the server
     * minted it; the label is human-facing prose that is expected to change, and an address that
     * moved when someone reworded a caption would be worse than no address at all.
     *
     * The raw id is not usable as-is: `.diocese:ambrosian:lugano_ch` parses as a class followed by a
     * pseudo-class, and the ~60 per-calendar labels contain `: `, which makes `querySelectorAll()`
     * throw outright rather than merely match nothing.
     *
     * No case folding **here**, but the fragments this produces are mixed case (`nation-roman-US`,
     * `sanctorale-roman-EDITIO_TYPICA_1970`) and UnitTestInterface lowercases every class token
     * before matching. Matching is therefore case-insensitive on the client, which must put the
     * card's class and its selector through the same treatment; the spec section named above states
     * that as part of the rule, because a client that lowered only one side would find no cards.
     */
    private static function cssClassFragmentForId(string $id): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '-', $id);
    }

    /**
     * Validate a data file by checking that it exists and that it is valid JSON that conforms to a specific schema.
     *
     * @param ExecuteValidationSourceFolder|ExecuteValidationSourceFile|ExecuteValidationResource $validation The validation object. It should have the following properties:
     * - category: with a value of `sourceDataCheck` or `resourceDataCheck`
     * - sourceFile|sourceFolder: a string, the path to the data file or folder
     * - validate: a string with the identifier of the resource that we are validating;
     *             this corresponds to the CSS class in the Unit Test frontend
     *             that identifies the cell that will show the results of the validation;
     *             a further CSS class will be appended to identify the specific check being performed:
     *             1. `.file-exists`: a string, the class name to add to the message if the file exists
     *             2. `.json-valid`: a string, the class name to add to the message if the file is valid JSON
     *             3. `.schema-valid`: a string, the class name to add to the message if the file is valid against the schema
     * @param ConnectionInterface $to The connection to send the validation message to
     * @param ?string $requestId The correlation id this request's frames must carry back, captured by the caller.
     */
    private function executeValidation(\stdClass $validation, ConnectionInterface $to, ?string $requestId = null): void
    {
        $runToken = $this->resolveRunToken($to);
        // First thing is try to determine the schema that we will be validating against,
        // and the path to the source file or folder that we will be validating against the schema.
        // Our purpose here is to set the $pathForSchema and $dataPath variables.
        $pathForSchema = null;
        $dataPath      = null;
        $category      = (string) $validation->category;
        $validate      = (string) $validation->validate;

        // Source data checks validate data directly in the filesystem, not through the API
        if ($category === 'sourceDataCheck') {
            /** @var string $pathForSchema */
            $pathForSchema = $validate;
            // Are we validating a single source file, or are we validating a folder of i18n files?
            if (property_exists($validation, 'sourceFolder')) {
                // If the 'sourceFolder' property is set, then we are validating a folder of i18n files
                /** @var ExecuteValidationSourceFolder $validation */
                $dataPath = rtrim($validation->sourceFolder, '/');
                $matches  = null;
                if (preg_match('/^(wider\-region|national\-calendar|diocesan\-calendar)\-([A-Za-z_]+)\-i18n$/', $validate, $matches)) {
                    switch ($matches[1]) {
                        case 'wider-region':
                            $dataPath = strtr(
                                JsonData::WIDER_REGION_I18N_FOLDER->path(),
                                ['{wider_region}' => $matches[2]]
                            );
                            break;
                        case 'national-calendar':
                            $dataPath = strtr(
                                JsonData::NATIONAL_CALENDAR_I18N_FOLDER->path(),
                                ['{nation}' => $matches[2]]
                            );
                            break;
                        case 'diocesan-calendar':
                            try {
                                $dioceseMetadata = $this->findDioceseMetadata($matches[2]);
                            } catch (\RuntimeException | NotFoundException $e) {
                                $this->handleDioceseMetadataError($e, $to, $validation, $matches[2], $runToken, requestId: $requestId);
                                return;
                            }
                            // The diocesan tree is partitioned by rite, and it is the only tier
                            // that is: an Ambrosian diocese keeps its i18n files under
                            // `sourcedata/rite/ambrosian/...`, so the bare Roman constant would
                            // send every one of them to a folder that does not exist.
                            $dataPath = strtr(
                                JsonData::diocesanCalendarI18nFolderFor($dioceseMetadata->rite)->path(),
                                [
                                    '{diocese}' => $matches[2],
                                    '{nation}'  => $dioceseMetadata->nation
                                ]
                            );
                            break;
                    }
                } elseif (preg_match('/^proprium\-de\-sanctis(?:\-([A-Z]{2}))?\-([1-2][0-9]{3})\-i18n$/', $validate, $matches)) {
                    $region   = $matches[1] !== '' ? $matches[1] : 'EDITIO_TYPICA';
                    $year     = $matches[2];
                    $dataPath = RomanMissal::getSanctoraleI18nFilePath("{$region}_{$year}");
                    if (false === is_string($dataPath)) {
                        throw new \Exception("Could not determine i18n folder path for Proprium de Sanctis {$region} {$year}");
                    }
                }
            } else {
                // If we are not validating a folder of i18n files, then we are validating a single source file,
                // and the 'sourceFile' property is required in this case
                if (property_exists($validation, 'sourceFile')) {
                    /** @var ExecuteValidationSourceFile $validation */
                    $dataPath = (string) $validation->sourceFile;
                    $matches  = null;
                    // `[A-Z][a-z]+` matched `Europe` but neither `IT` (no lowercase char) nor
                    // `milano_it` (lowercase initial, and no `_` in the class), so the national
                    // and diocesan arms below never ran and $dataPath silently kept the
                    // client-supplied `sourceFile`. `[A-Za-z_]+` matches all three, the same way
                    // the i18n branch above already does.
                    if (preg_match('/^(wider-region|national-calendar|diocesan-calendar)-([A-Za-z_]+)$/', $validate, $matches)) {
                        switch ($matches[1]) {
                            case 'wider-region':
                                $dataPath = strtr(
                                    JsonData::WIDER_REGION_FILE->path(),
                                    ['{wider_region}' => $matches[2]]
                                );
                                break;
                            case 'national-calendar':
                                $dataPath = strtr(
                                    JsonData::NATIONAL_CALENDAR_FILE->path(),
                                    ['{nation}' => $matches[2]]
                                );
                                break;
                            case 'diocesan-calendar':
                                try {
                                    $dioceseMetadata = $this->findDioceseMetadata($matches[2]);
                                } catch (\RuntimeException | NotFoundException $e) {
                                    $this->handleDioceseMetadataError($e, $to, $validation, $matches[2], $runToken, requestId: $requestId);
                                    return;
                                }
                                // Rite-partitioned, like every other diocesan path. This arm only
                                // starts executing once the slug pattern above admits a diocesan
                                // id, which is why the two changes belong together: widening the
                                // pattern while leaving the bare Roman constant here would send
                                // every Ambrosian diocese to a Roman path.
                                $dataPath = strtr(
                                    JsonData::diocesanCalendarFileFor($dioceseMetadata->rite)->path(),
                                    [
                                        '{diocese}'      => $matches[2],
                                        '{nation}'       => $dioceseMetadata->nation,
                                        '{diocese_name}' => $dioceseMetadata->diocese
                                    ]
                                );
                                break;
                        }
                    } elseif (preg_match('/^proprium\-de\-sanctis(?:\-([A-Z]{2}))?\-([1-2][0-9]{3})$/', $validate, $matches)) {
                        $region   = $matches[1] !== '' ? $matches[1] : 'EDITIO_TYPICA';
                        $year     = $matches[2];
                        $dataPath = RomanMissal::getSanctoraleFileName("{$region}_{$year}");
                        if (false === is_string($dataPath)) {
                            throw new \Exception("Could not determine file path for Proprium de Sanctis {$region} {$year}");
                        }
                    }
                } else {
                    throw new \InvalidArgumentException('sourceFile property is required for sourceDataCheck');
                }
            }
        } else {
            // If it's not a sourceDataCheck, it's probably a resourceDataCheck
            // That is to say, an API path, and the 'sourceFile' property is required
            /** @var ExecuteValidationResource $validation */
            if (property_exists($validation, 'sourceFile')) {
                $sourceFile    = (string) $validation->sourceFile;
                $pathForSchema = $sourceFile;
                $dataPath      = $sourceFile;
            } else {
                throw new \InvalidArgumentException('sourceFile property is required for resourceDataCheck');
            }
        }

        $schema = Health::retrieveSchemaForCategory($category, $pathForSchema);

        // A folder check is recognised exactly as the execution phase used to recognise it, so a
        // message carrying a non-string `sourceFolder` keeps taking the file branch as before. The
        // same predicate yields the folder string the frames quote: the one the client supplied,
        // which for a reconstructed slug is not the folder the server ends up reading.
        $sourceFolder  = property_exists($validation, 'sourceFolder') && is_string($validation->sourceFolder)
            ? $validation->sourceFolder
            : null;
        $isFolderCheck = null !== $sourceFolder;

        // The slug re-derivation used to open the file branch of the execution phase, but it is
        // resolution work: the seam below must receive a path that is already final, and must
        // never re-derive one. Nothing between the two positions reads $dataPath, so the move is
        // behaviour-preserving.
        if (false === $isFolderCheck) {
            $matches = null;
            if (preg_match('/^diocesan-calendar-([a-z]{6}_[a-z]{2})$/', $pathForSchema, $matches)) {
                $dioceseId = $matches[1];
                try {
                    $dioceseMetadata = $this->findDioceseMetadata($dioceseId);
                } catch (\RuntimeException | NotFoundException $e) {
                    $this->handleDioceseMetadataError($e, $to, $validation, $dioceseId, $runToken, requestId: $requestId);
                    return;
                }
                $nation      = $dioceseMetadata->nation;
                $dioceseName = $dioceseMetadata->diocese;
                // Rite-partitioned, exactly as the i18n-folder branch above: this is the site
                // that actually governs a diocesan source-file check, because it reassigns
                // $dataPath after the earlier `sourceFile` branch has run.
                $dataPath = strtr(JsonData::diocesanCalendarFileFor($dioceseMetadata->rite)->path(), [
                    '{nation}'       => $nation,
                    '{diocese}'      => $dioceseId,
                    '{diocese_name}' => $dioceseName
                ]);
            } elseif (preg_match('/^national-calendar-([A-Z]{2})$/', $pathForSchema, $matches)) {
                $nation   = $matches[1];
                $dataPath = strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), [
                    '{nation}' => $nation
                ]);
            }
        }

        $this->runValidationSteps(
            $dataPath,
            $isFolderCheck ? 'folder' : 'file',
            $schema,
            $validate,
            $to,
            $runToken,
            $category,
            $pathForSchema,
            $sourceFolder,
            requestId: $requestId
        );
    }

    /**
     * Run the validation steps for one already-resolved target.
     *
     * This is the execution half of {@see Health::executeValidation()}: given a final path, a
     * kind and a schema, it emits the `file-exists` / `json-valid` / `schema-valid` frames.
     * Resolving that path and that schema from a client message happens before the call and
     * never here, so a caller that already knows its target — an inventory entry, whose `kind`
     * uses this same `file`/`folder` vocabulary — can enter directly.
     *
     * @param string $dataPath The final path to check: a project-relative or absolute file or folder path, or an API URL.
     * @param 'file'|'folder' $kind Whether $dataPath names a folder of i18n files or a single file/endpoint.
     * @param ?string $schema The schema to validate against, or null when none could be resolved.
     * @param string $label The human label for the result frames (what a v1 message calls `validate`). It reaches only the
     *        message *text*; what the frames are addressed by is $classFragment, which defaults to it.
     * @param ConnectionInterface $to The connection to send the result frames to.
     * @param ?string $runToken The originating run token to echo back on responses, or null to use the per-connection fallback.
     * @param string $category The check's category, one of ExecuteValidationCategory. It never touches control flow — the
     *        schema is already resolved by the time we are called — and appears only in the two "could not detect / verify
     *        the schema" diagnostics. It is a parameter rather than something rebuilt here so that the rule that produces
     *        it lives in exactly one place. The default is the right value for an inventory-driven source-data check; a
     *        caller checking anything else passes its own.
     * @param ?string $pathForSchema The value the schema was resolved from, quoted in the same two diagnostics; defaults to $label,
     *        which is what a sourceDataCheck resolves from.
     * @param ?string $sourceFolder For a folder check, the folder as the *caller* named it — what the result frames quote.
     *        It is not always $dataPath: a v1 client sends a bare id (`IT`) for the reconstructed i18n slugs and the server
     *        reads the folder it derived from it. Defaults to $dataPath, for a caller whose two are the same.
     * @param ?string $classFragment The CSS class fragment the result frames are addressed by, without the leading dot and
     *        without the trailing `.file-exists` / `.json-valid` / `.schema-valid` step. Split out from $label because the
     *        two are only *accidentally* the same thing: a v1 `validate` slug is hyphenated precisely so that it can serve
     *        as both, but an inventory label is human prose (`National calendar: US`) and would produce a selector that
     *        matches nothing — or, once it contains `: `, one that makes the client's `querySelectorAll()` throw. Defaults
     *        to $label so that every v1 caller keeps emitting byte-identical frames; a caller whose label is not slug-safe
     *        passes its own, derived from a stable id via {@see Health::cssClassFragmentForId()}.
     * @param ?\stdClass $target What the frames are about — see {@see Health::frameTarget()} — carried on them so a client
     *        can attribute them without parsing the selector. Null for a v1 `executeValidation` message, which names no id: it
     *        is never reconstructed from the class fragment, since that derivation only runs the other way.
     * @param ?string $requestId The correlation id of the request that caused this frame, captured by the handler when
     *        the request arrived and passed explicitly rather than read from any per-connection store; see
     *        {@see Health::onMessage()} for why that distinction is load-bearing.
     */
    private function runValidationSteps(
        string $dataPath,
        string $kind,
        ?string $schema,
        string $label,
        ConnectionInterface $to,
        ?string $runToken,
        string $category = 'sourceDataCheck',
        ?string $pathForSchema = null,
        ?string $sourceFolder = null,
        ?string $classFragment = null,
        ?\stdClass $target = null,
        ?string $requestId = null
    ): void {
        $pathForSchema ??= $label;
        // Read before $dataPath is made absolute below, so the two stay distinguishable.
        $sourceFolder ??= $dataPath;
        // The v1 conflation, preserved exactly: with no fragment of its own a caller addresses its
        // frames by its label, which is what `executeValidation` has always done.
        $classFragment ??= $label;

        // Now that we have the correct schema to validate against,
        // we will perform the actual validation either for all files in a folder, or for a single file
        if ($kind === 'folder') {
            // Resolve relative paths against the project root
            if (!str_starts_with($dataPath, '/')) {
                $dataPath = Router::$apiFilePath . $dataPath;
            }
            $files = glob($dataPath . '/*.json');
            if (false === $files || empty($files)) {
                // Report all three steps, not just file-exists. A client sizes the phase as three
                // frames per check, so short-circuiting with a single frame under-delivers and the
                // phase never completes — the same wedge, approached from the other side.
                $missing = ["$dataPath does not exist or contains no json files"];
                foreach ([Step::EXISTS, Step::PARSES, Step::VALIDATES] as $step) {
                    $this->sendFolderStepResult(
                        $to,
                        $classFragment,
                        $target,
                        $step,
                        $missing,
                        '', // unreachable: $missing is non-empty, so the failure text is used
                        "Data folder $sourceFolder could not be checked",
                        $runToken,
                        requestId: $requestId
                    );
                }
                // The short path terminates here, so the terminal frame does too. An arm that
                // returns without one wedges a client that stops on `complete` forever — the same
                // shape as the folder-branch wedge fixed in ea29b678, one level up.
                $this->sendComplete($to, target: $target, runToken: $runToken, requestId: $requestId);
                return;
            }

            // A folder check reports on the folder, not on each file in it: exactly one frame per
            // step, whatever the outcome. Failures are therefore collected here and summarised
            // once at the end, rather than emitted per file — sending one frame per failing file
            // made the frame count depend on how many files happened to be broken, which no
            // client can predict (see UnitTestInterface#43).
            /** @var list<string> $fileExistsErrors */
            $fileExistsErrors = [];
            /** @var list<string> $jsonErrors */
            $jsonErrors = [];
            /** @var list<string> $schemaErrors */
            $schemaErrors = [];

            /** @var list<PromiseInterface<string>> $promises */
            $promises = [];

            foreach ($files as $file) {
                $filename = pathinfo($file, PATHINFO_BASENAME);

                $matchI8nFile = preg_match('/(?:[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_[A-Z]{2})?|(?:ar|en|eo)_001|(?:en_150|es_419))\.json$/', $filename);

                if (false === $matchI8nFile || 0 === $matchI8nFile) {
                    $fileExistsErrors[] = "invalid i18n json filename $filename";
                    continue;
                }

                /** @var PromiseInterface<array{data: string, fromCache: bool}> $promise */
                $promise    = $this->cachedFileGetContents($file);
                $promises[] = $promise->then(
                    function (array $result) use ($filename, $schema, $label, $category, $pathForSchema, &$jsonErrors, &$schemaErrors) {
                        /** @var array{data: string, fromCache: bool} $result */
                        $fileData = $result['data'];
                        $jsonData = json_decode($fileData);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $jsonErrors[] = "$filename: " . json_last_error_msg();
                            // A file that would not decode was never schema-checked either, so the
                            // schema step must not go on to claim it validated successfully.
                            $schemaErrors[] = "$filename: not validated, the file could not be decoded as JSON";
                        } else {
                            if (null !== $schema) {
                                $validationResult = $this->validateDataAgainstSchema($jsonData, $schema);
                                if ($validationResult instanceof \stdClass) {
                                    /** @var string $validationText */
                                    $validationText = $validationResult->text;
                                    $schemaErrors[] = "$filename: " . $validationText;
                                }
                            } else {
                                $schemaErrors[] = "$filename: unable to detect a schema for {$label} and category {$category} (path for schema: $pathForSchema)";
                            }
                        }
                    },
                    function (\Throwable $reason) use ($filename, &$fileExistsErrors, &$jsonErrors, &$schemaErrors) {
                        $fileExistsErrors[] = "unreadable i18n json file $filename: " . $reason->getMessage();
                        // An unread file was neither decoded nor validated; without these the
                        // later steps would report success over a file nobody managed to open.
                        $jsonErrors[]   = "$filename: not decoded, the file could not be read";
                        $schemaErrors[] = "$filename: not validated, the file could not be read";
                    }
                );
            }

            $allPromises = Promise\all($promises);

            $allPromises->then(
                function () use ($to, $classFragment, $target, $sourceFolder, $schema, &$fileExistsErrors, &$jsonErrors, &$schemaErrors, $runToken, $requestId) {
                    // Exactly one frame per step, pass or fail. A folder check is a statement
                    // about the folder, so a failure names the offending files inside a single
                    // frame instead of emitting one frame each.
                    $this->sendFolderStepResult(
                        $to,
                        $classFragment,
                        $target,
                        Step::EXISTS,
                        $fileExistsErrors,
                        "The Data folder $sourceFolder exists and contains valid i18n json files",
                        "Data folder $sourceFolder",
                        $runToken,
                        requestId: $requestId
                    );

                    $this->sendFolderStepResult(
                        $to,
                        $classFragment,
                        $target,
                        Step::PARSES,
                        $jsonErrors,
                        "The i18n json files in Data folder $sourceFolder were successfully decoded as JSON",
                        "The i18n json files in Data folder $sourceFolder were not all decoded as JSON",
                        $runToken,
                        requestId: $requestId
                    );

                    $this->sendFolderStepResult(
                        $to,
                        $classFragment,
                        $target,
                        Step::VALIDATES,
                        $schemaErrors,
                        "The i18n json files in Data folder $sourceFolder were successfully validated against the Schema $schema",
                        "The i18n json files in Data folder $sourceFolder were not all valid against the Schema $schema",
                        $runToken,
                        requestId: $requestId
                    );

                    $this->sendComplete($to, target: $target, runToken: $runToken, requestId: $requestId);
                },
                function (\Throwable $e) use ($to, $target, $label, $dataPath, $runToken, $requestId) {
                    echo 'Error verifying i18n folder for validation ' . $label . ' (' . $dataPath . '): ' . $e->getMessage() . "\n";
                    // Every per-file promise handles its own rejection, so reaching here means the
                    // folder check broke in a way nothing anticipated. A client still asked, and is
                    // still waiting: terminate it rather than leave it counting frames that will
                    // never arrive.
                    $this->sendComplete($to, target: $target, runToken: $runToken, requestId: $requestId);
                }
            );
        } else {
            // {@see Health::processValidationData()} and {@see Health::handleValidationDataError()} take a
            // whole v1 message and read exactly two properties off it: `validate` and `category`. Both are
            // parameters here, so this is a pure adapter for their signatures — it carries no information
            // the caller did not pass. The annotation asserts the *shape* those two require; the literal
            // category cannot be proven, because an unrecognised category has to reach the diagnostic
            // verbatim (telling a client that its category was wrong is the diagnostic's whole job).
            //
            // `validate` carries the *class fragment* rather than the label: both readers use it
            // for nothing but `$message->classes`, and for a v1 caller the two are the same string,
            // so the legacy frames come out unchanged.
            /** @var ExecuteValidationSourceFile|ExecuteValidationResource $validationForMessages */
            $validationForMessages = (object) [
                'action'     => 'executeValidation',
                'category'   => $category,
                'validate'   => $classFragment,
                'sourceFile' => $dataPath
            ];

            // If we are validating an API path, we check for a 200 OK HTTP response from the API
            // rather than checking for existence of the file in the filesystem
            if (str_starts_with($dataPath, 'http://') || str_starts_with($dataPath, 'https://')) {
                // $dataPath is an API path in this case
                echo 'Retrieving data from URL ' . $dataPath . "\n";
                /** @var PromiseInterface<array{data: string, status: int, fromCache: bool, retryAfter: ?string}> $httpPromise */
                $httpPromise = $this->cachedGet($dataPath, [], 300, $to);
                $httpPromise->then(
                    function (array $result) use ($to, $validationForMessages, $dataPath, $schema, $pathForSchema, $runToken, $requestId, $target) {
                        /** @var array{data: string, status: int, fromCache: bool, retryAfter: ?string} $result */
                        $data       = $result['data'];
                        $status     = $result['status'];
                        $retryAfter = $result['retryAfter'];
                        echo 'Fetched data for ' . $dataPath . ': got ' . strlen($data) . " bytes, HTTP $status\n";
                        // #833: `'http_errors' => false` on the Guzzle client means a 4xx/5xx resolves
                        // this promise instead of rejecting it, so `processValidationData()` — which
                        // unconditionally reports `exists` as passed, correctly so for the filesystem
                        // arm that only ever reaches it once #822's stat has already proven the file is
                        // there — must not be reached at all for a resource the API refused. 429/5xx
                        // are included (#834), reported the same way as any other refusal.
                        if (self::isHttpSuccessStatus($status)) {
                            $this->processValidationData($data, $to, $validationForMessages, $dataPath, $schema, $pathForSchema, $runToken, $target, requestId: $requestId);
                        } else {
                            $this->handleValidationHttpFailure($status, $data, $retryAfter, $to, $validationForMessages, $dataPath, $runToken, $target, requestId: $requestId);
                        }
                        // Terminates whether the steps passed or failed. Every arm now emits one
                        // frame per published step — the decode arm's missing third was #821 — but
                        // what a client stops on is still this frame, never a count.
                        $this->sendComplete($to, target: $target, runToken: $runToken, requestId: $requestId);
                    },
                    function (\Throwable $e) use ($to, $validationForMessages, $dataPath, $runToken, $requestId, $target) {
                        $this->handleValidationDataError($e, $to, $validationForMessages, $dataPath, $runToken, $target, requestId: $requestId);
                        $this->sendComplete($to, target: $target, runToken: $runToken, requestId: $requestId);
                    }
                );
            } else {
                // $dataPath is probably a source file in the filesystem in this case
                // Resolve relative paths against the project root
                $fsPath = $dataPath;
                if (!str_starts_with($dataPath, '/')) {
                    $fsPath = Router::$apiFilePath . $dataPath;
                }
                echo 'Reading data from file ' . $fsPath . "\n";
                /** @var PromiseInterface<array{data: string, fromCache: bool}> $promise */
                $promise = $this->cachedFileGetContents($fsPath);
                $promise->then(
                    function (array $result) use ($to, $validationForMessages, $dataPath, $schema, $pathForSchema, $runToken, $requestId, $target) {
                        /** @var array{data: string, fromCache: bool} $result */
                        $data = $result['data'];
                        echo 'Fetched data for ' . $dataPath . ': got ' . strlen($data) . " bytes\n";
                        $this->processValidationData($data, $to, $validationForMessages, $dataPath, $schema, $pathForSchema, $runToken, $target, requestId: $requestId);
                        $this->sendComplete($to, target: $target, runToken: $runToken, requestId: $requestId);
                    },
                    function (\Throwable $e) use ($to, $validationForMessages, $dataPath, $runToken, $requestId, $target) {
                        // The unreadable-file arm. It reports all three steps as failures and is
                        // still a termination, so it ends the same way a success does. A *missing*
                        // file reaches it too, since #822: the read stats first rather than letting
                        // the Fallback adapter resolve absence with zero bytes.
                        $this->handleValidationDataError($e, $to, $validationForMessages, $dataPath, $runToken, $target, requestId: $requestId);
                        $this->sendComplete($to, target: $target, runToken: $runToken, requestId: $requestId);
                    }
                );
            }
        }
    }

    /**
     * Handle errors when reading validation data.
     *
     * **This does not terminate the request: every call site sends the `complete` frame itself, immediately after
     * calling this.** Its sibling {@see Health::handleDioceseMetadataError()} does the opposite — it calls
     * {@see Health::sendComplete()} internally — and nothing in either name or signature distinguishes the two, so
     * the difference is stated here rather than left to be discovered. Adding a `sendComplete()` here for symmetry
     * would double-complete at both call sites; `sendComplete()` has no idempotency guard, by design.
     *
     * @param \Throwable $e The exception that occurred while reading data.
     * @param ConnectionInterface $to The WebSocket connection to send errors to.
     * @param ExecuteValidationSourceFolder|ExecuteValidationSourceFile|ExecuteValidationResource $validation The validation object.
     * @param string $dataPath The path to the data that failed to load.
     * @param ?string $runToken The originating run token to echo back on responses, or null to use the per-connection fallback.
     * @param ?\stdClass $target What the frames are about, per {@see Health::frameTarget()}, or null for a v1 message, which names none.
     * @param ?string $requestId The correlation id of the request that caused this frame, captured by the handler when
     *        the request arrived and passed explicitly rather than read from any per-connection store; see
     *        {@see Health::onMessage()} for why that distinction is load-bearing.
     * @return void
     */
    private function handleValidationDataError(\Throwable $e, ConnectionInterface $to, \stdClass $validation, string $dataPath, ?string $runToken = null, ?\stdClass $target = null, ?string $requestId = null): void
    {
        // `validate` carries the class fragment: see the note where $validationForMessages is built.
        $validate = (string) $validation->validate;
        $category = (string) $validation->category;
        echo 'Error reading data: could not read data from ' . $dataPath . ': ' . $e->getMessage() . "\n";

        // An unreadable file fails all three steps, not just the first: a client sizes the phase as
        // three frames per check, so reporting once would leave the phase short.
        $this->sendStepResult(
            $to,
            $validate,
            $target,
            Step::EXISTS,
            Status::FAIL,
            "Data file $dataPath is not readable: " . $e->getMessage(),
            null,
            $runToken,
            requestId: $requestId
        );

        $this->sendStepResult(
            $to,
            $validate,
            $target,
            Step::PARSES,
            Status::FAIL,
            "Could not decode the Data file $dataPath as JSON because it is not readable",
            null,
            $runToken,
            requestId: $requestId
        );

        $this->sendStepResult(
            $to,
            $validate,
            $target,
            Step::VALIDATES,
            Status::FAIL,
            "Unable to verify schema for dataPath {$dataPath} and category {$category} since Data file $dataPath does not exist or is not readable",
            null,
            $runToken,
            requestId: $requestId
        );
    }

    /**
     * Handle a non-2xx HTTP response for a `resourceDataCheck` (#833): the URL-based sibling of
     * {@see Health::handleValidationDataError()}'s unreadable-file arm, and built to match it frame
     * for frame. `Health`'s Guzzle client is built with `'http_errors' => false`, so a 4xx or 5xx
     * resolves {@see Health::cachedGet()}'s promise rather than rejecting it — this is the check that
     * would otherwise be skipped, leaving `runValidationSteps()` to hand a problem document to
     * {@see Health::processValidationData()} as if it were the checked resource, which reports
     * `exists` as passed unconditionally.
     *
     * All three published steps fail, not just `exists`: a refusal means `parses` and `validates`
     * were never attempted either, and per #821 "not attempted" is reported the same as "failed",
     * never left unsent.
     *
     * The `exists` text quotes the API's own RFC 9457 `detail` when the body decodes to one — it
     * already explains precisely what is wrong (e.g. a rite's year boundary) — and falls back to the
     * bare status when it does not. A `Retry-After` hint, when the response carried one (429/5xx,
     * which reach here the same way as any other refusal since #834), is folded into the same text.
     *
     * @param int $status The non-2xx HTTP status the request resolved with.
     * @param string $body The response body, inspected only for a problem-document `detail`.
     * @param ?string $retryAfter The `Retry-After` header value, or null when the response carried none.
     * @param ConnectionInterface $to The WebSocket connection to send errors to.
     * @param ExecuteValidationSourceFolder|ExecuteValidationSourceFile|ExecuteValidationResource $validation The validation object.
     * @param string $dataPath The URL that was requested.
     * @param ?string $runToken The originating run token to echo back on responses, or null to use the per-connection fallback.
     * @param ?\stdClass $target What the frames are about, per {@see Health::frameTarget()}, or null for a v1 message, which names none.
     * @param ?string $requestId The correlation id of the request that caused this frame, captured by the handler when
     *        the request arrived and passed explicitly rather than read from any per-connection store; see
     *        {@see Health::onMessage()} for why that distinction is load-bearing.
     * @return void
     */
    private function handleValidationHttpFailure(int $status, string $body, ?string $retryAfter, ConnectionInterface $to, \stdClass $validation, string $dataPath, ?string $runToken = null, ?\stdClass $target = null, ?string $requestId = null): void
    {
        // `validate` carries the class fragment: see the note where $validationForMessages is built.
        $validate = (string) $validation->validate;
        $category = (string) $validation->category;
        echo "Refused: HTTP $status received from $dataPath\n";

        $detail  = self::problemDetailFromBody($body);
        $suffix  = $detail !== null ? ": $detail" : '';
        $suffix .= $retryAfter !== null ? " (Retry-After: $retryAfter)" : '';

        $this->sendStepResult(
            $to,
            $validate,
            $target,
            Step::EXISTS,
            Status::FAIL,
            "The Data file $dataPath was refused with HTTP $status$suffix",
            null,
            $runToken,
            requestId: $requestId
        );

        $this->sendStepResult(
            $to,
            $validate,
            $target,
            Step::PARSES,
            Status::FAIL,
            "Could not decode the Data file $dataPath as JSON because the request for it returned HTTP $status",
            null,
            $runToken,
            requestId: $requestId
        );

        $this->sendStepResult(
            $to,
            $validate,
            $target,
            Step::VALIDATES,
            Status::FAIL,
            "Unable to verify schema for dataPath {$dataPath} and category {$category} since the request for it returned HTTP $status",
            null,
            $runToken,
            requestId: $requestId
        );
    }

    /**
     * Handle diocese metadata lookup errors.
     *
     * Sends a structured WebSocket error message to indicate that the caller
     * should abort further processing.
     *
     * @param \RuntimeException|NotFoundException $e The exception that was thrown.
     * @param ConnectionInterface $to The WebSocket connection to send the error to.
     * @param ExecuteValidationSourceFolder|ExecuteValidationSourceFile|ExecuteValidationResource $validation The validation object.
     * @param string $calendarId The diocese calendar ID that failed to resolve.
     * @param ?string $requestId The correlation id of the request that caused this frame, captured by the handler when
     *        the request arrived and passed explicitly rather than read from any per-connection store; see
     *        {@see Health::onMessage()} for why that distinction is load-bearing.
     * @return void
     */
    private function handleDioceseMetadataError(
        \RuntimeException|NotFoundException $e,
        ConnectionInterface $to,
        \stdClass $validation,
        string $calendarId,
        ?string $runToken = null,
        ?string $requestId = null
    ): void {
        $validate = (string) $validation->validate;

        $message       = new \stdClass();
        $message->type = 'error';

        // Check NotFoundException first since it extends RuntimeException via ApiException
        if ($e instanceof NotFoundException) {
            $message->error_code = 'unknown_diocese';
            $message->text       = "Unknown diocese calendar ID: {$calendarId}. Please verify the calendar ID is correct.";
            $message->hint       = 'invalid_input';
            echo "Diocese metadata error (NotFoundException) for {$calendarId}: " . $e->getMessage() . "\n";
        } else {
            // Generic RuntimeException (e.g., metadata not loaded yet)
            $message->error_code = 'metadata_loading';
            $message->text       = "Metadata not loaded yet. Please retry in a moment. Calendar ID: {$calendarId}";
            $message->hint       = 'retry';
            echo "Diocese metadata error (RuntimeException) for {$calendarId}: " . $e->getMessage() . "\n";
        }

        $message->classes = ".$validate.diocese-metadata";
        $this->sendMessage($to, $message, $runToken, requestId: $requestId);

        // Every caller returns immediately after this, so this frame is the whole answer to a
        // request that was accepted and started. It emits no step frames at all, which makes it the
        // arm a client counting to three would hang on longest — and the one the terminal frame is
        // least likely to be remembered for. The target is null because a v1 `executeValidation`
        // message names no id, and one is not invented here any more than it is on its step frames.
        $this->sendComplete($to, target: null, runToken: $runToken, requestId: $requestId);
    }

    /**
     * Emit the single result frame for one step of a `sourceFolder` check.
     *
     * A folder check reports on the folder as a whole, so each step yields exactly one frame
     * whether it passed or failed. Emitting one frame per failing file instead would make the
     * number of frames depend on how many files happened to be broken — unpredictable for a
     * client that has to know when a phase is complete (UnitTestInterface#43).
     *
     * All this adds to {@see Health::sendStepResult()} is the folder-shaped summary: an error list
     * becomes a {@see Status} and a single line of text naming every offending file. Deriving the
     * frame from that — the `type` it used to compute itself, and the selector its callers used to
     * build by concatenating a step name — is the emitter's job now, so the projection lives in one
     * place for folder checks and file checks alike.
     *
     * @param string       $classFragment The fragment the frames are addressed by, without the leading dot and
     *                                    without the trailing step; the step supplies that.
     * @param ?\stdClass   $target        What the frames are about, per {@see Health::frameTarget()}, or null for a v1 message.
     * @param Step         $step          Which of the three steps this frame reports.
     * @param list<string> $errors        Per-file failures; empty means the step passed.
     * @param string       $successText   Message for the passing case.
     * @param string       $failurePrefix Lead-in for the failing case; the offending files follow.
     * @param ?string      $runToken      Run token to echo back.
     * @param ?string $requestId The correlation id of the request that caused this frame, captured by the handler when
     *        the request arrived and passed explicitly rather than read from any per-connection store; see
     *        {@see Health::onMessage()} for why that distinction is load-bearing.
     */
    private function sendFolderStepResult(
        ConnectionInterface $to,
        string $classFragment,
        ?\stdClass $target,
        Step $step,
        array $errors,
        string $successText,
        string $failurePrefix,
        ?string $runToken,
        ?string $requestId = null
    ): void {
        $this->sendStepResult(
            $to,
            $classFragment,
            $target,
            $step,
            [] === $errors ? Status::PASS : Status::FAIL,
            [] === $errors
                ? $successText
                : $failurePrefix . ' — ' . count($errors) . ' problem(s): ' . implode('; ', $errors),
            $errors,
            $runToken,
            requestId: $requestId
        );
    }

    /**
     * Process the validation of data against a schema.
     *
     * @param ExecuteValidationSourceFolder|ExecuteValidationSourceFile|ExecuteValidationResource $validation The validation object.
     * @param ?string $runToken The originating run token to echo back on responses, or null to use the per-connection fallback.
     * @param ?\stdClass $target What the frames are about, per {@see Health::frameTarget()}, or null for a v1 message, which names none.
     * @param ?string $requestId The correlation id of the request that caused this frame, captured by the handler when
     *        the request arrived and passed explicitly rather than read from any per-connection store; see
     *        {@see Health::onMessage()} for why that distinction is load-bearing.
     */
    private function processValidationData(string $data, ConnectionInterface $to, \stdClass $validation, string $dataPath, ?string $schema, string $pathForSchema, ?string $runToken = null, ?\stdClass $target = null, ?string $requestId = null): void
    {
        // `validate` carries the class fragment: see the note where $validationForMessages is built.
        $validate = (string) $validation->validate;
        $category = (string) $validation->category;

        $this->sendStepResult(
            $to,
            $validate,
            $target,
            Step::EXISTS,
            Status::PASS,
            "The Data file $dataPath exists",
            null,
            $runToken,
            requestId: $requestId
        );

        $jsonData = json_decode($data);
        if (json_last_error() === JSON_ERROR_NONE) {
            $this->sendStepResult(
                $to,
                $validate,
                $target,
                Step::PARSES,
                Status::PASS,
                "The Data file $dataPath was successfully decoded as JSON",
                null,
                $runToken,
                requestId: $requestId
            );

            if (null !== $schema) {
                $validationResult = $this->validateDataAgainstSchema($jsonData, $schema);
                if (gettype($validationResult) === 'boolean' && $validationResult === true) {
                    $this->sendStepResult(
                        $to,
                        $validate,
                        $target,
                        Step::VALIDATES,
                        Status::PASS,
                        "The Data file $dataPath was successfully validated against the Schema $schema",
                        null,
                        $runToken,
                        requestId: $requestId
                    );
                } elseif ($validationResult instanceof \stdClass) {
                    // The failure text is the schema library's, quoted verbatim; the frame around it
                    // is built the same way as every other one rather than by decorating the object
                    // the validator happened to return.
                    /** @var string $validationText */
                    $validationText = $validationResult->text;
                    // `details` carries what the text flattens, and never invents what is not there.
                    // {@see Health::validateDataAgainstSchema()} builds that text by joining the schema's
                    // own error line to the validator's message with a newline, so splitting on newlines
                    // hands a client back the pieces instead of making it parse prose it did not build.
                    $this->sendStepResult(
                        $to,
                        $validate,
                        $target,
                        Step::VALIDATES,
                        Status::FAIL,
                        $validationText,
                        explode(PHP_EOL, $validationText),
                        $runToken,
                        requestId: $requestId
                    );
                }
            } else {
                $this->sendStepResult(
                    $to,
                    $validate,
                    $target,
                    Step::VALIDATES,
                    Status::FAIL,
                    "executeValidation validation->sourceFile (JSON): Unable to detect schema for dataPath {$dataPath} and category {$category} (path for schema: $pathForSchema, Route::CALENDARS->path(): " . Route::CALENDARS->path() . ', LitSchema::METADATA->path(): ' . LitSchema::METADATA->path() . ')',
                    null,
                    $runToken,
                    requestId: $requestId
                );
            }
        } else {
            $this->sendStepResult(
                $to,
                $validate,
                $target,
                Step::PARSES,
                Status::FAIL,
                "There was an error decoding the Data file $dataPath as JSON: " . json_last_error_msg() . ". Raw data = &lt;&lt;&lt;JSON\n" . $data . "\n&gt;&gt;&gt;",
                null,
                $runToken,
                requestId: $requestId
            );

            // A file that would not decode was never schema-checked, and until #821 this arm simply
            // stopped here: two frames where every sibling arm emits three. The step is reported as
            // failed rather than left unsent, because "not attempted" and "not sent" are the same
            // thing to a client and the honest answer is that the file did not validate. The folder
            // branch already collects exactly this ("not validated, the file could not be decoded as
            // JSON"); {@see Health::handleValidationDataError()} does the same for an unreadable file.
            $this->sendStepResult(
                $to,
                $validate,
                $target,
                Step::VALIDATES,
                Status::FAIL,
                "Unable to verify schema for dataPath {$dataPath} and category {$category} since Data file $dataPath could not be decoded as JSON",
                null,
                $runToken,
                requestId: $requestId
            );
        }
    }

    /**
     * Read the optional `rite` property off an incoming WebSocket message.
     *
     * `rite` is optional — it is deliberately absent from the `required` list of every shape in
     * `WebSocketMessage.json` that carries it — so clients that predate rite awareness keep working
     * and get their rite resolved from metadata instead.
     * A non-string value is treated as absent rather than as an error; the
     * resolver validates the string against {@see Rite} in any case.
     */
    private static function readRiteHint(\stdClass $message): ?string
    {
        if (property_exists($message, 'rite') && is_string($message->rite)) {
            return $message->rite;
        }
        return null;
    }

    /**
     * Determine the rite a calendar request should be computed under.
     *
     * Resolution order:
     *   1. an explicit `rite` on the WebSocket message, when it names a known {@see Rite};
     *   2. for `diocesancalendar`, the rite `/calendars` announces for that diocese;
     *   3. for `ritecalendar`, the calendar id itself read as a rite;
     *   4. otherwise the default rite.
     *
     * Step 2 is what lets an existing, rite-unaware client keep working: the four
     * Ambrosian dioceses need `/calendar/ambrosian/diocese/{id}` and 400 without
     * the segment (issue #767). National calendars are Roman-only — `/calendars`
     * announces no rite for them — so they fall through to step 4.
     *
     * @param string  $calendar The calendar identifier.
     * @param string  $category The type of calendar ('nationalcalendar', 'diocesancalendar' or 'ritecalendar').
     * @param ?string $riteHint The `rite` property of the incoming message, if any.
     */
    private function resolveRite(string $calendar, string $category, ?string $riteHint = null): Rite
    {
        if (null !== $riteHint) {
            $rite = Rite::tryFrom($riteHint);
            if (null !== $rite) {
                return $rite;
            }
        }

        if ($category === 'diocesancalendar') {
            try {
                return $this->findDioceseMetadata($calendar)->rite;
            } catch (\RuntimeException | NotFoundException) {
                // Metadata not loaded yet, or an unknown diocese: fall through to
                // the default. The request itself will report the real problem.
                return Rite::default();
            }
        }

        if ($category === 'ritecalendar') {
            return Rite::tryFrom($calendar) ?? Rite::default();
        }

        return Rite::default();
    }

    /**
     * Map a typed identity's `kind` onto the internal calendar category.
     *
     * The wire vocabulary and the internal one are deliberately different words for the same four
     * things, and only the wire half is new: nothing in this plan renames `nationalcalendar`,
     * `diocesancalendar` or `ritecalendar`, which are threaded through
     * {@see Health::buildCalendarRequestPath()}, {@see Health::resolveRite()} and
     * {@see Health::executeUnitTest()}.
     *
     * `general` and `rite` both land on `ritecalendar` because they are the same route:
     * `/calendar/{rite}/{year}` is *the* calendar of a rite, and the General Roman Calendar is that
     * route with the Roman rite. They stay separate words on the wire because "General Roman
     * Calendar" is what the thing is called, and because `general` fixes the rite while `rite`
     * chooses it.
     *
     * @return 'nationalcalendar'|'diocesancalendar'|'ritecalendar'|null null when the kind is not one of the four.
     */
    private static function categoryForKind(string $kind): ?string
    {
        return match ($kind) {
            'general', 'rite' => 'ritecalendar',
            'national'        => 'nationalcalendar',
            'diocesan'        => 'diocesancalendar',
            default           => null
        };
    }

    /**
     * The rite a calendar identity *actually* has, as far as the server can tell.
     *
     * Returning null means "the server has no opinion", which is not the same as "any rite will
     * do": it is the one case in which a client's assertion is taken at its word, because
     * contradicting it would require knowledge this process does not have. Only the diocesan kind
     * can reach that state, and only transiently — `Health::$metadata` is fetched asynchronously
     * when the WebSocket connection opens.
     *
     * @param 'general'|'national'|'diocesan'|'rite' $kind
     * @param ?string $id The identity's `id`, already known to be a string when the kind requires one.
     * @return Rite|null The rite this calendar is computed under, or null when it cannot be known here.
     * @throws \InvalidArgumentException When the id and the kind cannot describe the same calendar at all.
     */
    private function actualRiteForKind(string $kind, ?string $id): ?Rite
    {
        switch ($kind) {
            case 'diocesan':
                try {
                    return $this->findDioceseMetadata((string) $id)->rite;
                } catch (\RuntimeException | NotFoundException) {
                    // Metadata not loaded yet, or an id naming no diocese. Neither is a rite
                    // disagreement, and reporting either as one would send the reader hunting the
                    // wrong bug: the first is a server-side timing condition, the second is
                    // answered honestly by the calendar request itself, which 404s.
                    //
                    // {@see Health::resolveRite()} swallows the same two exceptions for the same
                    // reason but returns a different value — `Rite::default()`, not null — so do
                    // not read this as delegating to it. The two cannot agree on a return value:
                    // resolveRite() must name a rite because a URL has to be built, while null here
                    // means "no opinion to contradict the client with", and ROMAN would be an
                    // opinion. The observable behaviour is nevertheless identical, because a null
                    // here means the client's rite survives as the hint validateTypedCalendar()
                    // passes on, and resolveRite() honours a parsable hint at step 1 and never
                    // reaches its own diocesan lookup.
                    return null;
                }
            case 'national':
                // National calendars are Roman-only: `/calendars` announces a rite for dioceses and
                // not for nations, and `/calendar/ambrosian/nation/XX` is a 400 because there is no
                // such calendar to compute. resolveRite() encodes the same fact by falling through
                // to the default for this category.
                return Rite::default();
            case 'rite':
                // For a rite-level calendar the id *is* the rite, so an id that names no rite is
                // not a rite disagreement — it is an identity that describes nothing.
                $rite = Rite::tryFrom((string) $id);
                if (null === $rite) {
                    throw new \InvalidArgumentException("Unknown rite calendar: {$id}");
                }
                return $rite;
            default:
                // `general` is the General *Roman* Calendar — the rite is in the name. A rite-level
                // calendar of any other rite is `kind: rite`, which is why the two words both exist.
                //
                // The message says what is wrong and stops there, deliberately. Naming a kind to
                // try instead would be wrong as often as right: `ambrosian` would indeed want
                // `kind: rite`, but `IT` would want `kind: national` and `kind: rite` would reject
                // it in turn, so the advice would send the reader somewhere that also fails.
                if (null !== $id && Rite::ROMAN !== Rite::tryFrom($id)) {
                    throw new \InvalidArgumentException("Kind general names the General Roman Calendar; its only valid id is roman, not {$id}.");
                }
                return Rite::ROMAN;
        }
    }

    /**
     * Resolve a typed calendar identity into the internal triple the calendar routines take.
     *
     * Shared by every action that carries a `calendar` object, so that the `kind`→category mapping
     * and the rite check exist once rather than once per action.
     *
     * **A `rite` here is an assertion, not a hint, and a wrong one is rejected.** The distinction is
     * the point of the typed identity. On a legacy message `rite` is optional and
     * {@see Health::resolveRite()} prefers it whenever it parses — correct for a value a
     * rite-unaware client may have guessed at, since a guess is all the protocol could carry. A
     * client sending a typed identity selected the calendar and knows its rite, so a disagreement is
     * a client bug. Silently preferring the assertion would compute the wrong calendar; silently
     * preferring the metadata would compute the right one while leaving the client believing
     * something false. Saying so is more useful than either.
     *
     * @param \stdClass $calendar The identity object; its properties are untrusted, only its type is known.
     * @return array{category: 'nationalcalendar'|'diocesancalendar'|'ritecalendar', calendar: string, rite: Rite}
     * @throws \InvalidArgumentException Whose message is the client-facing rejection text.
     */
    private function resolveCalendarIdentity(\stdClass $calendar): array
    {
        $kind = property_exists($calendar, 'kind') ? $calendar->kind : null;
        if (false === is_string($kind)) {
            throw new \InvalidArgumentException('calendar.kind must be a string naming one of: general, national, diocesan, rite.');
        }
        $category = self::categoryForKind($kind);
        if (null === $category) {
            throw new \InvalidArgumentException("Unknown calendar kind: {$kind}");
        }
        /** @var 'general'|'national'|'diocesan'|'rite' $kind */

        $riteName = property_exists($calendar, 'rite') ? $calendar->rite : null;
        if (false === is_string($riteName)) {
            throw new \InvalidArgumentException('calendar.rite must be a string naming a known rite.');
        }
        $assertedRite = Rite::tryFrom($riteName);
        if (null === $assertedRite) {
            throw new \InvalidArgumentException("Unknown rite: {$riteName}");
        }

        $id = property_exists($calendar, 'id') ? $calendar->id : null;
        if (null !== $id && false === is_string($id)) {
            throw new \InvalidArgumentException('calendar.id must be a string.');
        }
        // `general` is the only kind that needs no id: there is exactly one General Roman Calendar,
        // so there is nothing to choose between. Every other kind names one of many.
        if (null === $id && 'general' !== $kind) {
            throw new \InvalidArgumentException("calendar.id is required for kind {$kind}.");
        }

        $actualRite = $this->actualRiteForKind($kind, $id);
        if (null !== $actualRite && $actualRite !== $assertedRite) {
            throw new \InvalidArgumentException(sprintf(
                'calendar.rite says %s but %s is %s.',
                $assertedRite->value,
                $id ?? $kind,
                $actualRite->value
            ));
        }

        // A rite-level calendar carries no separate identifier: buildCalendarRequestPath() emits
        // `/{rite}/{year}` for `ritecalendar` and resolveRite() reads the rite back off the calendar
        // id, so the rite is what has to be passed as the id. `general` may not have sent one at all.
        return [
            'category' => $category,
            'calendar' => 'ritecalendar' === $category ? $assertedRite->value : (string) $id,
            'rite'     => $assertedRite
        ];
    }

    /**
     * Reject a v2 message that still carries a legacy property its own shape retired.
     *
     * One implementation for all three reshaped actions, called from
     * {@see Health::validateSource()}, {@see Health::validateTypedCalendar()} and
     * {@see Health::runTest()}. Three hand-written copies of this rule is exactly the shape that lets
     * one of them quietly stop rejecting — the same argument that put the `kind`→category mapping in
     * one place.
     *
     * Runs before anything else in each handler, so a half-migrated message is answered for what is
     * actually wrong with it rather than for whatever its retired property happened to make of the
     * rest. The first offender in declaration order is named and the rest are not: each message then
     * says one true, specific thing, and a client sending two retired properties hears about the
     * second on its next attempt. See {@see Health::RETIRED_PROPERTIES} for why the rule exists.
     *
     * @param 'validateSource'|'validateCalendar'|'runTest' $action The reshaped action being handled.
     * @return bool True when the message was rejected and the caller must stop.
     */
    private function rejectRetiredProperties(\stdClass $message, string $action, ConnectionInterface $to, ?string $requestId = null): bool
    {
        $shape = self::RETIRED_PROPERTIES[$action]['shape'];
        foreach (self::RETIRED_PROPERTIES[$action]['retired'] as $property => $replacement) {
            if (property_exists($message, $property)) {
                $this->rejectMessage($to, ProtocolErrorCode::RETIRED_PROPERTY, sprintf('%s is not part of a %s: %s', $property, $shape, $replacement), requestId: $requestId);
                return true;
            }
        }

        return false;
    }

    /**
     * Read a message's `calendar` property as a typed identity.
     *
     * Both actions that carry one come through here, and the shared step is the *read*, not the
     * guarantee: `validateCalendar` is discriminated on `calendar` being an object
     * ({@see Health::isTypedCalendarMessage()}), so by the time it arrives the check below cannot
     * fail; `runTest` is discriminated on its name, so nothing has looked at `calendar` at all. The
     * one that needs the check and the one that does not therefore call the same thing, which is
     * what stops the second from being written without it.
     *
     * @param string $action The action name, so the rejection says which message is wrong.
     * @return array{category: 'nationalcalendar'|'diocesancalendar'|'ritecalendar', calendar: string, rite: Rite}
     * @throws \InvalidArgumentException Whose message is the client-facing rejection text.
     */
    private function readCalendarIdentity(\stdClass $message, string $action): array
    {
        $calendar = property_exists($message, 'calendar') ? $message->calendar : null;
        if (false === $calendar instanceof \stdClass) {
            throw new \InvalidArgumentException("{$action} calendar must be an object carrying kind, id and rite.");
        }

        return $this->resolveCalendarIdentity($calendar);
    }

    /**
     * Read a message's `year` as the integer the calendar routines take.
     *
     * `WebSocketMessageValidator` now enforces `year: integer` on every shape that carries it, so the
     * `TypeError` this once guarded against — `validateCalendar(…, int $year, …)` or
     * `executeUnitTest(…, int $year, …)` reached with a null or string `year`, an `\Error` that
     * Ratchet's `IoServer::handleData` does not catch — should no longer reach here. Kept as a
     * type-narrowing backstop rather than trusted blindly, the same reasoning
     * {@see Health::cancelRun()} documents on the property it was first found on.
     *
     * @param string $action The action name, so the rejection says which message is wrong.
     * @throws \InvalidArgumentException Whose message is the client-facing rejection text.
     */
    private static function readYear(\stdClass $message, string $action): int
    {
        $year = property_exists($message, 'year') ? $message->year : null;
        if (false === is_int($year)) {
            throw new \InvalidArgumentException("{$action} year must be an integer.");
        }

        return $year;
    }

    /**
     * Build the calendar API request path based on calendar ID, year, category and rite.
     *
     * The rite segment is always emitted explicitly (`/roman/...`, `/ambrosian/...`),
     * which is the canonical URL form {@see Rite} documents. It is required — not
     * merely canonical — for Ambrosian diocesan calendars, which 400 without it.
     *
     * @param string $calendar The calendar identifier (e.g., 'VA' for Vatican, 'USA' for national).
     * @param int $year The year for the calendar request.
     * @param string $category The type of calendar ('nationalcalendar', 'diocesancalendar' or 'ritecalendar').
     * @param Rite $rite The rite the calendar is computed under.
     * @return string The constructed request path.
     */
    private function buildCalendarRequestPath(string $calendar, int $year, string $category, Rite $rite): string
    {
        $ritePath = '/' . $rite->value;

        // 'VA' is the historical marker for "the rite-level calendar", not a
        // request for /nation/VA; it predates the `ritecalendar` category and
        // resolves the same way.
        if ($calendar === 'VA' || $category === 'ritecalendar') {
            return "$ritePath/$year?year_type=CIVIL";
        }

        return match ($category) {
            'nationalcalendar'  => "$ritePath/nation/$calendar/$year?year_type=CIVIL",
            'diocesancalendar'  => "$ritePath/diocese/$calendar/$year?year_type=CIVIL",
            default             => throw new \InvalidArgumentException("Unknown calendar category: {$category}")
        };
    }

    /**
     * Handle a `validateCalendar` message carrying a typed calendar identity.
     *
     * Purely a translation layer: it resolves the identity, checks the two scalars the legacy shape
     * never had to type-check, and hands the result to the unchanged
     * {@see Health::validateCalendar()}. Nothing about *how* a calendar is fetched and validated
     * changes with the message shape, and this method exists so that nothing about it has to.
     *
     * The resolved rite is passed in the `$riteHint` slot. `resolveRite()` treats a parsable hint as
     * authoritative, which is exactly right here because by this point it has been checked — see
     * {@see Health::resolveCalendarIdentity()}.
     *
     * `year` and `responseFormat` are re-checked here rather than trusted outright, even though
     * `WebSocketMessageValidator` now enforces `year: integer` and `responseFormat`'s enum on this
     * shape. `year` goes through the shared {@see Health::readYear()}; `responseFormat` is checked
     * here because it belongs to this action alone — an unusable one would reach
     * `ReturnTypeParam::from()` and raise a `\ValueError`, which is an `\Error` and so escapes
     * Ratchet's `IoServer::handleData`, taking the whole WebSocket process down over one malformed
     * message. See {@see Health::cancelRun()}, which documents the same hazard on the property it
     * was found on.
     *
     * @param ValidateTypedCalendar $message The message naming the calendar to compute and check.
     * @param ConnectionInterface $to The connection to send the result frames to.
     * @param ?string $requestId The correlation id this request's frames must carry back, captured by the caller.
     */
    private function validateTypedCalendar(\stdClass $message, ConnectionInterface $to, ?string $requestId = null): void
    {
        // A leftover `category`, `responsetype` or top-level `rite` is a half-migrated client: the
        // message happens to work, because `calendar.kind`, `responseFormat` and `calendar.rite`
        // supply what is actually read and the stale property never is, so the client keeps
        // believing the old property still selects something and only finds out otherwise the day
        // the two disagree. Say so now, while they still agree — most sharply for `rite`, where a
        // disagreement is the very thing resolveCalendarIdentity() refuses to resolve silently.
        // See Health::RETIRED_PROPERTIES.
        if ($this->rejectRetiredProperties($message, 'validateCalendar', $to, requestId: $requestId)) {
            return;
        }

        try {
            $identity = $this->readCalendarIdentity($message, 'validateCalendar');
            $year     = self::readYear($message, 'validateCalendar');
        } catch (\InvalidArgumentException $e) {
            $this->rejectMessage($to, ProtocolErrorCode::INVALID_MESSAGE, $e->getMessage(), requestId: $requestId);
            return;
        }

        $responseFormat = property_exists($message, 'responseFormat') ? $message->responseFormat : null;
        if (false === is_string($responseFormat) || false === in_array($responseFormat, self::VALIDATABLE_RESPONSE_FORMATS, true)) {
            $this->rejectMessage($to, ProtocolErrorCode::INVALID_MESSAGE, 'validateCalendar responseFormat must be one of: ' . implode(', ', self::VALIDATABLE_RESPONSE_FORMATS) . '.', requestId: $requestId);
            return;
        }

        $this->validateCalendar(
            $identity['calendar'],
            $year,
            $identity['category'],
            $responseFormat,
            $to,
            $identity['rite']->value,
            requestId: $requestId
        );
    }

    /**
     * Emit the result frame for one step of one calendar validation.
     *
     * The calendar-validation frames are addressed by `.calendar-{id}.{step}.year-{year}`, which is
     * the only address in the health protocol with a segment *past* the step. This is the one place
     * that knows that — the nineteen emission sites in {@see Health::validateCalendar()} name
     * the calendar and the year as values and never see a dot. Per-call-site selector construction is
     * the defect #820 fixed and #806 is removing; handing those sites a `"year-$year"` to interpolate
     * would have brought it back wearing a different hat.
     *
     * A calendar's `target` is the calendar *and* the year — `{"id": "IT", "year": 2024}` — because
     * neither identifies a check on its own: the same calendar is checked once per year in a run.
     * The category and the rite are in scope here and are deliberately left out; see
     * {@see Health::frameTarget()} for why identity is not duplicated in ahead of `requestId`.
     *
     * Every argument past `$text` is passed by name at the call sites. The two emitters are siblings
     * with different tails — this one's eighth slot is `$responseType`, `sendStepResult()`'s is
     * `$runToken`, and both are `?string` — so an argument list copied from one to the other would
     * bind silently and ship a run token as a `responsetype`. Names make that impossible, and make
     * `details: null, responseType: null` legible where two bare nulls were not.
     *
     * @param ConnectionInterface $to      The connection to send the frame to.
     * @param string       $calendar     The calendar identifier the run is checking, e.g. `US`.
     * @param int          $year         The year being checked; rides in the class as a trailing `.year-{year}`.
     * @param Step         $step         Which of the three steps this frame reports.
     * @param Status       $status       The outcome.
     * @param string       $text         The human-facing message, passed through untouched.
     * @param ?list<string> $details     The individual failures behind a summarising `text`, or null when the site
     *                                   genuinely has none — never manufactured, per `sendStepResult()`.
     * @param ?string      $responseType The legacy `responsetype` the decode-failure frames carry, or null.
     * @param ?string      $runToken     Run token to echo back.
     * @param ?string $requestId The correlation id of the request that caused this frame, captured by the handler when
     *        the request arrived and passed explicitly rather than read from any per-connection store; see
     *        {@see Health::onMessage()} for why that distinction is load-bearing.
     */
    private function sendCalendarStepResult(
        ConnectionInterface $to,
        string $calendar,
        int $year,
        Step $step,
        Status $status,
        string $text,
        ?array $details = null,
        ?string $responseType = null,
        ?string $runToken = null,
        ?string $requestId = null
    ): void {
        $this->sendStepResult(
            $to,
            "calendar-$calendar",
            self::frameTarget($calendar, ['year' => $year]),
            $step,
            $status,
            $text,
            details: $details,
            runToken: $runToken,
            requestId: $requestId,
            classQualifier: "year-$year",
            responseType: $responseType
        );
    }

    /**
     * Emit the `schema-valid` failure frame for a calendar, from the object the JSON-schema
     * validator returned.
     *
     * The failure text is the schema library's, quoted verbatim; the frame around it is built the
     * same way as every other one rather than by decorating the object the validator happened to
     * return — the same treatment {@see Health::processValidationData()} gives the identical object,
     * and for the same reason: a frame whose shape depends on which code path produced it is a frame
     * no client can rely on.
     *
     * `details` carries what the text flattens, and never invents what is not there.
     * {@see Health::validateDataAgainstSchema()} builds that text by joining the schema's own error
     * line to the validator's message with a newline, so splitting on newlines hands a client back
     * the pieces instead of making it parse prose it did not build.
     *
     * @param ConnectionInterface $to The connection to send the frame to.
     * @param string $calendar The calendar identifier the run is checking, e.g. `US`.
     * @param int $year The year being checked.
     * @param \stdClass $validationResult The `type`/`text` object {@see Health::validateDataAgainstSchema()} returns on failure.
     * @param ?string $runToken Run token to echo back.
     * @param ?string $requestId The correlation id of the request that caused this frame, captured by the handler when
     *        the request arrived and passed explicitly rather than read from any per-connection store; see
     *        {@see Health::onMessage()} for why that distinction is load-bearing.
     */
    private function sendSchemaFailureStepResult(
        ConnectionInterface $to,
        string $calendar,
        int $year,
        \stdClass $validationResult,
        ?string $runToken,
        ?string $requestId = null
    ): void {
        /** @var string $validationText */
        $validationText = $validationResult->text;
        $this->sendCalendarStepResult(
            $to,
            $calendar,
            $year,
            Step::VALIDATES,
            Status::FAIL,
            $validationText,
            details: explode(PHP_EOL, $validationText),
            runToken: $runToken,
            requestId: $requestId
        );
    }

    /**
     * Validates the specified liturgical calendar for a given year and category,
     * and sends the validation results to the specified connection.
     *
     * @param string $calendar The calendar identifier (e.g., 'VA' for Vatican).
     * @param int $year The year for which the calendar is to be validated.
     * @param string $category The type of calendar (e.g., 'nationalcalendar', 'diocesancalendar', 'ritecalendar').
     * @param string $responseType The response format type (e.g., 'JSON', 'XML', 'ICS', 'YML').
     * @param ConnectionInterface $to The connection to which messages about the validation process are sent.
     * @param ?string $riteHint The `rite` property of the incoming message, if any.
     * @param ?string $requestId The correlation id this request's frames must carry back, captured by the caller.
     *
     * This function retrieves the calendar data from a remote source based on the given parameters
     * and validates it against the appropriate schema. It supports multiple response types, including
     * XML, ICS, YML, and JSON. Validation results are sent as messages to the provided connection interface.
     */
    private function validateCalendar(string $calendar, int $year, string $category, string $responseType, ConnectionInterface $to, ?string $riteHint = null, ?string $requestId = null): void
    {
        $runToken        = $this->resolveRunToken($to);
        $returnTypeParam = ReturnTypeParam::from($responseType);
        $acceptMimeType  = $returnTypeParam->toAcceptMimeType();
        $opts            = [
            'headers' => [
                'Accept' => $acceptMimeType->value
            ],
            'stream'  => true
        ];

        $req     = $this->buildCalendarRequestPath($calendar, $year, $category, $this->resolveRite($calendar, $category, $riteHint));
        $promise = $this->cachedGet(Route::CALENDAR->path() . $req, $opts, 300, $to);
        $promise->then(
            function (array $result) use ($to, $calendar, $year, $category, $req, $responseType, $runToken, $requestId) {
                /** @var array{data: string, status: int, fromCache: bool, retryAfter: ?string} $result */
                $data       = $result['data'];
                $status     = $result['status'];
                $fromCache  = $result['fromCache'];
                $retryAfter = $result['retryAfter'];
                echo 'Fetched data for ' . Route::CALENDAR->path() . $req . ': got ' . strlen($data) . " bytes, HTTP $status" . ( $fromCache ? ' (from cache)' : '' ) . "\n";

                // #833: `'http_errors' => false` means a 4xx/5xx resolves this promise rather than
                // rejecting it, so `exists` must not be reported as passed without checking the status
                // that resolved it. A refusal means none of the three published steps were established
                // — not just the first — so all three are reported failed here rather than skipping
                // straight to `complete`, per #821's one-frame-per-step contract. 429/5xx reach this
                // gate too (#834): they used to be rejected separately, but nothing left downstream of
                // this gate can be misled by their body any more, so they are reported the same way as
                // any other refusal — including the `Retry-After` hint, when the response carried one.
                if (false === self::isHttpSuccessStatus($status)) {
                    $detail  = self::problemDetailFromBody($data);
                    $suffix  = $detail !== null ? ": $detail" : '';
                    $suffix .= $retryAfter !== null ? " (Retry-After: $retryAfter)" : '';
                    $this->sendCalendarStepResult(
                        $to,
                        $calendar,
                        $year,
                        Step::EXISTS,
                        Status::FAIL,
                        "The $category of $calendar for the year $year was refused with HTTP $status$suffix",
                        runToken: $runToken,
                        requestId: $requestId
                    );
                    $this->sendCalendarStepResult(
                        $to,
                        $calendar,
                        $year,
                        Step::PARSES,
                        Status::FAIL,
                        "Could not decode the $category of $calendar for the year $year as $responseType because the request for it returned HTTP $status",
                        // Every sibling `parses`-fail frame in the switch below carries `responsetype`
                        // (JSON/XML/ICS/YAML decode failures all do) — this gate short-circuits before
                        // the switch, but it is reporting the very same phase, so it must match.
                        responseType: $responseType,
                        runToken: $runToken,
                        requestId: $requestId
                    );
                    $this->sendCalendarStepResult(
                        $to,
                        $calendar,
                        $year,
                        Step::VALIDATES,
                        Status::FAIL,
                        "Unable to verify schema for the $category of $calendar for the year $year since the request for it returned HTTP $status",
                        runToken: $runToken,
                        requestId: $requestId
                    );
                    $this->sendComplete($to, target: self::frameTarget($calendar, ['year' => $year]), runToken: $runToken, requestId: $requestId);
                    return;
                }

                $this->sendCalendarStepResult(
                    $to,
                    $calendar,
                    $year,
                    Step::EXISTS,
                    Status::PASS,
                    "The $category of $calendar for the year $year exists",
                    runToken: $runToken,
                    requestId: $requestId
                );

                switch ($responseType) {
                    case 'XML':
                        libxml_use_internal_errors(true);
                        $xmlArr     = explode("\n", $data);
                        $xml        = new \DOMDocument();
                        $loadResult = $xml->loadXML($data);
                        //$xml = simplexml_load_string( $data );
                        if ($loadResult === false) {
                            // The list is bound before it is joined, so `text` and `details` are the
                            // same errors and cannot drift apart.
                            $xmlErrors = self::retrieveXmlErrors(libxml_get_errors(), $xmlArr);
                            libxml_clear_errors();
                            $this->sendCalendarStepResult(
                                $to,
                                $calendar,
                                $year,
                                Step::PARSES,
                                Status::FAIL,
                                "There was an error decoding the $category of $calendar for the year $year from the URL "
                                    . Route::CALENDAR->path() . $req . ' as XML: ' . implode('&#013;', $xmlErrors),
                                details: $xmlErrors,
                                responseType: $responseType,
                                runToken: $runToken,
                                requestId: $requestId
                            );
                        } else {
                            $this->sendCalendarStepResult(
                                $to,
                                $calendar,
                                $year,
                                Step::PARSES,
                                Status::PASS,
                                "The $category of $calendar for the year $year was successfully decoded as XML",
                                runToken: $runToken,
                                requestId: $requestId
                            );

                            // Always validate against schema (even for cached responses) since this is a test endpoint
                            $validationResult = $xml->schemaValidate(JsonData::SCHEMAS_FOLDER->path() . '/LiturgicalCalendar.xsd');
                            if ($validationResult) {
                                $this->sendCalendarStepResult(
                                    $to,
                                    $calendar,
                                    $year,
                                    Step::VALIDATES,
                                    Status::PASS,
                                    sprintf(
                                        "The $category of $calendar for the year $year was successfully validated against the Schema %s%s",
                                        JsonData::SCHEMAS_FOLDER->path() . '/LiturgicalCalendar.xsd',
                                        $fromCache ? ' (cached)' : ''
                                    ),
                                    runToken: $runToken,
                                    requestId: $requestId
                                );
                            } else {
                                $xmlErrors = self::retrieveXmlErrors(libxml_get_errors(), $xmlArr);
                                libxml_clear_errors();
                                $this->sendCalendarStepResult(
                                    $to,
                                    $calendar,
                                    $year,
                                    Step::VALIDATES,
                                    Status::FAIL,
                                    implode('&#013;', $xmlErrors),
                                    details: $xmlErrors,
                                    runToken: $runToken,
                                    requestId: $requestId
                                );
                            }
                        }
                        break;
                    case 'ICS':
                        try {
                            $vcalendar = VObject\Reader::read($data);
                        } catch (VObject\ParseException $e) {
                            $vcalendar = json_encode($e);
                        }
                        if ($vcalendar instanceof VObject\Document) {
                            $this->sendCalendarStepResult(
                                $to,
                                $calendar,
                                $year,
                                Step::PARSES,
                                Status::PASS,
                                "The $category of $calendar for the year $year was successfully decoded as ICS",
                                runToken: $runToken,
                                requestId: $requestId
                            );

                            // Always validate against schema (even for cached responses) since this is a test endpoint
                            $result = $vcalendar->validate();
                            if (count($result) === 0) {
                                $this->sendCalendarStepResult(
                                    $to,
                                    $calendar,
                                    $year,
                                    Step::VALIDATES,
                                    Status::PASS,
                                    sprintf(
                                        "The $category of $calendar for the year $year was successfully validated according the iCalendar Schema %s%s",
                                        'https://tools.ietf.org/html/rfc5545',
                                        $fromCache ? ' (cached)' : ''
                                    ),
                                    runToken: $runToken,
                                    requestId: $requestId
                                );
                            } else {
                                // The per-error list is what the text is built from, so it goes out as
                                // `details` rather than leaving a client to re-split the joined string.
                                $icsErrors = $this->formatIcsValidationErrors($result);
                                $this->sendCalendarStepResult(
                                    $to,
                                    $calendar,
                                    $year,
                                    Step::VALIDATES,
                                    Status::FAIL,
                                    implode('&#013;', $icsErrors),
                                    details: $icsErrors,
                                    runToken: $runToken,
                                    requestId: $requestId
                                );
                            }
                        } else {
                            $this->sendCalendarStepResult(
                                $to,
                                $calendar,
                                $year,
                                Step::PARSES,
                                Status::FAIL,
                                "There was an error decoding the $category of $calendar for the year $year from the URL "
                                    . Route::CALENDAR->path() . $req . ' as ICS: parsing resulted in type ' . gettype($vcalendar) . ' | ' . $vcalendar,
                                responseType: $responseType,
                                runToken: $runToken,
                                requestId: $requestId
                            );
                        }
                        break;
                    case 'YML':
                        try {
                            $yamlParsed = Yaml::parse($data);
                            if (false === is_array($yamlParsed) || empty($yamlParsed)) {
                                throw new \Exception('YAML parsing failed: expected a non-empty associative array');
                            }

                            $jsonEncoded = json_encode($yamlParsed, JSON_THROW_ON_ERROR);
                            $yamlData    = json_decode($jsonEncoded, false, 512, JSON_THROW_ON_ERROR);
                            if (!( $yamlData instanceof \stdClass )) {
                                throw new \Exception('YAML parsing failed: expected an object mapping, got ' . gettype($yamlData));
                            }

                            $this->sendCalendarStepResult(
                                $to,
                                $calendar,
                                $year,
                                Step::PARSES,
                                Status::PASS,
                                "The $category of $calendar for the year $year was successfully decoded as YAML",
                                runToken: $runToken,
                                requestId: $requestId
                            );

                            // Always validate against schema (even for cached responses) since this is a test endpoint
                            $validationResult = $this->validateDataAgainstSchema($yamlData, LitSchema::LITCAL->path());
                            if (gettype($validationResult) === 'boolean' && $validationResult === true) {
                                $cachedNote = $fromCache ? ' (cached)' : '';
                                $this->sendCalendarStepResult(
                                    $to,
                                    $calendar,
                                    $year,
                                    Step::VALIDATES,
                                    Status::PASS,
                                    "The $category of $calendar for the year $year was successfully validated against the Schema " . LitSchema::LITCAL->path() . $cachedNote,
                                    runToken: $runToken,
                                    requestId: $requestId
                                );
                            } elseif ($validationResult instanceof \stdClass) {
                                $this->sendSchemaFailureStepResult($to, $calendar, $year, $validationResult, $runToken, requestId: $requestId);
                            }
                        } catch (\Throwable $e) {
                            $this->sendCalendarStepResult(
                                $to,
                                $calendar,
                                $year,
                                Step::PARSES,
                                Status::FAIL,
                                "There was an error decoding the $category of $calendar for the year $year from the URL "
                                    . Route::CALENDAR->path() . $req . ' as YAML: ' . $e->getMessage(),
                                responseType: $responseType,
                                runToken: $runToken,
                                requestId: $requestId
                            );
                        }
                        break;
                    case 'JSON':
                    default:
                        $jsonData         = json_decode($data);
                        $jsonLastError    = json_last_error();
                        $jsonLastErrorMsg = json_last_error_msg();
                        if (false === ( $jsonData instanceof \stdClass ) || $jsonLastError !== JSON_ERROR_NONE) {
                            $decodeErrorText = "There was an error decoding the $category of $calendar for the year $year from the URL "
                                            . Route::CALENDAR->path() . $req . ' as JSON: data was decoded to type ' . gettype($jsonData);
                            if ($jsonLastError !== JSON_ERROR_NONE) {
                                $decodeErrorText .= ' | ' . $jsonLastErrorMsg;
                            }
                            $this->sendCalendarStepResult(
                                $to,
                                $calendar,
                                $year,
                                Step::PARSES,
                                Status::FAIL,
                                $decodeErrorText,
                                responseType: $responseType,
                                runToken: $runToken,
                                requestId: $requestId
                            );
                            break;
                        }

                        if (
                            false === property_exists($jsonData, 'litcal')
                            || false === property_exists($jsonData, 'settings')
                            || false === property_exists($jsonData, 'metadata')
                            || false === property_exists($jsonData, 'messages')
                        ) {
                            $this->sendCalendarStepResult(
                                $to,
                                $calendar,
                                $year,
                                Step::PARSES,
                                Status::FAIL,
                                "There was an error decoding the $category of $calendar for the year $year from the URL "
                                                    . Route::CALENDAR->path() . $req . ' as JSON: response data was perhaps truncated?',
                                responseType: $responseType,
                                runToken: $runToken,
                                requestId: $requestId
                            );
                            break;
                        }

                        $this->sendCalendarStepResult(
                            $to,
                            $calendar,
                            $year,
                            Step::PARSES,
                            Status::PASS,
                            "The $category of $calendar for the year $year was successfully decoded as JSON",
                            runToken: $runToken,
                            requestId: $requestId
                        );

                        // Always validate against schema (even for cached responses) since this is a test endpoint
                        $validationResult = $this->validateDataAgainstSchema($jsonData, LitSchema::LITCAL->path());
                        if (gettype($validationResult) === 'boolean' && $validationResult === true) {
                            $cachedNote = $fromCache ? ' (cached)' : '';
                            $this->sendCalendarStepResult(
                                $to,
                                $calendar,
                                $year,
                                Step::VALIDATES,
                                Status::PASS,
                                "The $category of $calendar for the year $year was successfully validated against the Schema " . LitSchema::LITCAL->path() . $cachedNote,
                                runToken: $runToken,
                                requestId: $requestId
                            );
                        } elseif ($validationResult instanceof \stdClass) {
                            $this->sendSchemaFailureStepResult($to, $calendar, $year, $validationResult, $runToken, requestId: $requestId);
                        }
                }

                // After the switch, so that every response format and every arm inside it — a parse
                // failure that `break`s out early as much as a full three-step pass — leaves through
                // the same terminal frame.
                $this->sendComplete($to, target: self::frameTarget($calendar, ['year' => $year]), runToken: $runToken, requestId: $requestId);
            },
            function (\Throwable $e) use ($to, $calendar, $year, $category, $req, $runToken, $requestId) {
                $this->sendCalendarStepResult(
                    $to,
                    $calendar,
                    $year,
                    Step::EXISTS,
                    Status::FAIL,
                    "The $category of $calendar for the year $year does not exist at the URL " . Route::CALENDAR->path() . $req . ' : ' . $e->getMessage(),
                    runToken: $runToken,
                    requestId: $requestId
                );

                $this->sendComplete($to, target: self::frameTarget($calendar, ['year' => $year]), runToken: $runToken, requestId: $requestId);
            }
        );
    }

    /**
     * Formats the schema-validation errors returned by Sabre\VObject's
     * Document::validate() into human-readable strings for the health-check
     * WebSocket messages (one entry per error: "<level>: <message> at line
     * <index> (<source line>)").
     *
     * @param array<array-key, mixed> $result The value returned by Document::validate().
     * @return list<string>
     */
    private function formatIcsValidationErrors(array $result): array
    {
        $errorStrings = [];
        foreach ($result as $error) {
            // sabre/vobject 5.0.0 declares Node/Property as a (non-generic) IteratorAggregate,
            // so PHPStan now flags the `node` shape type as an iterable without a value type.
            // Property is not @template-generic, so no value type can be supplied here.
            /** @var array{level:int,message:string,node:VObject\Property} $error */
            $errorLevel = new ICSErrorLevel($error['level']); // @phpstan-ignore missingType.iterableValue
            /** @var int $lineIndex The type is obvious, and declared, yet PHPStan seems to be a bit dumb on this one? */
            $lineIndex = $error['node']->lineIndex;
            /** @var string $lineString The type is obvious, and declared, yet PHPStan seems to be a bit dumb on this one? */
            $lineString     = $error['node']->lineString;
            $errorStrings[] = $errorLevel . ': ' . $error['message'] . " at line {$lineIndex} ({$lineString})";
        }
        return $errorStrings;
    }

    /**
     * Handle a `runTest` message: run one named unit test against a computed calendar.
     *
     * A translation layer in the same sense as {@see Health::validateTypedCalendar()}, and for the
     * same reason: it resolves the identity, type-checks the two scalars the property list can only
     * prove present, and hands the result to the unchanged {@see Health::executeUnitTest()}. Nothing
     * about *how* a test is run changes with the message shape, and this method exists so that
     * nothing about it has to.
     *
     * The resolved rite goes in the `$riteHint` slot, where `resolveRite()` treats a parsable value
     * as authoritative — correct here because {@see Health::resolveCalendarIdentity()} has already
     * checked it against what the server knows.
     *
     * **Not the same thing as checking the test definition.** `test:{rite}:{Name}` is an inventory id
     * addressed by {@see Health::validateSource()}, and it asks whether the definition file exists,
     * parses and validates against `LitCalTest.json`. `runTest` names the test by its bare name and
     * runs it. A definition can be valid while the test it describes fails, and a definition can be
     * malformed while the calendar is perfectly correct, so neither answer substitutes for the other.
     *
     * A leftover `category` is rejected here exactly as it is on `validateTypedCalendar()`, through
     * the shared {@see Health::rejectRetiredProperties()}. `runTest` has a mechanical predecessor in
     * `executeUnitTest`, which required `category`, so a client that renamed the action and kept the
     * property is as plausible here as anywhere — and `calendar.kind` supplies the category, so the
     * message would otherwise work while the client kept believing `category` selected something.
     * Only `category` is retired: `executeUnitTest` never had a `responsetype` to retire.
     *
     * @param RunTest $message The message naming the test, the calendar and the year.
     * @param ConnectionInterface $to The connection to send the result frames to.
     * @param ?string $requestId The correlation id this request's frames must carry back, captured by the caller.
     */
    private function runTest(\stdClass $message, ConnectionInterface $to, ?string $requestId = null): void
    {
        if ($this->rejectRetiredProperties($message, 'runTest', $to, requestId: $requestId)) {
            return;
        }

        // Checked rather than trusted for the reason readYear() sets out: a non-string here would
        // reach executeUnitTest(string $test, …) as a TypeError, which Ratchet does not catch.
        $test = property_exists($message, 'test') ? $message->test : null;
        if (false === is_string($test)) {
            $this->rejectMessage($to, ProtocolErrorCode::INVALID_MESSAGE, 'runTest test must be a string.', requestId: $requestId);
            return;
        }

        try {
            $identity = $this->readCalendarIdentity($message, 'runTest');
            $year     = self::readYear($message, 'runTest');
        } catch (\InvalidArgumentException $e) {
            $this->rejectMessage($to, ProtocolErrorCode::INVALID_MESSAGE, $e->getMessage(), requestId: $requestId);
            return;
        }

        $this->executeUnitTest(
            $test,
            $identity['calendar'],
            $year,
            $identity['category'],
            $to,
            $identity['rite']->value,
            requestId: $requestId
        );
    }

    /**
     * Emit the result frame for one test run.
     *
     * A test run is a single named outcome, not a pipeline: there is nothing to report between asking for
     * the calendar and knowing whether the assertion held. So it carries one step — `validates`, the same
     * word a schema check publishes, because that is what it is — and `status` says the rest.
     *
     * **`.test-valid` is unchanged, and is not an oddity.** It *addresses the validity box*; it does not
     * claim an outcome, and the client colours that box by `status`. #806 reads the class being the same
     * for a pass and a fail as a defect, but a class that encoded the outcome would be strictly worse: a
     * client would have to know the result in order to build the selector that finds the card to put the
     * result in. Renaming it is a breaking change, and belongs to legacy removal.
     *
     * A test's `target` is the test, the calendar and the year, because none of the three identifies a
     * check on its own — the same test runs against many calendars, and against many years of each. The
     * category and the rite are in scope here and are deliberately left out; see {@see Health::frameTarget()}.
     *
     * The address is `.{test}.year-{year}.test-valid`, the year *before* the step where a calendar
     * validation puts it after. That irregularity is a fact about the family and is declared as one
     * ({@see FrameFamily}), so this method passes `$year` as a value and, like every other emitter, never
     * sees a dot.
     *
     * The class fragment is the test name verbatim, as it has always been: test names are PascalCase file
     * names from `jsondata/tests/` and are already class-safe, and putting them through
     * {@see Health::cssClassFragmentForId()} would be a change to the wire dressed as tidying.
     *
     * @param ConnectionInterface $to The connection to send the frame to.
     * @param string $test The name of the unit test, e.g. `AllSaintsUSA`. Both the target's id and the class fragment.
     * @param string $calendar The calendar the test ran against, e.g. `US`.
     * @param int $year The year it ran for; rides in the class as `year-{year}`, before the step.
     * @param Status $status The outcome, which `type` is projected from.
     * @param string $text The human-facing message, passed through untouched.
     * @param ?list<string> $details The pieces behind a summarising `text`, or null when the site genuinely has none —
     *        which is both of today's sites: a JSON decode failure and an unreachable URL each have one thing to say,
     *        and per {@see Health::sendStepResult()} nothing structured is manufactured where none exists.
     * @param ?string $runToken The originating run token to echo back.
     * @param ?string $requestId The correlation id of the request that caused this frame, captured by the handler when
     *        the request arrived and passed explicitly rather than read from any per-connection store; see
     *        {@see Health::onMessage()} for why that distinction is load-bearing.
     */
    private function sendTestResult(
        ConnectionInterface $to,
        string $test,
        string $calendar,
        int $year,
        Status $status,
        string $text,
        ?array $details = null,
        ?string $runToken = null,
        ?string $requestId = null
    ): void {
        $this->sendStepResult(
            $to,
            $test,
            self::frameTarget($test, ['calendar' => $calendar, 'year' => $year]),
            Step::VALIDATES,
            $status,
            $text,
            details: $details,
            runToken: $runToken,
            requestId: $requestId,
            classQualifier: "year-$year",
            family: FrameFamily::TEST_RUN
        );
    }

    /**
     * Executes a unit test for a given Liturgical Calendar test.
     *
     * @param string $test The name of the unit test to be executed.
     * @param string $calendar The name of the calendar to be tested.
     * @param int $year The year for which the test should be executed.
     * @param string $category The type of calendar to be tested: nationalcalendar, diocesancalendar or ritecalendar.
     * @param ConnectionInterface $to The connection to which the test result should be sent.
     * @param ?string $riteHint The `rite` property of the incoming message, if any.
     * @param ?string $requestId The correlation id this request's frames must carry back, captured by the caller.
     */
    private function executeUnitTest(string $test, string $calendar, int $year, string $category, ConnectionInterface $to, ?string $riteHint = null, ?string $requestId = null): void
    {
        $runToken        = $this->resolveRunToken($to);
        $returnTypeParam = ReturnTypeParam::JSON;
        $acceptMimeType  = $returnTypeParam->toResponseContentType();
        $opts            = [
            'headers' => [
                'Accept' => $acceptMimeType->value
            ],
            'stream'  => true
        ];

        $rite    = $this->resolveRite($calendar, $category, $riteHint);
        $req     = $this->buildCalendarRequestPath($calendar, $year, $category, $rite);
        $promise = $this->cachedGet(Route::CALENDAR->path() . $req, $opts, 300, $to);
        $promise->then(
            function (array $result) use ($to, $test, $calendar, $year, $category, $req, $runToken, $requestId, $rite) {
                /** @var array{data: string, status: int, fromCache: bool, retryAfter: ?string} $result */
                $data       = $result['data'];
                $status     = $result['status'];
                $retryAfter = $result['retryAfter'];

                // #833: a refused calendar (e.g. a rite's year boundary) is valid JSON — a problem
                // document — so without this check it would decode cleanly and be handed to
                // LitTestRunner as if it were calendar data, which is worse than a plain failure: the
                // run would go on to answer whatever the test asserts, over data that never existed. A
                // test run carries one step, so the refusal is reported there rather than skipped.
                // 429/5xx reach this the same way as any other refusal (#834); their `Retry-After`
                // hint, when present, is folded into the same text rather than lost.
                if (false === self::isHttpSuccessStatus($status)) {
                    $detail  = self::problemDetailFromBody($data);
                    $suffix  = $detail !== null ? ": $detail" : '';
                    $suffix .= $retryAfter !== null ? " (Retry-After: $retryAfter)" : '';
                    $this->sendTestResult(
                        $to,
                        $test,
                        $calendar,
                        $year,
                        Status::FAIL,
                        "The $category of $calendar for the year $year was refused with HTTP $status$suffix at the URL " . Route::CALENDAR->path() . $req,
                        runToken: $runToken,
                        requestId: $requestId
                    );
                    $this->sendComplete($to, target: self::frameTarget($test, ['calendar' => $calendar, 'year' => $year]), runToken: $runToken, requestId: $requestId);
                    return;
                }

                /** @var \stdClass&object{settings:object{year:int,national_calendar?:string,diocesan_calendar?:string},litcal:LiturgicalEvent[]} $jsonData */
                $jsonData = json_decode($data);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $UnitTest = new LitTestRunner($test, $jsonData, $rite);
                    if ($UnitTest->isReady()) {
                        $UnitTest->runTest();
                    }
                    // The frame for a test that actually ran is built by LitTestRunner and leaves
                    // through the same funnel as every other one. `requestId` is handed to that
                    // funnel here, from the value this closure captured, exactly as `runToken` is:
                    // stamping it inside sendMessage() from a per-connection field would be the
                    // misattribution bug onMessage() describes, and passing through a shared funnel
                    // is not a reason to reach for one.
                    $this->sendMessage($to, $UnitTest->getMessage(), $runToken, requestId: $requestId);
                } else {
                    $this->sendTestResult(
                        $to,
                        $test,
                        $calendar,
                        $year,
                        Status::FAIL,
                        "There was an error decoding JSON data for the test $test: " . json_last_error_msg(),
                        runToken: $runToken,
                        requestId: $requestId
                    );
                }

                // A test run is one named outcome rather than a pipeline, and it terminates the same
                // way a three-step check does: the client's phase logic must not have to know which
                // kind it asked for. Emitted for the LitTestRunner frame above as well as for the
                // decode failure, since both are the end of this request.
                $this->sendComplete($to, target: self::frameTarget($test, ['calendar' => $calendar, 'year' => $year]), runToken: $runToken, requestId: $requestId);
            },
            function (\Throwable $e) use ($to, $test, $year, $category, $calendar, $req, $runToken, $requestId) {
                $this->sendTestResult(
                    $to,
                    $test,
                    $calendar,
                    $year,
                    Status::FAIL,
                    "The $category of $calendar for the year $year was not retrieved at the URL " . Route::CALENDAR->path() . $req . ' : ' . $e->getMessage(),
                    runToken: $runToken,
                    requestId: $requestId
                );

                $this->sendComplete($to, target: self::frameTarget($test, ['calendar' => $calendar, 'year' => $year]), runToken: $runToken, requestId: $requestId);
            }
        );
    }

    /**
     * Validate data against a specified schema.
     *
     * @param mixed $data The data to validate.
     * @param string $schemaUrl The URL of the schema to validate against.
     *
     * @return bool|\stdClass Returns true if the data is valid, otherwise returns an error object with details.
     */
    private function validateDataAgainstSchema(mixed $data, string $schemaUrl): bool|\stdClass
    {
        $res = false;
        try {
            $schema = Schema::import($schemaUrl);
            $schema->in($data);
            $res = true;
        } catch (\Throwable $e) {
            $litSchema     = LitSchema::fromURL($schemaUrl);
            $message       = new \stdClass();
            $message->type = 'error';
            $message->text = $litSchema->error() . PHP_EOL . $e->getMessage();
            return $message;
        }
        return $res;
    }

    /**
     * Handle Redis connection failure by falling back to APCu if available.
     */
    private static function handleRedisFailure(\RedisException $e): void
    {
        echo "Redis connection lost: {$e->getMessage()}, falling back to APCu\n";
        self::$redis = null;
        // Use the same comprehensive APCu check as initialization
        $apcuAvailable = extension_loaded('apcu')
            && function_exists('apcu_exists')
            && function_exists('apcu_store')
            && function_exists('apcu_fetch');
        if ($apcuAvailable) {
            self::$cacheBackend = 'apcu';
            echo "APCu fallback enabled\n";
        } else {
            self::$cacheBackend = 'none';
            self::$cacheEnabled = false;
            echo "No cache backend available, caching disabled\n";
        }
    }

    /**
     * Check if a key exists in the cache.
     */
    private static function cacheExists(string $key): bool
    {
        if (!self::$cacheEnabled) {
            return false;
        }
        if (self::$cacheBackend === 'redis' && self::$redis !== null) {
            try {
                return (bool) self::$redis->exists($key);
            } catch (\RedisException $e) {
                self::handleRedisFailure($e);
                // Retry with APCu if now available
                if (self::$cacheBackend === 'apcu') {
                    return apcu_exists($key);
                }
                return false;
            }
        }
        if (self::$cacheBackend === 'apcu') {
            return apcu_exists($key);
        }
        return false;
    }

    /**
     * Get a value from the cache.
     *
     * @return array{0: bool, 1: string|null} [success, data]
     */
    private static function cacheGet(string $key): array
    {
        if (!self::$cacheEnabled) {
            return [false, null];
        }
        if (self::$cacheBackend === 'redis' && self::$redis !== null) {
            try {
                $data = self::$redis->get($key);
                if ($data === false || !is_string($data)) {
                    return [false, null];
                }
                return [true, $data];
            } catch (\RedisException $e) {
                self::handleRedisFailure($e);
                // Retry with APCu if now available
                if (self::$cacheBackend === 'apcu') {
                    $data = apcu_fetch($key, $success);
                    if ($success && is_string($data)) {
                        return [true, $data];
                    }
                }
                return [false, null];
            }
        }
        if (self::$cacheBackend === 'apcu') {
            $data = apcu_fetch($key, $success);
            if ($success && is_string($data)) {
                return [true, $data];
            }
            return [false, null];
        }
        return [false, null];
    }

    /**
     * Store a value in the cache.
     */
    private static function cacheSet(string $key, string $value, int $ttl): bool
    {
        if (!self::$cacheEnabled) {
            return false;
        }
        if (self::$cacheBackend === 'redis' && self::$redis !== null) {
            try {
                return self::$redis->setex($key, $ttl, $value);
            } catch (\RedisException $e) {
                self::handleRedisFailure($e);
                // Retry with APCu if now available
                if (self::$cacheBackend === 'apcu') {
                    return apcu_store($key, $value, $ttl);
                }
                return false;
            }
        }
        if (self::$cacheBackend === 'apcu') {
            return apcu_store($key, $value, $ttl);
        }
        return false;
    }

    /**
     * Delete a key from the cache.
     *
     * Used when a stored entry cannot be decoded back into a usable value (#834): a malformed entry
     * — including the bare-body shape every entry had before #833 — must not be left in place to be
     * hit again on the very next request. Removing it lets the following request treat this key as a
     * plain miss instead of standing rejection for the rest of the TTL window.
     */
    private static function cacheDelete(string $key): void
    {
        if (!self::$cacheEnabled) {
            return;
        }
        if (self::$cacheBackend === 'redis' && self::$redis !== null) {
            try {
                self::$redis->del($key);
                return;
            } catch (\RedisException $e) {
                self::handleRedisFailure($e);
                // Retry with APCu if now available
                if (self::$cacheBackend === 'apcu') {
                    apcu_delete($key);
                }
                return;
            }
        }
        if (self::$cacheBackend === 'apcu') {
            apcu_delete($key);
        }
    }

    /**
     * Get cache memory info string for logging.
     */
    private static function cacheInfo(): string
    {
        if (!self::$cacheEnabled) {
            return 'Cache disabled';
        }
        if (self::$cacheBackend === 'redis' && self::$redis !== null) {
            try {
                $info = self::$redis->info('memory');
                if (is_array($info) && isset($info['used_memory'], $info['maxmemory'])) {
                    $usedRaw = $info['used_memory'];
                    $maxRaw  = $info['maxmemory'];
                    $used    = is_numeric($usedRaw) ? (int) $usedRaw : 0;
                    $max     = is_numeric($maxRaw) ? (int) $maxRaw : 0;
                    if ($max > 0) {
                        $percent = ( $used / $max ) * 100;
                        return 'Redis used: ' . round($used / 1024 / 1024, 2) . ' MB of ' .
                            round($max / 1024 / 1024, 2) . ' MB (' . round($percent, 2) . '%)';
                    }
                    return 'Redis used: ' . round($used / 1024 / 1024, 2) . ' MB (no maxmemory limit)';
                }
            } catch (\RedisException $e) {
                return 'Redis info error: ' . $e->getMessage();
            }
            return 'Redis info unavailable';
        }
        if (self::$cacheBackend === 'apcu') {
            /** @var array{seg_size:int,avail_mem:int}|false $info */
            $info = apcu_sma_info(true);
            if (false !== $info) {
                $total = isset($info['seg_size']) ? (int) $info['seg_size'] : 0;
                $free  = isset($info['avail_mem']) ? (int) $info['avail_mem'] : 0;
                if ($total > 0) {
                    $used    = $total - $free;
                    $percent = ( $used / $total ) * 100;
                    return 'APCu used: ' . round($used / 1024 / 1024, 2) . ' MB of ' .
                        round($total / 1024 / 1024, 2) . ' MB (' . round($percent, 2) . '%)';
                }
            }
            return 'APCu info unavailable';
        }
        return 'No cache backend';
    }

    /**
     * @return PromiseInterface<array{data: string, fromCache: bool}>
     */
    private function cachedFileGetContents(string $path, int $ttl = 300): PromiseInterface
    {
        $key = 'fgc_' . md5($path);

        // Use futureTick to allow event loop to process other events
        if (self::$cacheEnabled && self::cacheExists($key)) {
            $deferred         = new Deferred();
            [$success, $data] = self::cacheGet($key);
            if ($success && is_string($data)) {
                echo "Cache hit for file $path\n";
                // Schedule resolution via event loop to prevent blocking
                Loop::futureTick(function () use ($deferred, $data) {
                    $deferred->resolve(['data' => $data, 'fromCache' => true]);
                });
            } else {
                $deferred->reject(new \RuntimeException("Cache fetch for file $path returned non-string data"));
            }
            /** @var PromiseInterface<array{data: string, fromCache: bool}> $deferredPromise */
            $deferredPromise = $deferred->promise();
            return $deferredPromise;
        }

        if (self::$cacheEnabled) {
            echo "Cache miss for file $path, reading from filesystem\n";
        }

        // Stat before reading, because the read cannot tell absence from emptiness (#822).
        // `react/filesystem`'s Fallback adapter — always the one in play here, since neither
        // ext-eio nor ext-uv is a dependency of this project — resolves a missing file with zero
        // bytes rather than rejecting, and emits three PHP warnings on the way. Every caller then
        // ran its success path over an empty string: `file-exists` reported success for a file that
        // is not there, and the two steps after it failed for a reason that was not the real one.
        // A green "exists" above two red boxes reads as "the file is there but its contents are
        // broken", which sends whoever is debugging to the wrong file — worse than a plain failure.
        //
        // The check is explicit rather than a matter of letting the adapter reject, so that the
        // answer does not depend on which optional extension happens to be installed: with ext-eio
        // present the read would reject on its own, and behaviour that changes with the build is
        // worse than behaviour that does not. `is_file()` also rules out a directory, which
        // `is_readable()` alone would let through to the same zero-byte resolution.
        //
        // `Health` is a long-running process and PHP caches stat results per filename, including
        // negative ones. A file written by a `/data` request after an earlier check looked for it
        // would otherwise keep reporting absent for the life of the process.
        clearstatcache(true, $path);
        if (!is_file($path) || !is_readable($path)) {
            $reason   = is_file($path) ? 'file is not readable' : 'no such file';
            $deferred = new Deferred();
            $deferred->reject(new \RuntimeException("Unable to read file: $path ($reason)"));
            /** @var PromiseInterface<array{data: string, fromCache: bool}> $missingPromise */
            $missingPromise = $deferred->promise();
            return $missingPromise;
        }

        $filesystem = Factory::create();

        /** @var PromiseInterface<array{data: string, fromCache: bool}> $fsPromise */
        $fsPromise = $filesystem->file($path)->getContents()->then(
            /** @return array{data: string, fromCache: bool} */
            function (string $data) use ($key, $ttl, $path): array {
                $data = (string) $data;          // force fresh string
                if (self::$cacheEnabled) {
                    echo "Read file $path, caching contents\n";
                    $stored = self::cacheSet($key, $data, $ttl);
                    echo ( $stored ? "Stored file in cache\n" : "Failed to store file in cache\n" );
                    echo self::cacheInfo() . "\n";
                }
                return ['data' => $data, 'fromCache' => false]; // resolved promise
            },
            function (\Throwable $e) use ($path): never {
                throw new \RuntimeException("Unable to read file: $path", 0, $e);
            }
        );

        return $fsPromise;
    }

    /**
     * @param array{headers?:array<string, string>,stream?:bool} $options
     *
     * @return PromiseInterface<array{data: string, status: int, fromCache: bool, retryAfter: ?string}>
     */
    private function cachedGet(string $url, array $options = [], int $ttl = 300, ?ConnectionInterface $conn = null): PromiseInterface
    {
        $key = 'http_' . md5($url . serialize($options));

        /** @var Deferred<array{data: string, status: int, fromCache: bool, retryAfter: ?string}> $deferred */
        $deferred = new Deferred();

        // Return from cache if available - stagger resolutions to stream results back gradually
        if (self::$cacheEnabled && self::cacheExists($key)) {
            echo "Cache hit for $url\n";
            [$success, $raw] = self::cacheGet($key);
            // The cache stores `{"status":…,"body":…}` (see handleHttpResponse()), not the bare body,
            // precisely so a cache hit can answer the same question a live fetch just did: #833 was a
            // 400 that resolved as a pass on the *first* run because the status was never propagated at
            // all, and it would have resolved as a pass again on the *second* run — from cache — had the
            // status not been stored alongside the body it is decoded from here. Decoding is extracted
            // to {@see Health::decodeHttpCacheEntry()} so this contract is unit-testable on its own,
            // without a real cache backend or the ReactPHP loop {@see HealthHelpersTest} covers it.
            $decoded = ( $success && is_string($raw) ) ? self::decodeHttpCacheEntry($raw) : null;
            if (null !== $decoded) {
                $data   = $decoded['body'];
                $status = $decoded['status'];
                // Stagger cached responses: resolve in small batches using incremental delays
                // This prevents all cache hits from resolving in a single tick
                $connId                          = $conn !== null && is_int($conn->resourceId) ? $conn->resourceId : 0;
                $counter                         = $this->cacheHitCounters[$connId] ?? 0;
                $delay                           = floor($counter / max(1, $this->maxConcurrency)) * $this->staggerInterval;
                $this->cacheHitCounters[$connId] = $counter + 1;
                $resolveIfOpen                   = function () use ($deferred, $data, $status, $conn) {
                    // Skip resolving if the client has disconnected while waiting
                    if ($conn !== null && !$this->clients->contains($conn)) {
                        return;
                    }
                    // A cached entry is never a 429/5xx (handleHttpResponse() excludes those from the
                    // cache), so there is no Retry-After to carry forward here.
                    $deferred->resolve(['data' => $data, 'status' => $status, 'fromCache' => true, 'retryAfter' => null]);
                };
                if ($delay > 0) {
                    Loop::addTimer($delay, $resolveIfOpen);
                } else {
                    Loop::futureTick($resolveIfOpen);
                }

                /** @var PromiseInterface<array{data: string, status: int, fromCache: bool, retryAfter: ?string}> $deferredPromise */
                $deferredPromise = $deferred->promise();
                return $deferredPromise;
            }

            // A cache hit whose entry cannot be decoded — malformed, or written in the bare-body
            // shape a pre-#833 process left behind — is not a hit at all: it must not be answered
            // with a rejection, which is what this arm did until a review of #834 caught it. Doing so
            // fails every check whose entry predates this fix (all three published steps, via
            // handleValidationDataError() and friends) for the entire TTL window, which is exactly
            // the false-failure mirror of the bug this file fixes, landing right at the moment of
            // deploy. The stale bytes are removed and control falls through to the live-request path
            // below, exactly as a plain cache miss would.
            echo "Cache entry for $url could not be decoded, treating as a miss and re-fetching\n";
            self::cacheDelete($key);
        }

        if (self::$cacheEnabled) {
            echo "Cache miss for $url, making HTTP request\n";
        }


        $resolve = function (ResponseInterface $response) use ($deferred, $key, $ttl, $url) {
            --$this->inFlight;
            self::handleHttpResponse($response, $deferred, $key, $ttl, $url);
        };

        $reject = function (\Throwable $e) use ($deferred) {
            echo 'HTTP request failed: ' . $e->getMessage() . "\n";
            --$this->inFlight;
            $deferred->reject($e);
        };

        // Tag the queued request with its originating run so processQueue() can drop it if the run
        // is later superseded (client stopped/restarted). The connection's stored token is still the
        // current run's here, since cachedGet() is called synchronously while handling that request.
        $queuedResourceId = ( $conn !== null && is_int($conn->resourceId) ) ? $conn->resourceId : null;
        $queuedRunToken   = $queuedResourceId !== null ? ( $this->runTokens[$queuedResourceId] ?? null ) : null;

        $this->queue[] = [
            'url'        => $url,
            'options'    => $options,
            'resolve'    => $resolve,
            'reject'     => $reject,
            'resourceId' => $queuedResourceId,
            'runToken'   => $queuedRunToken
        ];

        /** @var PromiseInterface<array{data: string, status: int, fromCache: bool, retryAfter: ?string}> $deferredPromise */
        $deferredPromise = $deferred->promise();
        $this->ensureTicking();
        return $deferredPromise;
    }

    /**
     * Handle a fulfilled HTTP response for a queued {@see cachedGet} request: resolve the deferred
     * with the real status and body, caching them (when caching is enabled) unless the status is a
     * rate-limit (429) or server error (5xx). Extracted from the cachedGet fulfillment closure so the
     * status/cache branching is unit-testable without the Ratchet/Guzzle event loop; the caller is
     * responsible for the inFlight bookkeeping.
     *
     * **Every status resolves; none reject (#833, revised by #834's review).** `'http_errors' =>
     * false` on the Guzzle client already means a 4xx or 5xx never rejects this promise on its own —
     * this method used to carve out 429/5xx as an exception and reject those specifically, reasoning
     * that a 429's RFC 9457 problem+json body "decodes fine but lacks the calendar keys, which the
     * JSON branch would otherwise mislabel as 'response data was perhaps truncated?'". That reasoning
     * no longer holds: every consumer of `cachedGet()` now gates on {@see Health::isHttpSuccessStatus()}
     * *before* any JSON-shape check runs, for exactly this reason, so the mislabelling the rejection
     * was written to prevent cannot happen regardless of whether this method resolves or rejects. With
     * the original reason gone, rejecting 429/5xx separately is pure inconsistency: it makes those two
     * statuses arrive through a different frame-emission path (an exception message assembled here)
     * than every other non-2xx (the uniform `exists`/`parses`/`validates` gate), for no remaining
     * benefit — and it is why they were still missing the problem document's `detail` that #833 gives
     * every other refusal. They are resolved uniformly here instead, so the gate reports them exactly
     * like a 400 or 404.
     *
     * **`Retry-After` is still carried, not dropped.** It is genuinely useful and was previously only
     * visible on the rejection's exception message; it now rides on the resolved value's `retryAfter`
     * key so a consumer can fold it into the `exists` failure text it already builds.
     *
     * **429/5xx are still excluded from the cache.** That part of the original decision stands on its
     * own merit, independent of the rejection question: a rate limit or a server outage is transient
     * information about *this moment*, not a fact about the resource, and caching it would have a
     * request made one second after the outage ended keep reporting the outage for the rest of the TTL.
     *
     * @param Deferred<array{data: string, status: int, fromCache: bool, retryAfter: ?string}> $deferred
     */
    private static function handleHttpResponse(
        ResponseInterface $response,
        Deferred $deferred,
        string $key,
        int $ttl,
        string $url
    ): void {
        $body       = (string) $response->getBody();
        $bodyLength = strlen($body);
        echo "HTTP request completed for $url\n";
        if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development') {
            $date          = date('Y-m-d_H-i-s-u');
            $color         = $response->getStatusCode() >= 400 ? self::RED : self::GREEN;
            $debugMessage  = self::YELLOW . 'RESPONSE HTTP/' . $response->getProtocolVersion() . ' ' . $color . $response->getStatusCode() . ' ' . $response->getReasonPhrase() . " received from URL {$url}" . self::NC . PHP_EOL;
            $debugMessage .= PHP_EOL;
            $debugMessage .= self::BLUE . 'Incoming response headers' . self::NC . PHP_EOL;
            foreach ($response->getHeaders() as $name => $values) {
                $debugMessage .= $name . ': ' . implode(', ', $values) . PHP_EOL;
            };
            $debugMessage .= PHP_EOL;
            $debugMessage .= self::BLUE . "Incoming response body ({$bodyLength} bytes)" . self::NC . PHP_EOL;
            $debugMessage .= $body . PHP_EOL . PHP_EOL;
            file_put_contents(Router::$apiFilePath . 'logs' . DIRECTORY_SEPARATOR . "websocket_response_{$date}.log", $debugMessage);
        }

        $statusCode      = $response->getStatusCode();
        $retryAfterValue = $response->getHeaderLine('Retry-After');
        $retryAfter      = '' !== $retryAfterValue ? $retryAfterValue : null;

        // The status is cached alongside the body, not just resolved alongside it: a consumer that
        // asked for this URL before is entitled to the same answer a live request would give it.
        // 429/5xx are excluded — see the docblock above for why that exclusion still stands even
        // though these statuses are no longer rejected. Every other status was already being cached
        // before #833 — a 400 or 404 flows through so the per-format validation can report it at the
        // json-valid phase, now gated behind the `exists` check the resolved status makes possible.
        if (self::$cacheEnabled && !self::isUpstreamFailureStatus($statusCode)) {
            $cachePayload = self::encodeHttpCacheEntry($statusCode, $body);
            if (null !== $cachePayload) {
                $stored = self::cacheSet($key, $cachePayload, $ttl);
                echo ( $stored ? "Stored response body in cache\n" : "Failed to store response body in cache\n" );
                echo self::cacheInfo() . "\n";
            } else {
                echo "Failed to encode response body for caching (not valid UTF-8?), skipping cache write\n";
            }
        }
        $deferred->resolve(['data' => $body, 'status' => $statusCode, 'fromCache' => false, 'retryAfter' => $retryAfter]);
    }

    /**
     * Whether an upstream HTTP status must be kept out of the cache. Rate limiting (429) and server
     * errors (5xx) are transient facts about *this moment*, not about the resource, so caching one
     * would have a request made the instant an outage ends keep reporting the outage for the rest of
     * the TTL. Both statuses still resolve rather than reject (#834) and are reported through the same
     * `exists` gate as every other non-2xx — this only controls what gets written to the cache.
     */
    private static function isUpstreamFailureStatus(int $statusCode): bool
    {
        return $statusCode === 429 || $statusCode >= 500;
    }

    /**
     * Encode a fetched HTTP response for the cache: the status alongside the body, in one string, so
     * that a cache hit can answer #833's "does it exist" question exactly as a live fetch would.
     *
     * Extracted from {@see Health::handleHttpResponse()}, and paired with {@see Health::decodeHttpCacheEntry()}
     * below it, so the round trip is unit-testable on its own — without a real cache backend and
     * without the ReactPHP loop that `cachedGet()`'s cache-hit branch schedules its resolution through.
     *
     * Returns null when the body cannot be represented as JSON (e.g. invalid UTF-8) rather than
     * falling back to caching the bare body: a caller that cannot cache the status safely must not
     * cache at all, since a bare-body entry is exactly the pre-#833 shape that read back as an
     * unconditional pass.
     */
    private static function encodeHttpCacheEntry(int $status, string $body): ?string
    {
        $encoded = json_encode(['status' => $status, 'body' => $body]);
        return false !== $encoded ? $encoded : null;
    }

    /**
     * The inverse of {@see Health::encodeHttpCacheEntry()}.
     *
     * Returns null for anything that is not a well-formed `{"status":…,"body":…}` envelope —
     * including a bare body, which is what a cache entry written before #833 (or by anything else
     * that ever wrote to this key) would be. Guessing a status for that shape, e.g. defaulting to 200,
     * would silently reintroduce the exact bug #833 fixed the moment an old entry outlived a deploy;
     * treating it as absent instead makes the caller reject and re-fetch, which is always correct.
     *
     * @return array{status: int, body: string}|null
     */
    private static function decodeHttpCacheEntry(string $raw): ?array
    {
        $decoded = json_decode($raw, true);
        if (
            is_array($decoded)
            && isset($decoded['status'], $decoded['body'])
            && is_int($decoded['status'])
            && $decoded['status'] >= 100
            && $decoded['status'] <= 599
            && is_string($decoded['body'])
        ) {
            /** @var array{status: int, body: string} $decoded */
            return $decoded;
        }
        return null;
    }

    /**
     * Whether an HTTP status is a plain success. Consumers of {@see Health::cachedGet()} gate the
     * `exists` step on this (#833): `'http_errors' => false` on the Guzzle client means a 4xx/5xx
     * resolves rather than rejects, so without this check `exists` would pass for a request the API
     * refused. Every non-2xx reaches this check uniformly, 429 and 5xx included (#834) —
     * {@see Health::handleHttpResponse()} resolves every status rather than rejecting any of it, so
     * the gate is the only place any of them is told apart from a success.
     */
    private static function isHttpSuccessStatus(int $statusCode): bool
    {
        return $statusCode >= 200 && $statusCode < 300;
    }

    /**
     * The `detail` of an RFC 9457 problem-document body, when the body decodes to one.
     *
     * The API's own explanation of a refusal is quoted verbatim in an `exists` failure text (#833)
     * rather than left for a client to go and re-fetch: `detail` already says precisely what is wrong
     * — e.g. a rite's year boundary — better than any text manufactured here. `null` covers both "the
     * body is not JSON" and "it is JSON but not a problem document", so every call site falls back to
     * reporting the bare status.
     */
    private static function problemDetailFromBody(string $body): ?string
    {
        $decoded = json_decode($body);
        if ($decoded instanceof \stdClass && isset($decoded->detail) && is_string($decoded->detail)) {
            return $decoded->detail;
        }
        return null;
    }

    /**
     * Whether the given URL targets our own API host and is therefore safe to send the
     * first-party WS_API_KEY to.
     *
     * Relative URLs (no host component) target our own API. Absolute URLs must match the
     * configured API host (API_HOST, default 'localhost'); anything else — e.g. an external
     * resource validated via executeValidation — must never receive the key. A malformed URL
     * is treated as untrusted.
     */
    private static function isInternalApiUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === false) {
            return false;
        }
        if ($host === null) {
            return true;
        }
        $apiHost = isset($_ENV['API_HOST']) && is_string($_ENV['API_HOST']) && $_ENV['API_HOST'] !== ''
            ? $_ENV['API_HOST']
            : 'localhost';

        return strcasecmp($host, $apiHost) === 0;
    }

    /**
     * Return $options with the first-party WS_API_KEY attached as an X-Api-Key header, when one is
     * configured and the URL targets our own API host (see {@see isInternalApiUrl}). Otherwise the
     * options are returned unchanged, so the key is never sent to an arbitrary external URL. Any
     * existing headers (e.g. Accept) are preserved.
     *
     * @param array{headers?: array<string, string>, stream?: bool} $options
     * @return array{headers?: array<string, string>, stream?: bool}
     */
    private static function withApiKeyHeader(array $options, string $url): array
    {
        $wsApiKey = $_ENV['WS_API_KEY'] ?? null;
        if (is_string($wsApiKey) && $wsApiKey !== '' && self::isInternalApiUrl($url)) {
            $headers              = $options['headers'] ?? [];
            $headers['X-Api-Key'] = $wsApiKey;
            $options['headers']   = $headers;
        }

        return $options;
    }

    /**
     * Drop queued requests whose run this connection is no longer on. Two things cause that: the client
     * stopped and started a new run, so the connection's stored token advanced; or the client sent
     * `cancelRun` and {@see Health::cancelRun()} cleared the stored token outright. Their responses would
     * be discarded by the client anyway, so skipping the work lets a restarted run dispatch immediately
     * instead of first draining the abandoned run's backlog. Untagged requests (e.g. the metadata fetch
     * on connect) carry no token and are always kept. In-flight requests are not affected (they are few —
     * capped at maxConcurrency — and their responses are discarded client-side).
     */
    private function dropSupersededQueuedRequests(): void
    {
        if (empty($this->queue)) {
            return;
        }
        $this->queue = array_values(array_filter(
            $this->queue,
            fn (array $item): bool => null === $item['resourceId']
                || null === $item['runToken']
                || ( $this->runTokens[$item['resourceId']] ?? null ) === $item['runToken']
        ));
    }

    private function processQueue(): void
    {
        $this->dropSupersededQueuedRequests();
        echo 'Processing queue, inFlight: ' . $this->inFlight . ', maxConcurrency: ' . $this->maxConcurrency . ', queue size: ' . count($this->queue) . "\n";
        while ($this->inFlight < $this->maxConcurrency && !empty($this->queue)) {
            // Enforce the outbound request-rate cap: no more than $maxRequestRate dispatches within
            // any trailing 1-second window. This keeps the WS server under the public API's per-IP
            // rate limit (nginx limit_req) without needing server-side IP exemptions.
            $now                 = microtime(true);
            $this->dispatchTimes = array_values(array_filter(
                $this->dispatchTimes,
                static fn (float $t): bool => ( $now - $t ) < 1.0
            ));
            if (count($this->dispatchTimes) >= $this->maxRequestRate) {
                // Window is full: resume dispatching once the oldest dispatch ages out of the window.
                if (false === $this->rateLimitTimerActive) {
                    $this->rateLimitTimerActive = true;
                    $oldest                     = $this->dispatchTimes[0] ?? $now;
                    $wait                       = 1.0 - ( $now - $oldest );
                    if ($wait < 0.0) {
                        $wait = 0.0;
                    }
                    Loop::addTimer($wait, function (): void {
                        $this->rateLimitTimerActive = false;
                        $this->multiHandler->tick();
                        $this->processQueue();
                    });
                }
                echo 'Rate limit reached (' . $this->maxRequestRate . '/s), deferring ' . count($this->queue) . " queued request(s)\n";
                return;
            }

            [
                'url'     => $url,
                'options' => $options,
                'resolve' => $resolve,
                'reject'  => $reject
            ] = array_shift($this->queue);

            ++$this->inFlight;
            $this->dispatchTimes[] = microtime(true);

            if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development') {
                $date         = date('Y-m-d H:i:s.u');
                $debugMessage = "{$date}\tREQUEST GET URL " . $url . "\n";
                file_put_contents(Router::$apiFilePath . 'logs' . DIRECTORY_SEPARATOR . 'websocket_requests.log', $debugMessage, FILE_APPEND);
            }

            // Attach the first-party API key (when configured) only for requests targeting our own
            // API host, so WS_API_KEY is never leaked to an arbitrary absolute URL.
            $options = self::withApiKeyHeader($options, $url);

            $this->http->getAsync($url, $options)
                ->then(
                    $resolve,
                    $reject
                );
        }
        $this->drainHandler();
    }

    private function ensureTicking(): void
    {
        if ($this->ticking) {
            echo 'Already ticking' . "\n";
            return;
        }
        echo 'Starting to tick' . "\n";
        $this->ticking = true;

        Loop::futureTick(function () {
            $this->drainHandler();
        });
    }

    private function drainHandler(): void
    {
        if ($this->inFlight > 0 || !empty($this->queue)) {
            echo 'Drain handler ensuring ticking, inFlight: ' . $this->inFlight . ', queued requests: ' . count($this->queue) . '' . "\n";
            // keep ticking until no requests are left
            Loop::futureTick(function () {
                $this->multiHandler->tick();
                $this->processQueue();
            });
        } else {
            // no active or queued requests
            echo 'Stopping to tick, inFlight: ' . $this->inFlight . ', queue: ' . count($this->queue) . '' . "\n";
            $this->ticking = false;
        }
    }

    /**
     * A schema path from the static half of the inventory, or null — never an exception.
     *
     * Used only from the catch blocks that contain a failed inventory lookup. Those callers have
     * already lost their primary resolution and are choosing to degrade rather than propagate, so a
     * fallback that could itself throw would defeat the containment it exists to provide. Anything
     * going wrong here therefore yields null, and the caller falls through to its own arms.
     *
     * The static half is separable precisely because it never touches `CalendarMetadataProvider`
     * — see `CheckableInventory::staticItems()`, which also explains why test definitions are left
     * out of it.
     *
     * @param \Closure(): ?CheckableItem $lookup
     */
    private static function staticInventorySchema(\Closure $lookup): ?string
    {
        try {
            return $lookup()?->schema->path();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Mapping of data file paths to the LitSchema constants that their JSON data should validate against.
     * The paths are relative to the root of the project. The LitSchema constants are used to determine
     * which schema to use when validating the JSON data.
     */
    private static function getPathToSchemaFile(string $dataFile): ?string
    {
        // Source-data files come from the one inventory (#806 step A); the arms below are API
        // routes, which are a different kind of thing and stay here.
        //
        // CheckableInventory::byPath() already normalises between the two path representations
        // in play: $dataFile here is the unprefixed repo-relative form (matching JsonData::*->value),
        // while the inventory stores absolute paths prefixed with Router::$apiFilePath. No
        // conversion is needed at this call site.
        try {
            $item = CheckableInventory::byPath($dataFile);
            if (null !== $item) {
                return $item->schema->path();
            }
        } catch (\Throwable) {
            // The inventory does not merely index folders: it enumerates per-calendar source data via
            // CalendarMetadataProvider::create(), which reads and JSON-parses every national and
            // diocesan calendar file. One malformed or missing file makes the whole lookup throw.
            //
            // Health is a long-running process serving every client, so letting that propagate would
            // take out schema resolution for every other check — including the Roman temporale, which
            // has nothing to do with the broken file. The detector would fail on exactly what it
            // exists to detect.
            //
            // Retry against the static half, which is exactly the part that cannot have caused this:
            // the missal propriums, the temporale, the decrees and their i18n folders, all built from
            // in-memory registries. Those are also the paths most often checked, so this turns the
            // common degradation back into a real answer instead of a null. Only per-calendar targets
            // are left to fall through to the arms below. GET /validations still reports the 503
            // loudly, which is where that failure belongs.
            $fallback = self::staticInventorySchema(
                static fn (): ?CheckableItem => CheckableInventory::staticByPath($dataFile)
            );

            if (null !== $fallback) {
                return $fallback;
            }
        }

        // The rite segment is optional and its absence means the default rite, so the
        // rite-qualified COLLECTION form (`/events/roman`) has to resolve to whatever the bare
        // form (`/events`) resolves to — see issue #814. It is normalised away *before* the match
        // rather than added as arms of its own, so every arm below is rite-aware by construction
        // and a route added later cannot reintroduce the asymmetry. Paths that do not end in a
        // rite segment come through unchanged, leaving the bare forms matching exactly as before.
        //
        // `/calendar/{rite}` is unaffected: Route::CALENDAR has no arm here, so both forms
        // resolve to null exactly as they did.
        //
        // The strip is UNIFORM across the map, not gated on the routes that actually carry a rite
        // — so `/missals/roman` and `/decrees/ambrosian` resolve too, even though the Router 404s
        // them (`Router::extractRiteSegment()` admits a rite only on calendar, events and data,
        // and `Router::extractTestsRite()` on tests). That is deliberate, not an oversight. This
        // function answers "which schema would validate a response of this shape", NOT "is this
        // path routable": the Router remains the sole authority on what routes exist, and a schema
        // resolved for a non-route costs nothing because the fetch that follows 404s and the check
        // fails loudly anyway. Gating the strip would mean hardcoding the list of rite-carrying
        // routes here — precisely the staleness that produced #814 — and there is no registry to
        // derive such a list from, the knowledge living in an inline condition in
        // `Router::extractRiteSegment()` with `/tests` handled separately. Do not add routability
        // checks to this lookup.
        return match (self::stripTrailingRiteSegment($dataFile)) {
            Route::CALENDARS->path() => LitSchema::METADATA->path(),
            Route::DECREES->path()   => LitSchema::DECREES->path(),
            Route::EVENTS->path()    => LitSchema::EVENTS->path(),
            Route::TESTS->path()     => LitSchema::TESTS->path(),
            Route::EASTER->path()    => LitSchema::EASTER->path(),
            Route::MISSALS->path()   => LitSchema::MISSALS->path(),
            Route::DATA->path()      => LitSchema::DATA->path(),
            Route::SCHEMAS->path()   => LitSchema::SCHEMAS->path(),
            default                  => null
        };
    }

    /**
     * Remove a trailing rite segment from an API collection path.
     *
     * `/events/roman` becomes `/events`; a path that does not end in a rite segment is returned
     * unchanged, and only one segment is ever removed (so a doubled rite stays unresolvable).
     *
     * The rule is intentionally shape-based rather than route-aware: it strips the segment from any
     * path, including one whose route carries no rite. See the note at the call site in
     * {@see self::getPathToSchemaFile()} for why that is the right trade.
     *
     * @param string $path the path to normalise
     * @return string the path without its trailing rite segment
     */
    private static function stripTrailingRiteSegment(string $path): string
    {
        $stripped = preg_replace('/\/(?:' . self::riteAlternation() . ')$/', '', $path);

        return $stripped ?? $path;
    }

    /**
     * The regex alternation of every known rite, e.g. `roman|ambrosian`.
     *
     * Derived from {@see Rite} rather than written out, so a third rite is admitted by every
     * pattern that uses it the moment the case is declared.
     *
     * @return string the alternation, ready to drop inside a `(?:…)` group in a `/`-delimited pattern
     */
    private static function riteAlternation(): string
    {
        return implode('|', array_map(
            static fn (Rite $rite): string => preg_quote($rite->value, '/'),
            Rite::cases()
        ));
    }

    /**
     * Is this a `validateCalendar` message in the reshaped (v2) form?
     *
     * A thin wrapper over {@see WebSocketMessageValidator::shapeOf()}, which is the single source of
     * truth for "is `calendar` an object?": it must already resolve this to pick which arm of the
     * schema to validate against, and {@see Health::onMessage()} needs the same answer to pick a
     * handler. Delegating here keeps that predicate in one place instead of two literal copies that
     * could drift apart.
     *
     * `validateCalendar` is the only action that needs a shape test at all. `validateSource` and
     * `runTest` are new *names*, and a v1 client cannot accidentally emit a name it does not know;
     * `validateCalendar` kept its name because the action it names did not change.
     */
    private static function isTypedCalendarMessage(\stdClass $message): bool
    {
        return 'validateCalendarTyped' === WebSocketMessageValidator::shapeOf($message);
    }

    /**
     * Resolve the JSON Schema an `executeValidation` message should validate against.
     *
     * Three categories are supported, each resolving from a different input — see the
     * `ExecuteValidationCategory` note in the class docblock:
     *
     *   - `universalcalendar`: `$dataPath` is a source-data path or API URL, looked up in
     *     {@see Health::getPathToSchemaFile()};
     *   - `sourceDataCheck`: `$dataPath` is the `validate` slug, matched against anchored patterns;
     *   - `resourceDataCheck`: `$dataPath` is an API endpoint URL.
     *
     * Any other category returns null, which surfaces to the client as
     * "Unable to detect schema for dataPath …".
     *
     * Note the caller passes `$pathForSchema`, not the raw `sourceFile`: for `sourceDataCheck`
     * that is the `validate` slug, and for the other two it is the `sourceFile` itself.
     *
     * @param string $category The schema-resolution strategy; one of ExecuteValidationCategory.
     * @param string $dataPath The value to resolve from — a path, a URL, or a `validate` slug, per the category.
     * @return string|null The schema path, or null when the category is not one of the three supported values.
     */
    private static function retrieveSchemaForCategory(string $category, string $dataPath): ?string
    {
        $versionedPattern     = '/\/api\/(?:v[4-9]|v[1-9]\\d+)\//';
        $versionedReplacement = '/api/dev/';
        $isVersionedDataPath  = preg_match($versionedPattern, $dataPath) === 1;
        switch ($category) {
            case 'universalcalendar':
                if ($isVersionedDataPath) {
                    $versionedDataPath = preg_replace($versionedPattern, $versionedReplacement, $dataPath);
                    if (null === $versionedDataPath) {
                        throw new \InvalidArgumentException('Invalid dataPath: ' . $dataPath . ', expected to match ' . $versionedPattern);
                    }
                    /** @var string $versionedDataPath */
                    $pathToSchemaFile = Health::getPathToSchemaFile($versionedDataPath);
                    if (null !== $pathToSchemaFile) {
                        return preg_replace($versionedPattern, $versionedReplacement, $pathToSchemaFile);
                    }
                }
                return Health::getPathToSchemaFile($dataPath);
            case 'resourceDataCheck':
                if (
                    preg_match('/\/missals\/[_A-Z0-9]+$/', $dataPath)
                ) {
                    return $isVersionedDataPath ? preg_replace($versionedPattern, $versionedReplacement, LitSchema::PROPRIUMDESANCTIS->path()) : LitSchema::PROPRIUMDESANCTIS->path();
                } elseif (
                    preg_match('/\/events\/(?:(?:' . self::riteAlternation() . ')\/)?(?:nation\/[A-Z]{2}|diocese\/[a-z]{6}_[a-z]{2})(?:\?locale=[a-zA-Z0-9_]+)?$/', $dataPath)
                ) {
                    return $isVersionedDataPath ? preg_replace($versionedPattern, $versionedReplacement, LitSchema::EVENTS->path()) : LitSchema::EVENTS->path();
                } elseif (
                    // The rite segment is NON-capturing on purpose: the numbered groups below drive
                    // the switch, so a capturing group here would shift them all by one.
                    preg_match('/\/data\/(?:(?:' . self::riteAlternation() . ')\/)?(?:(nation)\/[A-Z]{2}|(diocese)\/[a-z]{6}_[a-z]{2}|(widerregion)\/[A-Z][a-z]+)(?:\?locale=[a-zA-Z0-9_]+)?$/', $dataPath, $matches)
                ) {
                    $schema = LitSchema::DATA->path();
                    foreach ($matches as $idx => $match) {
                        if ($idx > 0) {
                            switch ($match) {
                                case 'nation':
                                    $schema = LitSchema::NATIONAL->path();
                                    break;
                                case 'diocese':
                                    $schema = LitSchema::DIOCESAN->path();
                                    break;
                                case 'widerregion':
                                    $schema = LitSchema::WIDERREGION->path();
                                    break;
                            }
                        }
                    }
                    return $isVersionedDataPath ? preg_replace($versionedPattern, $versionedReplacement, $schema) : $schema;
                }
                if ($isVersionedDataPath) {
                    $versionedDataPath = preg_replace($versionedPattern, $versionedReplacement, $dataPath);
                    if (null === $versionedDataPath) {
                        throw new \InvalidArgumentException('Invalid dataPath: ' . $dataPath . ', expected to match ' . $versionedPattern);
                    }
                    /** @var string $versionedDataPath */
                    $pathToSchemaFile = Health::getPathToSchemaFile($versionedDataPath);
                    if (null !== $pathToSchemaFile) {
                        return preg_replace($versionedPattern, $versionedReplacement, $pathToSchemaFile);
                    }
                }
                return Health::getPathToSchemaFile($dataPath);
            case 'sourceDataCheck':
                // Legacy slugs from the runner pages, mapped onto inventory ids. #806 step A gives
                // every item an id; until the clients send those ids (UnitTestInterface#42), the
                // old vocabulary keeps working through this table.
                //
                // proprium-de-sanctis-* is deliberately absent: those slugs are hyphenated (e.g.
                // proprium-de-sanctis-IT-1983) while the matching inventory ids are underscored
                // (sanctorale:roman:IT_1983), so mapping them here would mean hand-listing the
                // five editions again — exactly the restatement the derivation removed. They keep
                // resolving through the surviving regex below instead, until clients send inventory
                // ids.
                $legacySlugToId = [
                    'memorials-from-decrees'      => 'decrees:roman',
                    'memorials-from-decrees-i18n' => 'decrees:roman:i18n',
                    'proprium-de-tempore'         => 'temporale:roman',
                    'proprium-de-tempore-i18n'    => 'temporale:roman:i18n'
                ];
                try {
                    $item = CheckableInventory::byId($legacySlugToId[$dataPath] ?? $dataPath);
                    if (null !== $item) {
                        return $item->schema->path();
                    }
                } catch (\Throwable) {
                    // Same containment, and the same static-half retry, as in getPathToSchemaFile():
                    // the inventory reads and parses all calendar source data, so one malformed file
                    // must not take out schema resolution for every other check in a process that
                    // stays up. The retry matters more once clients send inventory ids rather than
                    // these slugs (UnitTestInterface#42): sanctorale:roman:US_2011 matches none of the
                    // patterns below, while the slug it replaces does. Until then the regex arms
                    // resolve national-calendar-XX, diocesan-calendar-…, wider-region-…, tests-… and
                    // the proprium-de-* slugs generically, so the fall-through is real behaviour too,
                    // not a stub.
                    $fallback = self::staticInventorySchema(
                        static fn (): ?CheckableItem => CheckableInventory::staticById($legacySlugToId[$dataPath] ?? $dataPath)
                    );

                    if (null !== $fallback) {
                        return $fallback;
                    }
                }

                if (preg_match('/-i18n$/', $dataPath)) {
                    return LitSchema::I18N->path();
                }
                if (preg_match('/^memorials-from-decrees$/', $dataPath)) {
                    return LitSchema::DECREES_SRC->path();
                }
                if (preg_match('/^proprium-de-sanctis(?:-[A-Z]{2})?-(?:1|2)(?:9|0)(?:7|8|9|0|1|2)[0-9]$/', $dataPath)) {
                    return LitSchema::PROPRIUMDESANCTIS->path();
                }
                if (preg_match('/^proprium-de-tempore$/', $dataPath)) {
                    return LitSchema::PROPRIUMDETEMPORE->path();
                }
                if (preg_match('/^wider-region-[A-Z][a-z]+$/', $dataPath)) {
                    return LitSchema::WIDERREGION->path();
                }
                // These two deliberately mirror the identifier grammar `executeValidation()` uses
                // to derive the source path for the same slug. One identifier described by two
                // patterns, in one file, that have to agree is precisely the drift this issue is
                // about: every id in jsondata today satisfies both the narrow and the wide form,
                // but a diocese that ever broke the six-character convention would have its path
                // derived correctly and its schema silently resolve to null. `wider-region-`,
                // `tests-` and `proprium-de-*` have no such sibling and keep their own patterns.
                if (preg_match('/^national-calendar-[A-Za-z_]+$/', $dataPath)) {
                    return LitSchema::NATIONAL->path();
                }
                if (preg_match('/^diocesan-calendar-[A-Za-z_]+$/', $dataPath)) {
                    return LitSchema::DIOCESAN->path();
                }
                if (preg_match('/^tests-[a-zA-Z0-9_]+$/', $dataPath)) {
                    return LitSchema::TEST_SRC->path();
                }
                return null;
        }
        return null;
    }

    /**
     * Build the openfga_outbox block for the HTTP /health endpoint.
     *
     * Fetches PG-side status counts and oldest-pending age from OutboxRepository,
     * and Redis-side consumer group info via xInfo GROUPS.
     *
     * Designed to be called from HealthHandler (HTTP context), which is why it is
     * public static — the Redis connection here is a short-lived probe, independent
     * of the WebSocket server's cached self::$redis.
     *
     * @return array{
     *   pending: int,
     *   retrying: int,
     *   succeeded: int,
     *   failed_terminal: int,
     *   oldest_pending_age_seconds: int,
     *   consumer: array{
     *     redis_reachable: bool,
     *     stream_name: string,
     *     group_name: string,
     *     pending_entries: int,
     *     oldest_pel_idle_seconds: int
     *   }
     * }
     */
    public static function buildOutboxStats(): array
    {
        $counts    = [
            'pending'         => 0,
            'retrying'        => 0,
            'succeeded'       => 0,
            'failed_terminal' => 0,
        ];
        $oldestAge = 0;

        if (Connection::isConfigured()) {
            try {
                $repo      = new OutboxRepository(Connection::getInstance());
                $rawCounts = $repo->countByStatus();
                foreach ($rawCounts as $status => $n) {
                    if (array_key_exists($status, $counts)) {
                        $counts[$status] = $n;
                    }
                }
                $oldestAge = $repo->oldestPendingAgeSeconds();
            } catch (\Throwable) {
                // PG unreachable — leave zeros; the health endpoint's DB section surfaces the failure.
            }
        }

        $streamName = isset($_ENV['REDIS_OUTBOX_STREAM']) && is_string($_ENV['REDIS_OUTBOX_STREAM'])
            ? $_ENV['REDIS_OUTBOX_STREAM']
            : 'litcal:reconcile-stream';
        $groupName  = isset($_ENV['REDIS_OUTBOX_GROUP']) && is_string($_ENV['REDIS_OUTBOX_GROUP'])
            ? $_ENV['REDIS_OUTBOX_GROUP']
            : 'reconciler';

        $consumer = [
            'redis_reachable'         => false,
            'stream_name'             => $streamName,
            'group_name'              => $groupName,
            'pending_entries'         => 0,
            'oldest_pel_idle_seconds' => 0,
        ];

        if (extension_loaded('redis')) {
            try {
                $redis       = new \Redis();
                $redisSocket = isset($_ENV['REDIS_SOCKET']) && is_string($_ENV['REDIS_SOCKET'])
                    ? $_ENV['REDIS_SOCKET']
                    : null;
                if ($redisSocket !== null && $redisSocket !== '') {
                    $connected = $redis->connect($redisSocket, 0, 2.0);
                } else {
                    $redisHost = isset($_ENV['REDIS_HOST']) && is_string($_ENV['REDIS_HOST'])
                        ? $_ENV['REDIS_HOST']
                        : '127.0.0.1';
                    $redisPort = isset($_ENV['REDIS_PORT']) && is_numeric($_ENV['REDIS_PORT'])
                        ? (int) $_ENV['REDIS_PORT']
                        : 6379;
                    $connected = $redis->connect($redisHost, $redisPort, 2.0);
                }
                if ($connected) {
                    $redisPassword = isset($_ENV['REDIS_PASSWORD']) && is_string($_ENV['REDIS_PASSWORD'])
                        ? $_ENV['REDIS_PASSWORD']
                        : null;
                    if ($redisPassword !== null && $redisPassword !== '') {
                        $redis->auth($redisPassword);
                    }
                    $redis->ping();
                    $consumer['redis_reachable'] = true;
                    $info                        = $redis->xInfo('GROUPS', $streamName);
                    if (is_array($info)) {
                        foreach ($info as $group) {
                            if (is_array($group) && ( $group['name'] ?? null ) === $groupName) {
                                $pending                     = $group['pending'] ?? 0;
                                $consumer['pending_entries'] = is_numeric($pending) ? (int) $pending : 0;
                            }
                        }
                    }
                    // Populate oldest_pel_idle_seconds via the xPending detail form.
                    // Response shape: list of [msgId, consumer, idle_ms, deliveries].
                    try {
                        $pel = $redis->xPending($streamName, $groupName, '-', '+', 1);
                        if (is_array($pel) && count($pel) > 0 && is_array($pel[0]) && isset($pel[0][2]) && is_numeric($pel[0][2])) {
                            $consumer['oldest_pel_idle_seconds'] = intdiv((int) $pel[0][2], 1000);
                        }
                    } catch (\Throwable) {
                        // Best-effort — don't let a secondary Redis call break the health response.
                    }
                }
            } catch (\Throwable) {
                $consumer['redis_reachable'] = false;
            }
        }

        return [
            'pending'                    => $counts['pending'],
            'retrying'                   => $counts['retrying'],
            'succeeded'                  => $counts['succeeded'],
            'failed_terminal'            => $counts['failed_terminal'],
            'oldest_pending_age_seconds' => $oldestAge,
            'consumer'                   => $consumer,
        ];
    }

    /**
     * Takes an array of LIBXML errors and an array of XML lines and returns one string per error,
     * with line numbers and column numbers.
     *
     * A list rather than the joined string it used to return, so that a frame's `details` can carry
     * the errors as the separate things they are while its `text` joins them for a human. Recovering
     * them by splitting the joined string afterwards would have been fragile in a way that is easy to
     * miss: every message goes through `htmlspecialchars()`, but the ` in file: …` suffix below
     * interpolates the URI raw, so a separator arriving in a filename would have split an entry in
     * two. Not joining in the first place removes the question.
     *
     * @param \LibXMLError[] $errors Array of LIBXML errors
     * @param string[] $xml Array of strings, each string is a line in the XML document
     * @return list<string> One entry per error, with its line number and column number
     */
    private static function retrieveXmlErrors(array $errors, array $xml): array
    {
        $return = [];
        foreach ($errors as $error) {
            $errorStr = '';
            switch ($error->level) {
                case LIBXML_ERR_WARNING:
                    $errorStr .= "Warning $error->code: ";
                    break;
                case LIBXML_ERR_ERROR:
                    $errorStr .= "Error $error->code: ";
                    break;
                case LIBXML_ERR_FATAL:
                    $errorStr .= "Fatal Error $error->code: ";
                    break;
            }
            $errorStr .= htmlspecialchars(trim($error->message))
                      . " (Line: $error->line, Column: $error->column, Src: "
                      . htmlspecialchars(trim($xml[$error->line - 1] ?? '')) . ')';
            if ($error->file) {
                $errorStr .= " in file: $error->file";
            }
            array_push($return, $errorStr);
        }
        return $return;
    }
}
