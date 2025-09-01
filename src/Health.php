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
use LiturgicalCalendar\Api\Enum\ICSErrorLevel;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
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
 * @phpstan-type ExecuteValidationSourceFolder \stdClass&object{action:'executeValidation',category:'sourceDataCheck',validate:string,sourceFolder:string}
 * @phpstan-type ExecuteValidationSourceFile \stdClass&object{action:'executeValidation',category:'sourceDataCheck',validate:string,sourceFile:string}
 * @phpstan-type ExecuteValidationResource \stdClass&object{action:'executeValidation',category:'resourceDataCheck',validate:string,sourceFile:string}
 * @phpstan-type ValidateCalendar \stdClass&object{action:'validateCalendar',calendar:string,year:int,category:'nationalcalendar'|'diocesanCalendar',responsetype:'JSON'|'XML'|'ICS'|'YML'}
 * @phpstan-type ExecuteUnitTest \stdClass&object{action:'executeUnitTest',calendar:string,year:int,category:'nationalcalendar'|'diocesancalendar',test:string}
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
     * Array of actions that the Health endpoint can execute.
     * Each key is an action name. The value is an array of strings that represent the names of the
     * parameters that the action requires.
     *
     * @var array<string,string[]> $ACTION_PROPERTIES
     */
    private const ACTION_PROPERTIES = [
        'executeValidation' => ['category', 'validate', 'sourceFile'],
        'validateCalendar'  => ['category', 'calendar', 'year', 'responsetype'],
        'executeUnitTest'   => ['category', 'calendar', 'year', 'test']
    ];

    private static MetadataCalendars $metadata;

    private Client $http;

    private CurlMultiHandler $multiHandler;

    private int $maxConcurrency;
    private int $inFlight = 0;
    /** @var list<array{url:string,options:array{headers?:array{Accept:string}},resolve:\Closure(ResponseInterface):void,reject:\Closure(\Throwable):void}> */
    private array $queue  = [];
    private bool $ticking = false;

    /**
     * Initializes the Health object with an empty SplObjectStorage.
     *
     * The SplObjectStorage is used to store client connections.
     */
    public function __construct()
    {
        $this->clients = new \SplObjectStorage();

        Router::getApiPaths();

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

        if (Router::isLocalhost() || ( isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development' )) {
            $this->maxConcurrency = 4;
        } else {
            $this->maxConcurrency = 50;
        }
    }

    /**
     * Called when a new client connection is established.
     *
     * This stores the new connection to send messages to later.
     */
    public function onOpen(ConnectionInterface $conn): void
    {
        // Store the new connection to send messages to later
        $this->clients->attach($conn);
        if (false === is_int($conn->resourceId)) {
            echo 'Error onOpen: expected an integer resourceId, got ' . gettype($conn->resourceId) . "\n";
            return;
        } else {
            echo "New connection! ({$conn->resourceId})\n";
        }

        /*
        Loop::get()->addPeriodicTimer(0.01, function () {
            $this->multiHandler->tick();
            if (!empty($this->queue)) {
                $this->processQueue();
            }
        });
        */

        if (extension_loaded('apcu')) {
            echo 'APCu extension loaded, will use for caching' . "\n";
        } else {
            echo 'APCu extension not loaded, will not use for caching' . "\n";
        }

        if (false === isset(self::$metadata)) {
            echo 'Metadata not yet loaded, loading now from ' . Route::CALENDARS->path() . "\n";

            $opts = [
                'headers' => [
                    'Accept' => 'application/json'
                ]
            ];

            /** @var PromiseInterface<string> $promise */
            $promise = $this->cachedGet(Route::CALENDARS->path(), $opts);

            $promise->then(
                function (string $rawData) {
                    $rawData = (string) $rawData; // force fresh string
                    echo 'Fetched metadata: got ' . strlen($rawData) . " bytes\n";

                    $metadataObj = json_decode($rawData);

                    if (false === ( $metadataObj instanceof \stdClass )) {
                        echo 'Error loading metadata: expected stdClass, got ' . gettype($metadataObj) . "\n";
                        return;
                    }

                    if (JSON_ERROR_NONE !== json_last_error()) {
                        echo 'Error loading metadata: ' . json_last_error_msg() . "\n";
                        return;
                    } else {
                        echo "Loaded metadata\n";
                    }

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
        echo sprintf('Receiving message from connection %d: %s', $resourceId, $msg . "\n");
        /** @var ExecuteValidationSourceFolder|ExecuteValidationSourceFile|ExecuteValidationResource|ValidateCalendar|ExecuteUnitTest $messageReceived */
        $messageReceived = json_decode($msg);
        if (
            json_last_error() === JSON_ERROR_NONE
            && $messageReceived instanceof \stdClass
            && property_exists($messageReceived, 'action')
            && self::validateMessageProperties($messageReceived)
        ) {
            switch ($messageReceived->action) {
                case 'executeValidation':
                    /** @var ExecuteValidationSourceFolder|ExecuteValidationSourceFile|ExecuteValidationResource $messageReceived */
                    $this->executeValidation($messageReceived, $from);
                    break;
                case 'validateCalendar':
                    /** @var ValidateCalendar $messageReceived */
                    $this->validateCalendar(
                        $messageReceived->calendar,
                        $messageReceived->year,
                        $messageReceived->category,
                        $messageReceived->responsetype,
                        $from
                    );
                    break;
                case 'executeUnitTest':
                    /** @var ExecuteUnitTest $messageReceived */
                    $this->executeUnitTest(
                        $messageReceived->test,
                        $messageReceived->calendar,
                        $messageReceived->year,
                        $messageReceived->category,
                        $from
                    );
                    break;
                default:
                    $message       = new \stdClass();
                    $message->type = 'echobot';
                    $message->text = $msg;
                    $this->sendMessage($from, $message);
            }
        } else {
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errorMsg = json_last_error_msg();
            } elseif (!$messageReceived instanceof \stdClass) {
                $errorMsg = 'Message is not an object';
            } elseif (!property_exists($messageReceived, 'action')) {
                $errorMsg = 'No action specified';
            } elseif (!self::validateMessageProperties($messageReceived)) {
                $errorMsg = 'Invalid message properties';
            } else {
                $errorMsg = 'Unknown error';
            }
            echo sprintf('Invalid message from connection %1$d: %2$s (%3$s)', $resourceId, $errorMsg, $msg);
            $message           = new \stdClass();
            $message->type     = 'echobot';
            $message->errorMsg = $errorMsg;
            $message->text     = sprintf('Invalid message from connection %d: %s', $resourceId, $msg);
            $this->sendMessage($from, $message);
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
        // The connection is closed, remove it, as we can no longer send it messages
        $this->clients->detach($conn);
        /** @var int $resourceId */
        $resourceId = $conn->resourceId;
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
     */
    private function sendMessage(ConnectionInterface $from, string|\stdClass $msg): void
    {
        if (gettype($msg) !== 'string') {
            $msg = json_encode($msg, JSON_PRETTY_PRINT);
        }
        /** @var string $msg */
        $from->send($msg);
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
     */
    private function executeValidation(\stdClass $validation, ConnectionInterface $to): void
    {
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
                            $dioceseMetadata = array_find(self::$metadata->diocesan_calendars, fn ($el) => $el->calendar_id === $matches[2]);
                            if ($dioceseMetadata === null) {
                                throw new \Exception("No diocese found for calendar id: {$matches[2]}");
                            }
                            $nation   = $dioceseMetadata->nation;
                            $dataPath = strtr(
                                JsonData::DIOCESAN_CALENDAR_I18N_FOLDER->path(),
                                [
                                    '{diocese}' => $matches[2],
                                    '{nation}'  => $nation
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
                    if (preg_match('/^(wider-region|national-calendar|diocesan-calendar)-([A-Z][a-z]+)$/', $validate, $matches)) {
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
                                $dioceseMetadata = array_find(self::$metadata->diocesan_calendars, fn ($el) => $el->calendar_id === $matches[2]);
                                if ($dioceseMetadata === null) {
                                    throw new \Exception("No diocese found for calendar id: {$matches[2]}");
                                }
                                $nation      = (string) $dioceseMetadata->nation;
                                $dioceseName = (string) $dioceseMetadata->diocese;
                                $dataPath    = strtr(
                                    JsonData::DIOCESAN_CALENDAR_FILE->path(),
                                    [
                                        '{diocese}'      => $matches[2],
                                        '{nation}'       => $nation,
                                        '{diocese_name}' => $dioceseName
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

        // Now that we have the correct schema to validate against,
        // we will perform the actual validation either for all files in a folder, or for a single file
        if (property_exists($validation, 'sourceFolder') && is_string($validation->sourceFolder)) {
            $sourceFolder = (string) $validation->sourceFolder;
            // If the 'sourceFolder' property is set, then we are validating a folder of i18n files
            /** @var ExecuteValidationSourceFolder $validation */
            $files = glob($dataPath . '/*.json');
            if (false === $files || empty($files)) {
                $message          = new \stdClass();
                $message->type    = 'error';
                $message->text    = "Data folder $sourceFolder ($dataPath) does not exist or does not contain any json files";
                $message->classes = ".$validate.file-exists";
                $this->sendMessage($to, $message);
                return;
            }

            $fileExistsAndIsReadable = true;
            $jsonDecodable           = true;
            $schemaValidated         = true;

            /** @var list<PromiseInterface<string>> $promises */
            $promises = [];

            foreach ($files as $file) {
                $filename = pathinfo($file, PATHINFO_BASENAME);

                $matchI8nFile = preg_match('/(?:[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_[A-Z]{2})?|(?:ar|en|eo)_001|(?:en_150|es_419))\.json$/', $filename);

                if (false === $matchI8nFile || 0 === $matchI8nFile) {
                    $fileExistsAndIsReadable = false;
                    $message                 = new \stdClass();
                    $message->type           = 'error';
                    $message->text           = "Data folder $sourceFolder contains an invalid i18n json filename $filename";
                    $message->classes        = ".$validate.file-exists";
                    $this->sendMessage($to, $message);
                    continue;
                }

                /** @var PromiseInterface<string> $promise */
                $promise    = $this->cachedFileGetContents($file);
                $promises[] = $promise->then(
                    function (string $fileData) use ($to, $validation, $filename, $schema, $pathForSchema, &$jsonDecodable, &$schemaValidated) {
                        $fileData = (string) $fileData; // force fresh string
                        $validate = (string) $validation->validate;
                        $category = (string) $validation->category;
                        $jsonData = json_decode($fileData);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $jsonDecodable    = false;
                            $message          = new \stdClass();
                            $message->type    = 'error';
                            $message->text    = "The i18n json file $filename was not successfully decoded as JSON: " . json_last_error_msg();
                            $message->classes = ".$validate.json-valid";
                            $this->sendMessage($to, $message);
                        } else {
                            if (null !== $schema) {
                                $validationResult = $this->validateDataAgainstSchema($jsonData, $schema);
                                if ($validationResult instanceof \stdClass) {
                                    $schemaValidated           = false;
                                    $validationResult->classes = ".$validate.schema-valid";
                                    $this->sendMessage($to, $validationResult);
                                }
                            } else {
                                $message          = new \stdClass();
                                $message->type    = 'error';
                                $message->text    = "executeValidation validation->sourceFolder: Unable to detect a schema for {$validate} and category {$category} (path for schema: $pathForSchema)";
                                $message->classes = ".$validate.schema-valid";
                                $this->sendMessage($to, $message);
                            }
                        }
                    },
                    function (\Throwable $reason) use ($to, $validation, $filename, &$fileExistsAndIsReadable) {
                        $fileExistsAndIsReadable = false;
                        $validate                = (string) $validation->validate;
                        $sourceFolder            = (string) $validation->sourceFolder;
                        $message                 = new \stdClass();
                        $message->type           = 'error';
                        $message->text           = "Data folder $sourceFolder contains an unreadable i18n json file $filename: " . $reason->getMessage();
                        $message->classes        = ".$validate.file-exists";
                        $this->sendMessage($to, $message);
                    }
                );
            }

            $allPromises = Promise\all($promises);

            $allPromises->then(
                function () use ($to, $validation, $schema, $fileExistsAndIsReadable, $jsonDecodable, $schemaValidated) {
                    $validate     = (string) $validation->validate;
                    $sourceFolder = (string) $validation->sourceFolder;
                    if ($fileExistsAndIsReadable) {
                        $message = (object) [
                            'type'    => 'success',
                            'text'    => "The Data folder $sourceFolder exists and contains valid i18n json files",
                            'classes' => ".$validate.file-exists"
                        ];
                        $this->sendMessage($to, $message);
                    }

                    if ($jsonDecodable) {
                        $message = (object) [
                            'type'    => 'success',
                            'text'    => "The i18n json files in Data folder $sourceFolder were successfully decoded as JSON",
                            'classes' => ".$validate.json-valid"
                        ];
                        $this->sendMessage($to, $message);
                    }

                    if ($schemaValidated) {
                        $message = (object) [
                            'type'    => 'success',
                            'text'    => "The i18n json files in Data folder $sourceFolder were successfully validated against the Schema $schema",
                            'classes' => ".$validate.schema-valid"
                        ];
                        $this->sendMessage($to, $message);
                    }
                },
                function (\Throwable $e) use ($validation) {
                    echo 'Error verifying i18n folder for validation ' . json_encode($validation) . ': ' . $e->getMessage() . "\n";
                }
            );
        } else {
            // If the 'sourceFolder' property is not set, then we are validating a single source file or API path
            $matches = null;
            if (preg_match('/^diocesan-calendar-([a-z]{6}_[a-z]{2})$/', $pathForSchema, $matches)) {
                $dioceseId       = $matches[1];
                $dioceseMetadata = array_find(self::$metadata->diocesan_calendars, fn ($diocesan_calendar) => $diocesan_calendar->calendar_id === $dioceseId);
                if (null === $dioceseMetadata) {
                    throw new \InvalidArgumentException("Invalid diocese ID $dioceseId");
                }
                $nation      = $dioceseMetadata->nation;
                $dioceseName = $dioceseMetadata->diocese;
                $dataPath    = strtr(JsonData::DIOCESAN_CALENDAR_FILE->path(), [
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

            // If we are validating an API path, we check for a 200 OK HTTP response from the API
            // rather than checking for existence of the file in the filesystem
            $category = (string) $validation->category;
            $validate = (string) $validation->validate;
            if ($category === 'resourceDataCheck') {
                /** @var ExecuteValidationResource $validation */
                $headers = get_headers($dataPath);
                assert(is_array($headers), 'Headers should be an array for resourceDataCheck category');
                $headerOK = $headers[0];
                assert(is_string($headerOK), 'OK Header should be a string for resourceDataCheck category');
                if (!$headers || strpos($headerOK, '200 OK') === false) {
                    $message          = new \stdClass();
                    $message->type    = 'error';
                    $message->text    = "URL $dataPath is giving an error: " . $headerOK;
                    $message->classes = ".$validate.file-exists";
                    $this->sendMessage($to, $message);

                    $message          = new \stdClass();
                    $message->type    = 'error';
                    $message->text    = "Could not decode the Data file $dataPath as JSON because it is not readable";
                    $message->classes = ".$validate.json-valid";
                    $this->sendMessage($to, $message);

                    $message          = new \stdClass();
                    $message->type    = 'error';
                    $message->text    = "Unable to verify schema for dataPath {$dataPath} and category {$category} since Data file $dataPath does not exist or is not readable";
                    $message->classes = ".$validate.schema-valid";
                    $this->sendMessage($to, $message);
                    // early exit
                    return;
                }
            }

            if (str_starts_with($dataPath, 'http://') || str_starts_with($dataPath, 'https://')) {
                // $dataPath is an API path in this case
                echo 'Retrieving data from URL ' . $dataPath . "\n";
                /** @var PromiseInterface<string> $promise */
                $promise = $this->cachedGet($dataPath);
            } else {
                // $dataPath is probably a source file in the filesystem in this case
                echo 'Reading data from file ' . $dataPath . "\n";
                /** @var PromiseInterface<string> $promise */
                $promise = $this->cachedFileGetContents($dataPath);
            }

            $promise->then(
                function (string $data) use ($to, $validation, $dataPath, $schema, $pathForSchema) {
                    $data = (string) $data; // force fresh string
                    echo 'Fetched data for ' . $dataPath . ': got ' . strlen($data) . " bytes\n";
                    $this->processValidationData($data, $to, $validation, $dataPath, $schema, $pathForSchema);
                },
                function (\Throwable $e) use ($to, $validation, $dataPath) {
                    $validate = (string) $validation->validate;
                    $category = (string) $validation->category;
                    echo 'Error reading data: could not read data from ' . $dataPath . ': ' . $e->getMessage() . "\n";
                    $message          = new \stdClass();
                    $message->type    = 'error';
                    $message->text    = "Data file $dataPath is not readable: " . $e->getMessage();
                    $message->classes = ".$validate.file-exists";
                    $this->sendMessage($to, $message);

                    $message          = new \stdClass();
                    $message->type    = 'error';
                    $message->text    = "Could not decode the Data file $dataPath as JSON because it is not readable";
                    $message->classes = ".$validate.json-valid";
                    $this->sendMessage($to, $message);

                    $message          = new \stdClass();
                    $message->type    = 'error';
                    $message->text    = "Unable to verify schema for dataPath {$dataPath} and category {$category} since Data file $dataPath does not exist or is not readable";
                    $message->classes = ".$validate.schema-valid";
                    $this->sendMessage($to, $message);
                }
            );
        }
    }

    /**
     * Process the validation of data against a schema.
     *
     * @param ExecuteValidationSourceFolder|ExecuteValidationSourceFile|ExecuteValidationResource $validation The validation object.
     */
    private function processValidationData(string $data, ConnectionInterface $to, \stdClass $validation, string $dataPath, ?string $schema, string $pathForSchema): void
    {
        $validate         = (string) $validation->validate;
        $category         = (string) $validation->category;
        $message          = new \stdClass();
        $message->type    = 'success';
        $message->text    = "The Data file $dataPath exists";
        $message->classes = ".$validate.file-exists";
        $this->sendMessage($to, $message);

        $jsonData = json_decode($data);
        if (json_last_error() === JSON_ERROR_NONE) {
            $message          = new \stdClass();
            $message->type    = 'success';
            $message->text    = "The Data file $dataPath was successfully decoded as JSON";
            $message->classes = ".$validate.json-valid";
            $this->sendMessage($to, $message);

            if (null !== $schema) {
                $validationResult = $this->validateDataAgainstSchema($jsonData, $schema);
                if (gettype($validationResult) === 'boolean' && $validationResult === true) {
                    $message          = new \stdClass();
                    $message->type    = 'success';
                    $message->text    = "The Data file $dataPath was successfully validated against the Schema $schema";
                    $message->classes = ".$validate.schema-valid";
                    $this->sendMessage($to, $message);
                } elseif ($validationResult instanceof \stdClass) {
                    $validationResult->classes = ".$validate.schema-valid";
                    $this->sendMessage($to, $validationResult);
                }
            } else {
                $message          = new \stdClass();
                $message->type    = 'error';
                $message->text    = "executeValidation validation->sourceFile (JSON): Unable to detect schema for dataPath {$dataPath} and category {$category} (path for schema: $pathForSchema, Route::CALENDARS->path(): " . Route::CALENDARS->path() . ', LitSchema::METADATA->path(): ' . LitSchema::METADATA->path() . ')';
                $message->classes = ".$validate.schema-valid";
                $this->sendMessage($to, $message);
            }
        } else {
            $message          = new \stdClass();
            $message->type    = 'error';
            $message->text    = "There was an error decoding the Data file $dataPath as JSON: " . json_last_error_msg() . ". Raw data = &lt;&lt;&lt;JSON\n" . $data . "\n&gt;&gt;&gt;";
            $message->classes = ".$validate.json-valid";
            $this->sendMessage($to, $message);
        }
    }

    /**
     * Validates the specified liturgical calendar for a given year and category,
     * and sends the validation results to the specified connection.
     *
     * @param string $calendar The calendar identifier (e.g., 'VA' for Vatican).
     * @param int $year The year for which the calendar is to be validated.
     * @param string $category The type of calendar (e.g., 'nationalcalendar', 'diocesancalendar').
     * @param string $responseType The response format type (e.g., 'JSON', 'XML', 'ICS', 'YML').
     * @param ConnectionInterface $to The connection to which messages about the validation process are sent.
     *
     * This function retrieves the calendar data from a remote source based on the given parameters
     * and validates it against the appropriate schema. It supports multiple response types, including
     * XML, ICS, YML, and JSON. Validation results are sent as messages to the provided connection interface.
     */
    private function validateCalendar(string $calendar, int $year, string $category, string $responseType, ConnectionInterface $to): void
    {
        $returnTypeParam = ReturnTypeParam::from($responseType);
        $acceptMimeType  = $returnTypeParam->toAcceptMimeType();
        $opts            = [
            'headers' => [
                'Accept' => $acceptMimeType->value
            ]
        ];

        if ($calendar === 'VA') {
            $req = "/$year?year_type=CIVIL";
        } else {
            switch ($category) {
                case 'nationalcalendar':
                    $req = "/nation/$calendar/$year?year_type=CIVIL";
                    break;
                case 'diocesancalendar':
                    $req = "/diocese/$calendar/$year?year_type=CIVIL";
                    break;
                default:
                    //we shouldn't ever get any other categories
                    $req = '/unknown';
            }
        }

        $promise = $this->cachedGet(Route::CALENDAR->path() . $req, $opts);
        $promise->then(
            function (string $data) use ($to, $calendar, $year, $category, $req, $responseType) {
                $data = (string) $data; // force fresh string
                echo 'Fetched data for ' . Route::CALENDAR->path() . $req . ': got ' . strlen($data) . " bytes\n";

                $message          = new \stdClass();
                $message->type    = 'success';
                $message->text    = "The $category of $calendar for the year $year exists";
                $message->classes = ".calendar-$calendar.file-exists.year-$year";
                $this->sendMessage($to, $message);

                switch ($responseType) {
                    case 'XML':
                        libxml_use_internal_errors(true);
                        $xmlArr     = explode("\n", $data);
                        $xml        = new \DOMDocument();
                        $loadResult = $xml->loadXML($data);
                        //$xml = simplexml_load_string( $data );
                        if ($loadResult === false) {
                            $message       = new \stdClass();
                            $message->type = 'error';
                            $errors        = libxml_get_errors();
                            $errorString   = self::retrieveXmlErrors($errors, $xmlArr);
                            libxml_clear_errors();
                            $message->text         = "There was an error decoding the $category of $calendar for the year $year from the URL "
                                            . Route::CALENDAR->path() . $req . ' as XML: ' . $errorString;
                            $message->classes      = ".calendar-$calendar.json-valid.year-$year";
                            $message->responsetype = $responseType;
                            $this->sendMessage($to, $message);
                        } else {
                            $message          = new \stdClass();
                            $message->type    = 'success';
                            $message->text    = "The $category of $calendar for the year $year was successfully decoded as XML";
                            $message->classes = ".calendar-$calendar.json-valid.year-$year";
                            $this->sendMessage($to, $message);

                            $validationResult = $xml->schemaValidate(JsonData::SCHEMAS_FOLDER->path() . '/LiturgicalCalendar.xsd');
                            if ($validationResult) {
                                $message          = new \stdClass();
                                $message->type    = 'success';
                                $message->text    = sprintf(
                                    "The $category of $calendar for the year $year was successfully validated against the Schema %s",
                                    JsonData::SCHEMAS_FOLDER->path() . '/LiturgicalCalendar.xsd'
                                );
                                $message->classes = ".calendar-$calendar.schema-valid.year-$year";
                                $this->sendMessage($to, $message);
                            } else {
                                $errors      = libxml_get_errors();
                                $errorString = self::retrieveXmlErrors($errors, $xmlArr);
                                libxml_clear_errors();
                                $message          = new \stdClass();
                                $message->type    = 'error';
                                $message->text    = $errorString;
                                $message->classes = ".calendar-$calendar.schema-valid.year-$year";
                                $this->sendMessage($to, $message);
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
                            $message          = new \stdClass();
                            $message->type    = 'success';
                            $message->text    = "The $category of $calendar for the year $year was successfully decoded as ICS";
                            $message->classes = ".calendar-$calendar.json-valid.year-$year";
                            $this->sendMessage($to, $message);

                            $result = $vcalendar->validate();
                            if (count($result) === 0) {
                                $message          = new \stdClass();
                                $message->type    = 'success';
                                $message->text    = sprintf(
                                    "The $category of $calendar for the year $year was successfully validated according the iCalendar Schema %s",
                                    'https://tools.ietf.org/html/rfc5545'
                                );
                                $message->classes = ".calendar-$calendar.schema-valid.year-$year";
                                $this->sendMessage($to, $message);
                            } else {
                                $message       = new \stdClass();
                                $message->type = 'error';
                                $errorStrings  = [];
                                foreach ($result as $error) {
                                    /** @var array{level:int,message:string,node:VObject\Property} $error */
                                    $errorLevel = new ICSErrorLevel($error['level']);
                                    /** @var int $lineIndex The type is obvious, and declared, yet PHPStan seems to be a bit dumb on this one? */
                                    $lineIndex = $error['node']->lineIndex;
                                    /** @var string $lineString The type is obvious, and declared, yet PHPStan seems to be a bit dumb on this one? */
                                    $lineString     = $error['node']->lineString;
                                    $errorStrings[] = $errorLevel . ': ' . $error['message'] . " at line {$lineIndex} ({$lineString})";
                                }
                                $message->text    = implode('&#013;', $errorStrings);
                                $message->classes = ".calendar-$calendar.schema-valid.year-$year";
                                $this->sendMessage($to, $message);
                            }
                        } else {
                            $message               = new \stdClass();
                            $message->type         = 'error';
                            $message->text         = "There was an error decoding the $category of $calendar for the year $year from the URL "
                                            . Route::CALENDAR->path() . $req . ' as ICS: parsing resulted in type ' . gettype($vcalendar) . ' | ' . $vcalendar;
                            $message->classes      = ".calendar-$calendar.json-valid.year-$year";
                            $message->responsetype = $responseType;
                            $this->sendMessage($to, $message);
                        }
                        break;
                    case 'YML':
                        try {
                            /**
                             * TODO: perhaps we need to register a custom Exception handler, since yaml_parse() throws a warning instead of an exception
                             *       and we need to catch that warning as an exception {@see \LiturgicalCalendar\Api\Core::warningHandler()}
                             */
                            $yamlParsed = yaml_parse($data);
                            if (false === $yamlParsed) {
                                throw new \Exception('YAML parsing failed');
                            }

                            $jsonEncoded = json_encode($yamlParsed, JSON_THROW_ON_ERROR);
                            $yamlData    = json_decode($jsonEncoded);
                            if ($yamlData) {
                                $message          = new \stdClass();
                                $message->type    = 'success';
                                $message->text    = "The $category of $calendar for the year $year was successfully decoded as YAML";
                                $message->classes = ".calendar-$calendar.json-valid.year-$year";
                                $this->sendMessage($to, $message);

                                $validationResult = $this->validateDataAgainstSchema($yamlData, LitSchema::LITCAL->path());
                                if (gettype($validationResult) === 'boolean' && $validationResult === true) {
                                    $message          = new \stdClass();
                                    $message->type    = 'success';
                                    $message->text    = "The $category of $calendar for the year $year was successfully validated against the Schema " . LitSchema::LITCAL->path();
                                    $message->classes = ".calendar-$calendar.schema-valid.year-$year";
                                    $this->sendMessage($to, $message);
                                } elseif ($validationResult instanceof \stdClass) {
                                    $validationResult->classes = ".calendar-$calendar.schema-valid.year-$year";
                                    $this->sendMessage($to, $validationResult);
                                }
                            }
                        } catch (\Throwable $e) {
                            $message               = new \stdClass();
                            $message->type         = 'error';
                            $message->text         = "There was an error decoding the $category of $calendar for the year $year from the URL "
                                            . Route::CALENDAR->path() . $req . ' as YAML: ' . $e->getMessage();
                            $message->classes      = ".calendar-$calendar.json-valid.year-$year";
                            $message->responsetype = $responseType;
                            $this->sendMessage($to, $message);
                        }
                        break;
                    case 'JSON':
                    default:
                        $jsonData = json_decode($data);
                        if (false === ( $jsonData instanceof \stdClass )) {
                            $message               = new \stdClass();
                            $message->type         = 'error';
                            $message->text         = "There was an error decoding the $category of $calendar for the year $year from the URL "
                                            . Route::CALENDAR->path() . $req . ' as JSON: data was decoded to type ' . gettype($jsonData);
                            $message->classes      = ".calendar-$calendar.json-valid.year-$year";
                            $message->responsetype = $responseType;
                            $this->sendMessage($to, $message);
                            break;
                        }
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $message          = new \stdClass();
                            $message->type    = 'success';
                            $message->text    = "The $category of $calendar for the year $year was successfully decoded as JSON";
                            $message->classes = ".calendar-$calendar.json-valid.year-$year";
                            $this->sendMessage($to, $message);

                            $validationResult = $this->validateDataAgainstSchema($jsonData, LitSchema::LITCAL->path());
                            if (gettype($validationResult) === 'boolean' && $validationResult === true) {
                                $message          = new \stdClass();
                                $message->type    = 'success';
                                $message->text    = "The $category of $calendar for the year $year was successfully validated against the Schema " . LitSchema::LITCAL->path();
                                $message->classes = ".calendar-$calendar.schema-valid.year-$year";
                                $this->sendMessage($to, $message);
                            } elseif ($validationResult instanceof \stdClass) {
                                $validationResult->classes = ".calendar-$calendar.schema-valid.year-$year";
                                $this->sendMessage($to, $validationResult);
                            }
                        } else {
                            $message               = new \stdClass();
                            $message->type         = 'error';
                            $message->text         = "There was an error decoding the $category of $calendar for the year $year from the URL "
                                            . Route::CALENDAR->path() . $req . ' as JSON: ' . json_last_error_msg();
                            $message->classes      = ".calendar-$calendar.json-valid.year-$year";
                            $message->responsetype = $responseType;
                            $this->sendMessage($to, $message);
                        }
                }
            },
            function (\Throwable $e) use ($to, $calendar, $year, $category, $req) {
                $message          = new \stdClass();
                $message->type    = 'error';
                $message->text    = "The $category of $calendar for the year $year does not exist at the URL " . Route::CALENDAR->path() . $req . ' : ' . $e->getMessage();
                $message->classes = ".calendar-$calendar.file-exists.year-$year";
                $this->sendMessage($to, $message);
            }
        );
    }

    /**
     * Executes a unit test for a given Liturgical Calendar test.
     *
     * @param string $test The name of the unit test to be executed.
     * @param string $calendar The name of the calendar to be tested.
     * @param int $year The year for which the test should be executed.
     * @param string $category The type of calendar to be tested: nationalcalendar or diocesancalendar.
     * @param ConnectionInterface $to The connection to which the test result should be sent.
     */
    private function executeUnitTest(string $test, string $calendar, int $year, string $category, ConnectionInterface $to): void
    {
        $returnTypeParam = ReturnTypeParam::JSON;
        $acceptMimeType  = $returnTypeParam->toResponseContentType();
        $opts            = [
            'headers' => [
                'Accept' => $acceptMimeType->value
            ]
        ];

        if ($calendar === 'VA') {
            $req = "/$year?year_type=CIVIL";
        } else {
            switch ($category) {
                case 'nationalcalendar':
                    $req = "/nation/$calendar/$year?year_type=CIVIL";
                    break;
                case 'diocesancalendar':
                    $req = "/diocese/$calendar/$year?year_type=CIVIL";
                    break;
                default:
                    //we shouldn't ever get any other categories
                    $req = '/unknown';
            }
        }
        $promise = $this->cachedGet(Route::CALENDAR->path() . $req, $opts);
        $promise->then(
            function (string $data) use ($to, $test) {
                $data = (string) $data; // force fresh string
                /** @var \stdClass&object{settings:object{year:int,national_calendar?:string,diocesan_calendar?:string},litcal:LiturgicalEvent[]} $jsonData */
                $jsonData = json_decode($data);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $UnitTest = new LitTestRunner($test, $jsonData);
                    if ($UnitTest->isReady()) {
                        $UnitTest->runTest();
                    }
                    $this->sendMessage($to, $UnitTest->getMessage());
                }
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
     * @return PromiseInterface<string>
     */
    private function cachedFileGetContents(string $path, int $ttl = 300): PromiseInterface
    {
        $key = 'fgc_' . md5($path);

        if (apcu_exists($key)) {
            $deferred = new Deferred();
            echo "Cache hit for file $path\n";
            $data = apcu_fetch($key);
            if (is_string($data)) {
                $deferred->resolve($data);
            } else {
                $deferred->reject(new \RuntimeException("Cache fetch for file $path returned non-string data"));
            }
            /** @var PromiseInterface<string> $deferredPromise */
            $deferredPromise = $deferred->promise();
            return $deferredPromise;
        }

        echo "Cache miss for file $path, reading from filesystem\n";
        $filesystem = Factory::create();

        /** @var PromiseInterface<string> $fsPromise */
        $fsPromise = $filesystem->file($path)->getContents()->then(
            /** @param string $data @return string */
            function (string $data) use ($key, $ttl, $path) {
                $data = (string) $data;          // force fresh string
                echo "Read file $path, caching contents\n";
                $stored = apcu_store($key, $data, $ttl);
                echo ( $stored ? "Stored file in cache\n" : "Failed to store file in cache\n" );
                /** @var array{seg_size:int,avail_mem:int} $info */
                $info = apcu_sma_info(true);
                if (false !== $info) {
                    $total = isset($info['seg_size']) ? (int) $info['seg_size'] : 0;
                    $free  = isset($info['avail_mem']) ? (int) $info['avail_mem'] : 0;
                    if ($total > 0) { // avoid division by zero
                        $used    = $total - $free;
                        $percent = ( $used / $total ) * 100;

                        echo 'APCu used: ' . round($used / 1024 / 1024, 2) . ' MB of ' .
                            round($total / 1024 / 1024, 2) . ' MB (' .
                            round($percent, 2) . "%)\n";
                    }
                }
                //var_dump(apcu_exists($key), apcu_fetch($key));
                return $data; // resolved promise
            },
            /** @return never */
            function (\Throwable $e) use ($path) {
                throw new \RuntimeException("Unable to read file: $path", 0, $e);
            }
        );

        return $fsPromise;
    }

    /**
     * @param array{headers?:array{Accept:string}} $options
     *
     * @return PromiseInterface<string>
     */
    private function cachedGet(string $url, array $options = [], int $ttl = 300): PromiseInterface
    {
        $key      = 'http_' . md5($url . serialize($options));
        $deferred = new Deferred();

        // Return from cache if available
        if (apcu_exists($key)) {
            echo "Cache hit for $url\n";
            $data = apcu_fetch($key);
            if (is_string($data)) {
                $deferred->resolve($data);
            } else {
                $deferred->reject(new \RuntimeException("Cache fetch for URL $url returned non-string data"));
            }

            /** @var PromiseInterface<string> $deferredPromise */
            $deferredPromise = $deferred->promise();
            return $deferredPromise;
        }

        echo "Cache miss for $url, making HTTP request\n";

        $resolve = function (ResponseInterface $response) use ($deferred, $key, $ttl, $url) {
            echo "HTTP request completed for $url, caching response\n";
            $body   = (string) $response->getBody();
            $stored = apcu_store($key, $body, $ttl);
            echo ( $stored ? "Stored response body in cache\n" : "Failed to store response body in cache\n" );
            /** @var array{seg_size:int,avail_mem:int} $info */
            $info = apcu_sma_info(true);
            if (false !== $info) {
                $total = isset($info['seg_size']) ? (int) $info['seg_size'] : 0;
                $free  = isset($info['avail_mem']) ? (int) $info['avail_mem'] : 0;
                if ($total > 0) { // avoid division by zero
                    $used    = $total - $free;
                    $percent = ( $used / $total ) * 100;

                    echo 'APCu used: ' . round($used / 1024 / 1024, 2) . ' MB of ' .
                        round($total / 1024 / 1024, 2) . ' MB (' .
                        round($percent, 2) . "%)\n";
                }
            }
            //var_dump(apcu_exists($key), apcu_fetch($key));
            $this->inFlight--;
            $deferred->resolve($body);
        };

        $reject = function (\Throwable $e) use ($deferred) {
            echo 'HTTP request failed: ' . $e->getMessage() . "\n";
            $this->inFlight--;
            $deferred->reject($e);
        };

        $this->queue[] = [
            'url'     => $url,
            'options' => $options,
            'resolve' => $resolve,
            'reject'  => $reject
        ];

        /** @var PromiseInterface<string> $deferredPromise */
        $deferredPromise = $deferred->promise();
        $this->ensureTicking();
        return $deferredPromise;
    }

    private function processQueue(): void
    {
        while ($this->inFlight < $this->maxConcurrency && !empty($this->queue)) {
            [
                'url'     => $url,
                'options' => $options,
                'resolve' => $resolve,
                'reject'  => $reject
            ] = array_shift($this->queue);

            ++$this->inFlight;

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
            return;
        }
        $this->ticking = true;

        Loop::futureTick(function () {
            $this->drainHandler();
        });
    }

    private function drainHandler(): void
    {
        if ($this->inFlight > 0 || !empty($this->queue)) {
            // keep ticking until no requests are left
            Loop::futureTick(function () {
                $this->multiHandler->tick();
                $this->processQueue();
            });
        } else {
            // no active or queued requests
            $this->ticking = false;
        }
    }

    /**
     * Mapping of data file paths to the LitSchema constants that their JSON data should validate against.
     * The paths are relative to the root of the project. The LitSchema constants are used to determine
     * which schema to use when validating the JSON data.
     */
    private static function getPathToSchemaFile(string $dataFile): ?string
    {
        return match ($dataFile) {
            JsonData::MISSALS_FOLDER->value . '/propriumdetempore/propriumdetempore.json'                 => LitSchema::PROPRIUMDETEMPORE->path(),
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_1970/propriumdesanctis_1970.json'       => LitSchema::PROPRIUMDESANCTIS->path(),
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_2002/propriumdesanctis_2002.json'       => LitSchema::PROPRIUMDESANCTIS->path(),
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_2008/propriumdesanctis_2008.json'       => LitSchema::PROPRIUMDESANCTIS->path(),
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_IT_1983/propriumdesanctis_IT_1983.json' => LitSchema::PROPRIUMDESANCTIS->path(),
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_US_2011/propriumdesanctis_US_2011.json' => LitSchema::PROPRIUMDESANCTIS->path(),
            Route::CALENDARS->path()                                                                      => LitSchema::METADATA->path(),
            Route::DECREES->path()                                                                        => LitSchema::DECREES->path(),
            Route::EVENTS->path()                                                                         => LitSchema::EVENTS->path(),
            Route::TESTS->path()                                                                          => LitSchema::TESTS->path(),
            Route::EASTER->path()                                                                         => LitSchema::EASTER->path(),
            Route::MISSALS->path()                                                                        => LitSchema::MISSALS->path(),
            Route::DATA->path()                                                                           => LitSchema::DATA->path(),
            Route::SCHEMAS->path()                                                                        => LitSchema::SCHEMAS->path(),
            default => null
        };
    }

    /**
     * Validates the properties of a message object.
     *
     * This function checks the properties of a given message object to ensure
     * they match the expected properties defined in ACTION_PROPERTIES for the
     * specified action. If any expected property is missing from the message
     * object, the function returns false, indicating the message is invalid.
     *
     * @param ExecuteValidationSourceFolder|ExecuteValidationSourceFile|ExecuteValidationResource|ValidateCalendar|ExecuteUnitTest $message The message object to validate.
     * @return bool True if all required properties are present, false otherwise.
     */
    private static function validateMessageProperties(\stdClass $message): bool
    {
        $valid = true;
        foreach (Health::ACTION_PROPERTIES[$message->action] as $prop) {
            if (false === property_exists($message, $prop)) {
                if ($prop === 'sourceFile' && $message->action === 'executeValidation' && property_exists($message, 'sourceFolder')) {
                    continue;
                }
                return false;
            }
        }
        return $valid;
    }

    /**
     * Returns the appropriate schema for the given category and dataPath.
     * If dataPath is null, it will return the schema for the category.
     * If dataPath is not null, it will return the schema for the dataPath.
     * If the category is 'universalcalendar', it will return the schema from the DATA_PATH_TO_SCHEMA array.
     * If the category is 'nationalcalendar', 'diocesancalendar', 'widerregioncalendar', or 'propriumdesanctis',
     * it will return the corresponding schema constant.
     * If the category is 'resourceDataCheck', it will return the schema for the dataPath if it matches one of the patterns,
     * otherwise it will return the schema from the DATA_PATH_TO_SCHEMA array.
     * If the category is not recognized, it will return null.
     *
     * @param string $category The category of the data.
     * @param string $dataPath The path to the data.
     * @return string|null The schema for the given category and dataPath, or null if the category is not recognized.
     */
    private static function retrieveSchemaForCategory(string $category, string $dataPath): ?string
    {
        $versionedPattern     = '/\/api\/v[4-9]\//';
        $versionedReplacement = '/api/dev/';
        $isVersionedDataPath  = preg_match($versionedPattern, $dataPath) !== false;
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
            case 'nationalcalendar':
                return $isVersionedDataPath ? preg_replace($versionedPattern, $versionedReplacement, LitSchema::NATIONAL->path()) : LitSchema::NATIONAL->path();
            case 'diocesancalendar':
                return $isVersionedDataPath ? preg_replace($versionedPattern, $versionedReplacement, LitSchema::DIOCESAN->path()) : LitSchema::DIOCESAN->path();
            case 'widerregioncalendar':
                return $isVersionedDataPath ? preg_replace($versionedPattern, $versionedReplacement, LitSchema::WIDERREGION->path()) : LitSchema::WIDERREGION->path();
            case 'propriumdesanctis':
                return $isVersionedDataPath ? preg_replace($versionedPattern, $versionedReplacement, LitSchema::PROPRIUMDESANCTIS->path()) : LitSchema::PROPRIUMDESANCTIS->path();
            case 'resourceDataCheck':
                if (
                    preg_match('/\/missals\/[_A-Z0-9]+$/', $dataPath)
                ) {
                    return $isVersionedDataPath ? preg_replace($versionedPattern, $versionedReplacement, LitSchema::PROPRIUMDESANCTIS->path()) : LitSchema::PROPRIUMDESANCTIS->path();
                } elseif (
                    preg_match('/\/events\/(?:nation\/[A-Z]{2}|diocese\/[a-z]{6}_[a-z]{2})(?:\?locale=[a-zA-Z0-9_]+)?$/', $dataPath)
                ) {
                    return $isVersionedDataPath ? preg_replace($versionedPattern, $versionedReplacement, LitSchema::EVENTS->path()) : LitSchema::EVENTS->path();
                } elseif (
                    preg_match('/\/data\/(?:(nation)\/[A-Z]{2}|(diocese)\/[a-z]{6}_[a-z]{2}|(widerregion)\/[A-Z][a-z]+)(?:\?locale=[a-zA-Z0-9_]+)?$/', $dataPath, $matches)
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
                if (preg_match('/^national-calendar-[A-Z]{2}$/', $dataPath)) {
                    return LitSchema::NATIONAL->path();
                }
                if (preg_match('/^diocesan-calendar-[a-z]{6}_[a-z]{2}$/', $dataPath)) {
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
     * Takes an array of LIBXML errors and an array of XML lines
     * and returns a string of the errors with line numbers and column numbers.
     * @param \LibXMLError[] $errors Array of LIBXML errors
     * @param string[] $xml Array of strings, each string is a line in the XML document
     * @return string The errors with line numbers and column numbers
     */
    private static function retrieveXmlErrors(array $errors, array $xml): string
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
                      . htmlspecialchars(trim($xml[$error->line - 1])) . ')';
            if ($error->file) {
                $errorStr .= " in file: $error->file";
            }
            array_push($return, $errorStr);
        }
        return implode('&#013;', $return);
    }
}
