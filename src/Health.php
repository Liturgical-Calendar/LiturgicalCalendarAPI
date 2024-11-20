<?php

namespace LiturgicalCalendar\Api;

use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;
use Sabre\VObject;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use LiturgicalCalendar\Api\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Enum\ICSErrorLevel;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\ReturnType;
use LiturgicalCalendar\Api\Enum\Route;

/**
 * This class provides a WebSocket-based interface for executing various tests
 * of the Liturgical Calendar API, such as JSON schema validation and unit tests.
 *
 * @package LiturgicalCalendar\Api
 * @author  John Romano D'Orazio <priest@johnromanodorazio.com>
 * @license https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @version 3.9
 * @link    https://litcal.johnromanodorazio.com
 */
class Health implements MessageComponentInterface
{
    /**
     * A collection of connected clients.
     *
     * @var \SplObjectStorage
     */
    protected \SplObjectStorage $clients;

    /**
     * The path that the health check will use to query the API.
     *
     * @var string $REQPATH
     */
    private const REQPATH = API_BASE_PATH . Route::CALENDAR->value;

    /**
     * Array of actions that the Health endpoint can execute.
     * Each key is an action name. The value is an array of strings that represent the names of the
     * parameters that the action requires.
     *
     * @var array<string, string[]> $ACTION_PROPERTIES
     */
    private const ACTION_PROPERTIES = [
        "executeValidation" => ["validate", "sourceFile", "category"],
        "validateCalendar"  => ["calendar", "year", "category", "responsetype"],
        "executeUnitTest"   => ["calendar", "year", "category", "test"]
    ];

    private static ?object $metadata = null;

    /**
     * Mapping of data file paths to the LitSchema constants that their JSON data should validate against.
     * The paths are relative to the root of the project. The LitSchema constants are used to determine
     * which schema to use when validating the JSON data.
     *
     * @var string[] $DATA_PATH_TO_SCHEMA
     */
    public const DATA_PATH_TO_SCHEMA = [
        "jsondata/sourcedata/missals/propriumdetempore/propriumdetempore.json"              => LitSchema::PROPRIUMDETEMPORE,
        "jsondata/sourcedata/missals/propriumdesanctis_1970/propriumdesanctis_1970.json"    => LitSchema::PROPRIUMDESANCTIS,
        "jsondata/sourcedata/missals/propriumdesanctis_2002/propriumdesanctis_2002.json"    => LitSchema::PROPRIUMDESANCTIS,
        "jsondata/sourcedata/missals/propriumdesanctis_2008/propriumdesanctis_2008.json"    => LitSchema::PROPRIUMDESANCTIS,
        "jsondata/sourcedata/missals/propriumdesanctis_IT_1983/propriumdesanctis_IT_1983.json"
                                                                             => LitSchema::PROPRIUMDESANCTIS,
        "jsondata/sourcedata/missals/propriumdesanctis_US_2011/propriumdesanctis_US_2011"   => LitSchema::PROPRIUMDESANCTIS,
        "jsondata/sourcedata/nations/index.json"                                            => LitSchema::INDEX,
        API_BASE_PATH . '/calendars'                                         => LitSchema::METADATA,
        API_BASE_PATH . '/decrees'                                           => LitSchema::DECREES,
        API_BASE_PATH . '/events'                                            => LitSchema::EVENTS,
        API_BASE_PATH . '/tests'                                             => LitSchema::TESTS,
        API_BASE_PATH . '/easter'                                            => LitSchema::EASTER,
        API_BASE_PATH . '/missals'                                           => LitSchema::MISSALS,
        API_BASE_PATH . '/data'                                              => LitSchema::DATA,
        API_BASE_PATH . '/schemas'                                           => LitSchema::SCHEMAS
    ];

    /**
     * Called when a new client connection is established.
     *
     * This stores the new connection to send messages to later.
     */
    public function onOpen(ConnectionInterface $conn)
    {
        // Store the new connection to send messages to later
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";

        if (null === self::$metadata) {
            self::$metadata = json_decode(file_get_contents(API_BASE_PATH . '/calendars'));
            if (JSON_ERROR_NONE === json_last_error()) {
                echo "Loaded metadata\n";
            } else {
                echo "Error loading metadata: " . json_last_error_msg() . "\n";
            }
        } else {
            if (property_exists(self::$metadata, 'litcal_metadata') && property_exists(self::$metadata->litcal_metadata, 'diocesan_calendars')) {
                echo "Metadata was already loaded and has required diocesan_calendars property\n";
            } else {
                echo "Error loading metadata: missing diocesan_calendars property\n";
                echo json_encode(self::$metadata);
            }
        }
    }

    /**
     * Validates the properties of a message object.
     *
     * This function checks the properties of a given message object to ensure
     * they match the expected properties defined in ACTION_PROPERTIES for the
     * specified action. If any expected property is missing from the message
     * object, the function returns false, indicating the message is invalid.
     *
     * @param object $message The message object to validate.
     * @return bool True if all required properties are present, false otherwise.
     */
    private static function validateMessageProperties(object $message): bool
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
     * Handle an incoming message.
     *
     * This function is called whenever a user sends a message to the WebSocket
     * server. It is responsible for parsing the message, validating it, and then
     * executing the action specified.
     *
     * @param ConnectionInterface $from The user who sent the message
     * @param string $msg The message that was sent
     */
    public function onMessage(ConnectionInterface $from, $msg)
    {
        echo sprintf('Receiving message from connection %d: %s', $from->resourceId, $msg);
        $messageReceived = json_decode($msg);
        if (
            json_last_error() === JSON_ERROR_NONE
            && property_exists($messageReceived, 'action')
            && self::validateMessageProperties($messageReceived)
        ) {
            switch ($messageReceived->action) {
                case 'executeValidation':
                    $this->executeValidation($messageReceived, $from);
                    break;
                case 'validateCalendar':
                    $this->validateCalendar(
                        $messageReceived->calendar,
                        $messageReceived->year,
                        $messageReceived->category,
                        $messageReceived->responsetype,
                        $from
                    );
                    break;
                case 'executeUnitTest':
                    $this->executeUnitTest(
                        $messageReceived->test,
                        $messageReceived->calendar,
                        $messageReceived->year,
                        $messageReceived->category,
                        $from
                    );
                    break;
                default:
                    $message = new \stdClass();
                    $message->type = "echobot";
                    $message->text = $msg;
                    $this->sendMessage($from, $message);
            }
        } else {
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errorMsg = json_last_error_msg();
            } elseif (!property_exists($messageReceived, 'action')) {
                $errorMsg = 'No action specified';
            } elseif (!self::validateMessageProperties($messageReceived)) {
                $errorMsg = 'Invalid message properties';
            }
            echo sprintf('Invalid message from connection %1$d: %2$s (%3$s)', $from->resourceId, $errorMsg, $msg);
            $message = new \stdClass();
            $message->type = "echobot";
            $message->errorMsg = $errorMsg;
            $message->text = sprintf('Invalid message from connection %d: %s', $from->resourceId, $msg);
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
    public function onClose(ConnectionInterface $conn)
    {
        // The connection is closed, remove it, as we can no longer send it messages
        $this->clients->detach($conn);

        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    /**
     * Handles errors that occur on a connection.
     *
     * Logs the error message and closes the connection.
     *
     * @param ConnectionInterface $conn The connection on which the error occurred
     * @param \Exception $e The exception that was thrown
     */
    public function onError(ConnectionInterface $conn, \Exception $e)
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
    private function sendMessage(ConnectionInterface $from, string|\stdClass $msg)
    {
        if (gettype($msg) !== 'string') {
            $msg = json_encode($msg);
        }
        foreach ($this->clients as $client) {
            if ($from === $client) {
                // The message from sender will be echoed back only to the sender, not to other clients
                $client->send($msg);
            }
        }
    }

    /**
     * Initializes the Health object with an empty SplObjectStorage.
     *
     * The SplObjectStorage is used to store client connections.
     */
    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
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
     * @param string|null $dataPath The path to the data.
     * @return string|null The schema for the given category and dataPath, or null if the category is not recognized.
     */
    private static function retrieveSchemaForCategory(string $category, ?string $dataPath = null): ?string
    {
        switch ($category) {
            case 'universalcalendar':
                return Health::DATA_PATH_TO_SCHEMA[ $dataPath ];
            case 'nationalcalendar':
                return LitSchema::NATIONAL;
            case 'diocesancalendar':
                return LitSchema::DIOCESAN;
            case 'widerregioncalendar':
                return LitSchema::WIDERREGION;
            case 'propriumdesanctis':
                return LitSchema::PROPRIUMDESANCTIS;
            case 'resourceDataCheck':
                if (
                    preg_match("/\/missals\/[_A-Z0-9]+$/", $dataPath)
                ) {
                    return LitSchema::PROPRIUMDESANCTIS;
                } elseif (
                    preg_match("/\/events\/(?:nation|diocese)\/[_a-zA-Z0-9]+$/", $dataPath)
                ) {
                    return LitSchema::EVENTS;
                } elseif (
                    preg_match("/\/data\/(nation|diocese|widerregion)/", $dataPath, $matches)
                ) {
                    $schema = LitSchema::DATA;
                    switch ($matches[1]) {
                        case 'nation':
                            $schema = LitSchema::NATIONAL;
                            break;
                        case 'diocese':
                            $schema = LitSchema::DIOCESAN;
                            break;
                        case 'widerregion':
                            $schema = LitSchema::WIDERREGION;
                            break;
                    }
                    return $schema;
                }
                return Health::DATA_PATH_TO_SCHEMA[ $dataPath ];
                break;
            case 'sourceDataCheck':
                if (preg_match("/-i18n$/", $dataPath)) {
                    return LitSchema::I18N;
                }
                if (preg_match("/^memorials-from-decrees$/", $dataPath)) {
                    return LitSchema::DECREES_SRC;
                }
                if (preg_match("/^proprium-de-sanctis(?:-[A-Z]{2})?-(?:1|2)(?:9|0)(?:7|8|9|0|1|2)[0-9]$/", $dataPath)) {
                    return LitSchema::PROPRIUMDESANCTIS;
                }
                if (preg_match("/^proprium-de-tempore$/", $dataPath)) {
                    return LitSchema::PROPRIUMDETEMPORE;
                }
                if (preg_match("/^regional-calendars-index$/", $dataPath)) {
                    return LitSchema::INDEX;
                }
                if (preg_match("/^wider-region-[A-Z][a-z]+$/", $dataPath)) {
                    return LitSchema::WIDERREGION;
                }
                if (preg_match("/^national-calendar-[A-Z]{2}$/", $dataPath)) {
                    return LitSchema::NATIONAL;
                }
                if (preg_match("/^diocesan-calendar-[a-z]{6}_[a-z]{2}$/", $dataPath)) {
                    return LitSchema::DIOCESAN;
                }
                if (preg_match("/^tests-[a-zA-Z0-9_]+$/", $dataPath)) {
                    return LitSchema::TEST_SRC;
                }
                return null;
        }
        return null;
    }

    /**
     * Validate a data file by checking that it exists and that it is valid JSON that conforms to a specific schema.
     *
     * @param object $validation The validation object. It should have the following properties:
     * - sourceFile: a string, the path to the data file
     * - validate: an object with the following properties:
     *   - file-exists: a string, the class name to add to the message if the file exists
     *   - json-valid: a string, the class name to add to the message if the file is valid JSON
     *   - schema-valid: a string, the class name to add to the message if the file is valid against the schema
     * @param ConnectionInterface $to The connection to send the validation message to
     */
    private function executeValidation(object $validation, ConnectionInterface $to)
    {
        if ($validation->category === 'sourceDataCheck') {
            $pathForSchema      = $validation->validate;
            if (property_exists($validation, 'sourceFolder')) {
                $dataPath       = rtrim($validation->sourceFolder, '/');
            } else {
                $dataPath       = $validation->sourceFile;
            }
        } else {
            $pathForSchema      = $validation->sourceFile;
            $dataPath           = $validation->sourceFile;
        }

        $schema = Health::retrieveSchemaForCategory($validation->category, $pathForSchema);

        if (property_exists($validation, 'sourceFolder')) {
            $files = glob($dataPath . '/*.json');
            if (false === $files || empty($files)) {
                $message = new \stdClass();
                $message->type = "error";
                $message->text = "Data folder $validation->sourceFolder does not exist or does not contain any json files";
                $message->classes = ".$validation->validate.file-exists";
                $this->sendMessage($to, $message);
                return;
            }
            $fileExistsAndIsReadable = true;
            $jsonDecodable           = true;
            $schemaValidated         = true;
            foreach ($files as $file) {
                $filename = pathinfo($file, PATHINFO_BASENAME);

                $matchI8nFile = preg_match("/(?:[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_[A-Z]{2})?|(?:ar|en|eo)_001|(?:en_150|es_419))\.json$/", $filename);
                if (false === $matchI8nFile || 0 === $matchI8nFile) {
                    $fileExistsAndIsReadable = false;
                    $message = new \stdClass();
                    $message->type = "error";
                    $message->text = "Data folder $validation->sourceFolder contains an invalid i18n json filename $filename";
                    $message->classes = ".$validation->validate.file-exists";
                    $this->sendMessage($to, $message);
                } else {
                    $fileData = file_get_contents($file);
                    if (false === $fileData) {
                        $fileExistsAndIsReadable = false;
                        $message = new \stdClass();
                        $message->type = "error";
                        $message->text = "Data folder $validation->sourceFolder contains an unreadable i18n json file $filename";
                        $message->classes = ".$validation->validate.file-exists";
                        $this->sendMessage($to, $message);
                    } else {
                        $jsonData = json_decode($fileData);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $jsonDecodable = false;
                            $message = new \stdClass();
                            $message->type = "error";
                            $message->text = "The i18n json file $filename was not successfully decoded as JSON: " . json_last_error_msg();
                            $message->classes = ".$validation->validate.json-valid";
                            $this->sendMessage($to, $message);
                        } else {
                            if (null !== $schema) {
                                $validationResult = $this->validateDataAgainstSchema($jsonData, $schema);
                                if (gettype($validationResult) === 'object') {
                                    $schemaValidated = false;
                                    $validationResult->classes = ".$validation->validate.schema-valid";
                                    $this->sendMessage($to, $validationResult);
                                }
                            } else {
                                $message = new \stdClass();
                                $message->type = "error";
                                $message->text = "Unable to detect a schema for {$validation->validate} and category {$validation->category}";
                                $message->classes = ".$validation->validate.schema-valid";
                                $this->sendMessage($to, $message);
                            }
                        }
                    }
                }
            }
            if ($fileExistsAndIsReadable) {
                $message = new \stdClass();
                $message->type = "success";
                $message->text = "The Data folder $validation->sourceFolder exists and contains valid i18n json files";
                $message->classes = ".$validation->validate.file-exists";
                $this->sendMessage($to, $message);
            }
            if ($jsonDecodable) {
                $message = new \stdClass();
                $message->type = "success";
                $message->text = "The i18n json files in Data folder $validation->sourceFolder were successfully decoded as JSON";
                $message->classes = ".$validation->validate.json-valid";
                $this->sendMessage($to, $message);
            }
            if ($schemaValidated) {
                $message = new \stdClass();
                $message->type = "success";
                $message->text = "The i18n json files in Data folder $validation->sourceFolder were successfully validated against the Schema $schema";
                $message->classes = ".$validation->validate.schema-valid";
                $this->sendMessage($to, $message);
            }
        } else {
            $matches = null;
            if (preg_match("/^diocesan-calendar-([a-z]{6}_[a-z]{2})$/", $pathForSchema, $matches)) {
                $dioceseId = $matches[1];
                $dioceseName = array_values(array_filter(self::$metadata->litcal_metadata->diocesan_calendars, fn ($diocesan_calendar) => $diocesan_calendar->calendar_id === $dioceseId))[0]->diocese;
                $dataPath = preg_replace("/nations\/([A-Z]{2})\/(?:[a-z]{6}_[a-z]{2})\.json$/", "nations/$1/$dioceseName.json", $dataPath);
            }
            $data = file_get_contents($dataPath);
            if ($data !== false) {
                $message = new \stdClass();
                $message->type = "success";
                $message->text = "The Data file $dataPath exists";
                $message->classes = ".$validation->validate.file-exists";
                $this->sendMessage($to, $message);

                $jsonData = json_decode($data);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $message = new \stdClass();
                    $message->type = "success";
                    $message->text = "The Data file $dataPath was successfully decoded as JSON";
                    $message->classes = ".$validation->validate.json-valid";
                    $this->sendMessage($to, $message);

                    if (null !== $schema) {
                        $validationResult = $this->validateDataAgainstSchema($jsonData, $schema);
                        if (gettype($validationResult) === 'boolean' && $validationResult === true) {
                            $message = new \stdClass();
                            $message->type = "success";
                            $message->text = "The Data file $dataPath was successfully validated against the Schema $schema";
                            $message->classes = ".$validation->validate.schema-valid";
                            $this->sendMessage($to, $message);
                        } elseif (gettype($validationResult === 'object')) {
                            $validationResult->classes = ".$validation->validate.schema-valid";
                            $this->sendMessage($to, $validationResult);
                        }
                    } else {
                        $message = new \stdClass();
                        $message->type = "error";
                        $message->text = "Unable to detect schema for dataPath {$dataPath} and category {$validation->category}";
                        $message->classes = ".$validation->validate.schema-valid";
                        $this->sendMessage($to, $message);
                    }
                } else {
                    $message = new \stdClass();
                    $message->type = "error";
                    $message->text = "There was an error decoding the Data file $dataPath as JSON: " . json_last_error_msg() . " :: Raw data = <<<JSON\n$data\n>>>";
                    $message->classes = ".$validation->validate.json-valid";
                    $this->sendMessage($to, $message);
                }
            } else {
                $message = new \stdClass();
                $message->type = "error";
                $message->text = "Data file $dataPath does not exist";
                $message->classes = ".$validation->validate.file-exists";
                $this->sendMessage($to, $message);
            }
        }
    }

    /**
     * Takes an array of LIBXML errors and an array of XML lines
     * and returns a string of the errors with line numbers and column numbers.
     * @param array $errors Array of LIBXML errors
     * @param array $xml Array of strings, each string is a line in the XML document
     * @return string The errors with line numbers and column numbers
     */
    private static function retrieveXmlErrors(array $errors, array $xml): string
    {
        $return = [];
        foreach ($errors as $error) {
            $errorStr = "";
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
                      . htmlspecialchars(trim($xml[$error->line - 1])) . ")";
            if ($error->file) {
                $errorStr .= " in file: $error->file";
            }
            array_push($return, $errorStr);
        }
        return implode('&#013;', $return);
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
        //get the index of the responsetype from the ReturnType class
        $responseTypeIdx = array_search($responseType, ReturnType::$values);
        //get the corresponding accept mime type
        $acceptMimeType = AcceptHeader::$values[$responseTypeIdx];
        $opts = [
            "http" => [
                "method" => "GET",
                "header" => "Accept: $acceptMimeType\r\n"
            ]
        ];
        $context = stream_context_create($opts);
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
            }
        }
        $data = file_get_contents(self::REQPATH . $req, false, $context);
        if ($data !== false) {
            $message = new \stdClass();
            $message->type = "success";
            $message->text = "The $category of $calendar for the year $year exists";
            $message->classes = ".calendar-$calendar.file-exists.year-$year";
            $this->sendMessage($to, $message);

            switch ($responseType) {
                case "XML":
                    libxml_use_internal_errors(true);
                    $xml = new \DOMDocument();
                    $xml->loadXML($data);
                    //$xml = simplexml_load_string( $data );
                    $xmlArr = explode("\n", $data);
                    if ($xml === false) {
                        $message = new \stdClass();
                        $message->type = "error";
                        $errors = libxml_get_errors();
                        $errorString = self::retrieveXmlErrors($errors, $xmlArr);
                        libxml_clear_errors();
                        $message->text = "There was an error decoding the $category of $calendar for the year $year from the URL "
                                        . self::REQPATH . $req . " as XML: " . $errorString;
                        $message->classes = ".calendar-$calendar.json-valid.year-$year";
                        $message->responsetype = $responseType;
                        $this->sendMessage($to, $message);
                    } else {
                        $message = new \stdClass();
                        $message->type = "success";
                        $message->text = "The $category of $calendar for the year $year was successfully decoded as XML";
                        $message->classes = ".calendar-$calendar.json-valid.year-$year";
                        $this->sendMessage($to, $message);

                        $validationResult = $xml->schemaValidate('jsondata/schemas/LiturgicalCalendar.xsd');
                        if ($validationResult) {
                            $message = new \stdClass();
                            $message->type = "success";
                            $message->text = sprintf(
                                "The $category of $calendar for the year $year was successfully validated against the Schema %s",
                                "jsondata/schemas/LiturgicalCalendar.xsd"
                            );
                            $message->classes = ".calendar-$calendar.schema-valid.year-$year";
                            $this->sendMessage($to, $message);
                        } else {
                            $errors = libxml_get_errors();
                            $errorString = self::retrieveXmlErrors($errors, $xmlArr);
                            libxml_clear_errors();
                            $message = new \stdClass();
                            $message->type = "error";
                            $message->text = $errorString;
                            $message->classes = ".calendar-$calendar.schema-valid.year-$year";
                            $this->sendMessage($to, $message);
                        }
                    }
                    break;
                case "ICS":
                    try {
                        $vcalendar = VObject\Reader::read($data);
                    } catch (VObject\ParseException $ex) {
                        $vcalendar = json_encode($ex);
                    }
                    if ($vcalendar instanceof VObject\Document) {
                        $message = new \stdClass();
                        $message->type = "success";
                        $message->text = "The $category of $calendar for the year $year was successfully decoded as ICS";
                        $message->classes = ".calendar-$calendar.json-valid.year-$year";
                        $this->sendMessage($to, $message);

                        $result = $vcalendar->validate();
                        if (count($result) === 0) {
                            $message = new \stdClass();
                            $message->type = "success";
                            $message->text = sprintf(
                                "The $category of $calendar for the year $year was successfully validated according the iCalendar Schema %s",
                                "https://tools.ietf.org/html/rfc5545"
                            );
                            $message->classes = ".calendar-$calendar.schema-valid.year-$year";
                            $this->sendMessage($to, $message);
                        } else {
                            $message = new \stdClass();
                            $message->type = "error";
                            $errorStrings = [];
                            foreach ($result as $error) {
                                $errorLevel = new ICSErrorLevel($error['level']);
                                //TODO: implement $error['node']->lineIndex and $error['node']->lineString if and when the PR is accepted upstream...
                                $errorStrings[] = $errorLevel . ": " . $error['message'];// . " at line {$error['node']->lineIndex} ({$error['node']->lineString})"
                            }
                            $message->text = implode('&#013;', $errorStrings) || "validation encountered " . count($result) . " errors";
                            $message->classes = ".calendar-$calendar.schema-valid.year-$year";
                            $this->sendMessage($to, $message);
                        }
                    } else {
                        $message = new \stdClass();
                        $message->type = "error";
                        $message->text = "There was an error decoding the $category of $calendar for the year $year from the URL "
                                        . self::REQPATH . $req . " as ICS: parsing resulted in type " . gettype($vcalendar) . " | " . $vcalendar;
                        $message->classes = ".calendar-$calendar.json-valid.year-$year";
                        $message->responsetype = $responseType;
                        $this->sendMessage($to, $message);
                    }
                    break;
                case "YML":
                    try {
                        $yamlData = json_decode(json_encode(yaml_parse($data)));
                        if ($yamlData) {
                            $message = new \stdClass();
                            $message->type = "success";
                            $message->text = "The $category of $calendar for the year $year was successfully decoded as YAML";
                            $message->classes = ".calendar-$calendar.json-valid.year-$year";
                            $this->sendMessage($to, $message);

                            $validationResult = $this->validateDataAgainstSchema($yamlData, LitSchema::LITCAL);
                            if (gettype($validationResult) === 'boolean' && $validationResult === true) {
                                $message = new \stdClass();
                                $message->type = "success";
                                $message->text = "The $category of $calendar for the year $year was successfully validated against the Schema " . LitSchema::LITCAL;
                                $message->classes = ".calendar-$calendar.schema-valid.year-$year";
                                $this->sendMessage($to, $message);
                            } elseif (gettype($validationResult === 'object')) {
                                $validationResult->classes = ".calendar-$calendar.schema-valid.year-$year";
                                $this->sendMessage($to, $validationResult);
                            }
                        }
                    } catch (\Exception $ex) {
                        $message = new \stdClass();
                        $message->type = "error";
                        $message->text = "There was an error decoding the $category of $calendar for the year $year from the URL "
                                        . self::REQPATH . $req . " as YAML: " . $ex->getMessage();
                        $message->classes = ".calendar-$calendar.json-valid.year-$year";
                        $message->responsetype = $responseType;
                        $this->sendMessage($to, $message);
                    }
                    break;
                case "JSON":
                default:
                    $jsonData = json_decode($data);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $message = new \stdClass();
                        $message->type = "success";
                        $message->text = "The $category of $calendar for the year $year was successfully decoded as JSON";
                        $message->classes = ".calendar-$calendar.json-valid.year-$year";
                        $this->sendMessage($to, $message);

                        $validationResult = $this->validateDataAgainstSchema($jsonData, LitSchema::LITCAL);
                        if (gettype($validationResult) === 'boolean' && $validationResult === true) {
                            $message = new \stdClass();
                            $message->type = "success";
                            $message->text = "The $category of $calendar for the year $year was successfully validated against the Schema " . LitSchema::LITCAL;
                            $message->classes = ".calendar-$calendar.schema-valid.year-$year";
                            $this->sendMessage($to, $message);
                        } elseif (gettype($validationResult === 'object')) {
                            $validationResult->classes = ".calendar-$calendar.schema-valid.year-$year";
                            $this->sendMessage($to, $validationResult);
                        }
                    } else {
                        $message = new \stdClass();
                        $message->type = "error";
                        $message->text = "There was an error decoding the $category of $calendar for the year $year from the URL "
                                        . self::REQPATH . $req . " as JSON: " . json_last_error_msg();
                        $message->classes = ".calendar-$calendar.json-valid.year-$year";
                        $message->responsetype = $responseType;
                        $this->sendMessage($to, $message);
                    }
            }
        } else {
            $message = new \stdClass();
            $message->type = "error";
            $message->text = "The $category of $calendar for the year $year does not exist at the URL " . self::REQPATH . $req;
            $message->classes = ".calendar-$calendar.file-exists.year-$year";
            $this->sendMessage($to, $message);
        }
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
        //get the index of the responsetype from the ReturnType class
        $responseTypeIdx = array_search("JSON", ReturnType::$values);
        //get the corresponding accept mime type
        $acceptMimeType = AcceptHeader::$values[$responseTypeIdx];
        $opts = [
            "http" => [
                "method" => "GET",
                "header" => "Accept: $acceptMimeType\r\n"
            ]
        ];
        $context = stream_context_create($opts);
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
            }
        }
        $data = file_get_contents(self::REQPATH . $req, false, $context);
        // We don't really need to check whether file_get_contents succeeded
        //  because this check already takes place in the validateCalendar test phase
        $jsonData = json_decode($data);
        if (json_last_error() === JSON_ERROR_NONE) {
            $UnitTest = new LitTest($test, $jsonData);
            if ($UnitTest->isReady()) {
                $UnitTest->runTest();
            }
            $this->sendMessage($to, $UnitTest->getMessage());
        }
    }

    /**
     * Validate data against a specified schema.
     *
     * @param object|array $data The data to validate.
     * @param string $schemaUrl The URL of the schema to validate against.
     *
     * @return bool|object Returns true if the data is valid, otherwise returns an error object with details.
     */
    private function validateDataAgainstSchema(object|array $data, string $schemaUrl): bool|object
    {
        $res = false;
        try {
            $schema = Schema::import($schemaUrl);
            $schema->in($data);
            $res = true;
        } catch (InvalidValue | \Exception $e) {
            $message = new \stdClass();
            $message->type = "error";
            $message->text = LitSchema::ERROR_MESSAGES[ $schemaUrl ] . PHP_EOL . $e->getMessage();
            return $message;
        }
        return $res;
    }
}
