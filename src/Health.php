<?php

namespace Johnrdorazio\LitCal;

use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;
use Sabre\VObject;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Johnrdorazio\LitCal\Enum\AcceptHeader;
use Johnrdorazio\LitCal\Enum\ICSErrorLevel;
use Johnrdorazio\LitCal\Enum\LitSchema;
use Johnrdorazio\LitCal\Enum\ReturnType;
use Johnrdorazio\LitCal\Enum\Route;

class Health implements MessageComponentInterface
{
    protected $clients;
    private const REQPATH = API_BASE_PATH . Route::CALENDAR->value;
    public function onOpen(ConnectionInterface $conn)
    {
        // Store the new connection to send messages to later
        $this->clients->attach($conn);

        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        echo sprintf('Receiving message "%s" from connection %d', $msg, $from->resourceId);
        $messageReceived = json_decode($msg);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (property_exists($messageReceived, 'action')) {
                switch ($messageReceived->action) {
                    case 'executeValidation':
                        if (
                            property_exists($messageReceived, 'validate') &&
                            property_exists($messageReceived, 'sourceFile') &&
                            property_exists($messageReceived, 'category')
                        ) {
                            $this->executeValidation($messageReceived, $from);
                        }
                        break;
                    case 'validateCalendar':
                        if (
                            property_exists($messageReceived, 'calendar') &&
                            property_exists($messageReceived, 'year') &&
                            property_exists($messageReceived, 'category') &&
                            property_exists($messageReceived, 'responsetype')
                        ) {
                            $this->validateCalendar(
                                $messageReceived->calendar,
                                $messageReceived->year,
                                $messageReceived->category,
                                $messageReceived->responsetype,
                                $from
                            );
                        }
                        break;
                    case 'executeUnitTest':
                        if (
                            property_exists($messageReceived, 'calendar') &&
                            property_exists($messageReceived, 'year') &&
                            property_exists($messageReceived, 'category') &&
                            property_exists($messageReceived, 'test')
                        ) {
                            $this->executeUnitTest($messageReceived->test, $messageReceived->calendar, $messageReceived->year, $messageReceived->category, $from);
                        }
                        break;
                    default:
                        $message = new \stdClass();
                        $message->type = "echobot";
                        $message->text = $msg;
                        $this->sendMessage($from, $message);
                }
            }
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        // The connection is closed, remove it, as we can no longer send it messages
        $this->clients->detach($conn);

        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }

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

    public const DATA_PATH_TO_SCHEMA = [
        "data/propriumdetempore.json"                                => LitSchema::PROPRIUMDETEMPORE,
        "data/propriumdesanctis_1970/propriumdesanctis_1970.json"    => LitSchema::PROPRIUMDESANCTIS,
        "data/propriumdesanctis_2002/propriumdesanctis_2002.json"    => LitSchema::PROPRIUMDESANCTIS,
        "data/propriumdesanctis_2008/propriumdesanctis_2008.json"    => LitSchema::PROPRIUMDESANCTIS,
        "data/propriumdesanctis_ITALY_1983/propriumdesanctis_ITALY_1983.json"
                                                                     => LitSchema::PROPRIUMDESANCTIS,
        "data/propriumdesanctis_USA_2011/propriumdesanctis_USA_2011" => LitSchema::PROPRIUMDESANCTIS,
        "nations/index.json"                                         => LitSchema::INDEX,
        API_BASE_PATH . '/calendars/'                                => LitSchema::METADATA,
        API_BASE_PATH . '/decrees/'                                  => LitSchema::DECREEMEMORIALS,
        API_BASE_PATH . '/events/'                                   => LitSchema::EVENTS,
        API_BASE_PATH . '/tests/'                                    => LitSchema::TESTS,
        API_BASE_PATH . '/easter/'                                   => LitSchema::EASTER,
        API_BASE_PATH . '/missals/'                                  => LitSchema::MISSALS,
        API_BASE_PATH . '/data/'                                     => LitSchema::DATA
    ];

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
    }

    private function executeValidation(object $validation, ConnectionInterface $to)
    {
        $dataPath = $validation->sourceFile;
        switch ($validation->category) {
            case 'universalcalendar':
                $schema = Health::DATA_PATH_TO_SCHEMA[ $dataPath ];
                break;
            case 'nationalcalendar':
                $schema = LitSchema::NATIONAL;
                break;
            case 'diocesancalendar':
                $schema = LitSchema::DIOCESAN;
                break;
            case 'widerregioncalendar':
                $schema = LitSchema::WIDERREGION;
                break;
            case 'propriumdesanctis':
                $schema = LitSchema::PROPRIUMDESANCTIS;
                break;
            case 'resourceDataCheck':
                if (
                    preg_match("/\/missals\/[_A-Z0-9]+$/", $dataPath)
                ) {
                    $schema = LitSchema::PROPRIUMDESANCTIS;
                } elseif (
                    preg_match("/\/data\/(nation|diocese|widerregion)/", $dataPath)
                ) {
                    $schema = LitSchema::DATA;
                } else {
                    $schema = Health::DATA_PATH_TO_SCHEMA[ $dataPath ];
                }
                break;
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
                $message->text = "There was an error decoding the Data file $dataPath as JSON: " . json_last_error_msg();
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

    private function validateCalendar(string $Calendar, int $Year, string $category, string $responseType, ConnectionInterface $to): void
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
        if ($Calendar === 'VATICAN') {
            $req = "/$Year?calendartype=CIVIL";
        } else {
            switch ($category) {
                case 'nationalcalendar':
                    $req = "/nation/$Calendar/$Year?calendartype=CIVIL";
                    break;
                case 'diocesancalendar':
                    $req = "/diocese/$Calendar/$Year?calendartype=CIVIL";
                    break;
                default:
                    //we shouldn't ever get any other categories
            }
        }
        $data = file_get_contents(self::REQPATH . $req, false, $context);
        if ($data !== false) {
            $message = new \stdClass();
            $message->type = "success";
            $message->text = "The $category of $Calendar for the year $Year exists";
            $message->classes = ".calendar-$Calendar.file-exists.year-$Year";
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
                        $message->text = "There was an error decoding the $category of $Calendar for the year $Year from the URL "
                                        . self::REQPATH . $req . " as XML: " . $errorString;
                        $message->classes = ".calendar-$Calendar.json-valid.year-$Year";
                        $message->responsetype = $responseType;
                        $this->sendMessage($to, $message);
                    } else {
                        $message = new \stdClass();
                        $message->type = "success";
                        $message->text = "The $category of $Calendar for the year $Year was successfully decoded as XML";
                        $message->classes = ".calendar-$Calendar.json-valid.year-$Year";
                        $this->sendMessage($to, $message);

                        $validationResult = $xml->schemaValidate(API_BASE_PATH . '/schemas/LiturgicalCalendar.xsd');
                        if ($validationResult) {
                            $message = new \stdClass();
                            $message->type = "success";
                            $message->text = sprintf(
                                "The $category of $Calendar for the year $Year was successfully validated against the Schema %s",
                                API_BASE_PATH . "/schemas/LiturgicalCalendar.xsd"
                            );
                            $message->classes = ".calendar-$Calendar.schema-valid.year-$Year";
                            $this->sendMessage($to, $message);
                        } else {
                            $errors = libxml_get_errors();
                            $errorString = self::retrieveXmlErrors($errors, $xmlArr);
                            libxml_clear_errors();
                            $message = new \stdClass();
                            $message->type = "error";
                            $message->text = $errorString;
                            $message->classes = ".calendar-$Calendar.schema-valid.year-$Year";
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
                        $message->text = "The $category of $Calendar for the year $Year was successfully decoded as ICS";
                        $message->classes = ".calendar-$Calendar.json-valid.year-$Year";
                        $this->sendMessage($to, $message);

                        $result = $vcalendar->validate();
                        if (count($result) === 0) {
                            $message = new \stdClass();
                            $message->type = "success";
                            $message->text = sprintf(
                                "The $category of $Calendar for the year $Year was successfully validated according the iCalendar Schema %s",
                                "https://tools.ietf.org/html/rfc5545"
                            );
                            $message->classes = ".calendar-$Calendar.schema-valid.year-$Year";
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
                            $message->classes = ".calendar-$Calendar.schema-valid.year-$Year";
                            $this->sendMessage($to, $message);
                        }
                    } else {
                        $message = new \stdClass();
                        $message->type = "error";
                        $message->text = "There was an error decoding the $category of $Calendar for the year $Year from the URL "
                                        . self::REQPATH . $req . " as ICS: parsing resulted in type " . gettype($vcalendar) . " | " . $vcalendar;
                        $message->classes = ".calendar-$Calendar.json-valid.year-$Year";
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
                            $message->text = "The $category of $Calendar for the year $Year was successfully decoded as YAML";
                            $message->classes = ".calendar-$Calendar.json-valid.year-$Year";
                            $this->sendMessage($to, $message);

                            $validationResult = $this->validateDataAgainstSchema($yamlData, LitSchema::LITCAL);
                            if (gettype($validationResult) === 'boolean' && $validationResult === true) {
                                $message = new \stdClass();
                                $message->type = "success";
                                $message->text = "The $category of $Calendar for the year $Year was successfully validated against the Schema " . LitSchema::LITCAL;
                                $message->classes = ".calendar-$Calendar.schema-valid.year-$Year";
                                $this->sendMessage($to, $message);
                            } elseif (gettype($validationResult === 'object')) {
                                $validationResult->classes = ".calendar-$Calendar.schema-valid.year-$Year";
                                $this->sendMessage($to, $validationResult);
                            }
                        }
                    } catch (\Exception $ex) {
                        $message = new \stdClass();
                        $message->type = "error";
                        $message->text = "There was an error decoding the $category of $Calendar for the year $Year from the URL "
                                        . self::REQPATH . $req . " as YAML: " . $ex->getMessage();
                        $message->classes = ".calendar-$Calendar.json-valid.year-$Year";
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
                        $message->text = "The $category of $Calendar for the year $Year was successfully decoded as JSON";
                        $message->classes = ".calendar-$Calendar.json-valid.year-$Year";
                        $this->sendMessage($to, $message);

                        $validationResult = $this->validateDataAgainstSchema($jsonData, LitSchema::LITCAL);
                        if (gettype($validationResult) === 'boolean' && $validationResult === true) {
                            $message = new \stdClass();
                            $message->type = "success";
                            $message->text = "The $category of $Calendar for the year $Year was successfully validated against the Schema " . LitSchema::LITCAL;
                            $message->classes = ".calendar-$Calendar.schema-valid.year-$Year";
                            $this->sendMessage($to, $message);
                        } elseif (gettype($validationResult === 'object')) {
                            $validationResult->classes = ".calendar-$Calendar.schema-valid.year-$Year";
                            $this->sendMessage($to, $validationResult);
                        }
                    } else {
                        $message = new \stdClass();
                        $message->type = "error";
                        $message->text = "There was an error decoding the $category of $Calendar for the year $Year from the URL "
                                        . self::REQPATH . $req . " as JSON: " . json_last_error_msg();
                        $message->classes = ".calendar-$Calendar.json-valid.year-$Year";
                        $message->responsetype = $responseType;
                        $this->sendMessage($to, $message);
                    }
            }
        } else {
            $message = new \stdClass();
            $message->type = "error";
            $message->text = "The $category of $Calendar for the year $Year does not exist at the URL " . self::REQPATH . $req;
            $message->classes = ".calendar-$Calendar.file-exists.year-$Year";
            $this->sendMessage($to, $message);
        }
    }

    private function executeUnitTest(string $Test, string $Calendar, int $Year, string $category, ConnectionInterface $to): void
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
        if ($Calendar === 'VATICAN') {
            $req = "/$Year?calendartype=CIVIL";
        } else {
            switch ($category) {
                case 'nationalcalendar':
                    $req = "/nation/$Calendar/$Year?calendartype=CIVIL";
                    break;
                case 'diocesancalendar':
                    $req = "/diocese/$Calendar/$Year?calendartype=CIVIL";
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
            $UnitTest = new LitTest($Test, $jsonData);
            if ($UnitTest->isReady()) {
                $UnitTest->runTest();
            }
            $this->sendMessage($to, $UnitTest->getMessage());
        }
    }

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
