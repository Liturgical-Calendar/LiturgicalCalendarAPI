<?php

include_once( 'vendor/autoload.php' );
include_once( 'includes/enums/LitSchema.php' );

$testsFolder = dirname(__FILE__) . '/tests';
$it = new DirectoryIterator("glob://$testsFolder/*Test.php");
foreach($it as $f) {
    $fileName = $f->getFilename();
    include_once( 'tests/' . $fileName );
}

use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;
use Sabre\VObject;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Sabre\VObject\InvalidDataException;

class LitCalHealth implements MessageComponentInterface {
    protected $clients;

    public function onOpen(ConnectionInterface $conn) {
        // Store the new connection to send messages to later
        $this->clients->attach($conn);

        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        echo sprintf('Receiving message "%s" from connection %d', $msg, $from->resourceId);
        $messageReceived = json_decode( $msg );
        if( json_last_error() === JSON_ERROR_NONE ) {
            if( property_exists( $messageReceived, 'action' ) ) {
                switch( $messageReceived->action ) {
                    case 'executeValidation':
                        if(
                            property_exists( $messageReceived, 'validate' ) &&
                            property_exists( $messageReceived, 'sourceFile' ) &&
                            property_exists( $messageReceived, 'category' )
                        ) {
                            $this->executeValidation( $messageReceived, $from );
                        }
                        break;
                    case 'validateCalendar':
                        if(
                            property_exists( $messageReceived, 'calendar' ) &&
                            property_exists( $messageReceived, 'year' ) &&
                            property_exists( $messageReceived, 'category' ) &&
                            property_exists( $messageReceived, 'responsetype' )
                        ) {
                            $this->validateCalendar( $messageReceived->calendar, $messageReceived->year, $messageReceived->category, $messageReceived->responsetype, $from );
                        }
                        break;
                    case 'executeUnitTest':
                        if(
                            property_exists( $messageReceived, 'calendar' ) &&
                            property_exists( $messageReceived, 'year' ) &&
                            property_exists( $messageReceived, 'category' ) &&
                            property_exists( $messageReceived, 'test' )
                        ) {
                            $this->executeUnitTest( $messageReceived->test, $messageReceived->calendar, $messageReceived->year, $messageReceived->category, $from );
                        }
                        break;
                    default:
                        $message = new stdClass();
                        $message->type = "echobot";
                        $message->text = $msg;
                        $this->sendMessage( $from, $message );
                }
            }
        }

    }

    public function onClose(ConnectionInterface $conn) {
        // The connection is closed, remove it, as we can no longer send it messages
        $this->clients->detach($conn);

        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }

    private function sendMessage( ConnectionInterface $from, string|stdClass $msg ) {
        if( gettype( $msg ) !== 'string' ) {
            $msg = json_encode( $msg );
        }
        foreach ($this->clients as $client) {
            if ($from === $client) {
                // The message from sender will be echoed back only to the sender, not to other clients
                $client->send($msg);
            }
        }
    }

    const DataPathToSchema = [
        "https://litcal.johnromanodorazio.com/api/dev/LitCalMetadata.php"       => LitSchema::METADATA,
        "data/propriumdetempore.json"                                           => LitSchema::PROPRIUMDETEMPORE,
        "data/propriumdesanctis_1970/propriumdesanctis_1970.json"               => LitSchema::PROPRIUMDESANCTIS,
        "data/propriumdesanctis_2002/propriumdesanctis_2002.json"               => LitSchema::PROPRIUMDESANCTIS,
        "data/propriumdesanctis_2008/propriumdesanctis_2008.json"               => LitSchema::PROPRIUMDESANCTIS,
        "data/memorialsFromDecrees/memorialsFromDecrees.json"                   => LitSchema::DECREEMEMORIALS,
        "nations/index.json"                                                    => LitSchema::INDEX
    ];

    const LitCalBaseUrl = "https://litcal.johnromanodorazio.com/api/dev/LitCalEngine.php";

    public function __construct() {
        $this->clients = new \SplObjectStorage;
    }

    private function executeValidation( object $validation, ConnectionInterface $to ) {
        $dataPath = $validation->sourceFile;
        switch( $validation->category ) {
            case 'universalcalendar':
                $schema = LitCalHealth::DataPathToSchema[ $dataPath ];
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
        }
        $data = file_get_contents( $dataPath );
        if( $data !== false ) {
            $message = new stdClass();
            $message->type = "success";
            $message->text = "The Data file $dataPath exists";
            $message->classes = ".$validation->validate.file-exists";
            $this->sendMessage( $to, $message );

            $jsonData = json_decode( $data );
            if( json_last_error() === JSON_ERROR_NONE ) {
                $message = new stdClass();
                $message->type = "success";
                $message->text = "The Data file $dataPath was successfully decoded as JSON";
                $message->classes = ".$validation->validate.json-valid";
                $this->sendMessage( $to, $message );

                $validationResult = $this->validateDataAgainstSchema( $jsonData, $schema );
                if( gettype( $validationResult ) === 'boolean' && $validationResult === true ) {
                    $message = new stdClass();
                    $message->type = "success";
                    $message->text = "The Data file $dataPath was successfully validated against the Schema $schema";
                    $message->classes = ".$validation->validate.schema-valid";
                    $this->sendMessage( $to, $message );
                }
                else if( gettype( $validationResult === 'object' ) ) {
                    $validationResult->classes = ".$validation->validate.schema-valid";
                    $this->sendMessage( $to, $validationResult );
                }
            } else {
                $message = new stdClass();
                $message->type = "error";
                $message->text = "There was an error decoding the Data file $dataPath as JSON: " . json_last_error_msg();
                $message->classes = ".$validation->validate.json-valid";
                $this->sendMessage( $to, $message );
            }

        } else {
            $message = new stdClass();
            $message->type = "error";
            $message->text = "Data file $dataPath does not exist";
            $message->classes = ".$validation->validate.file-exists";
            $this->sendMessage( $to, $message );
        }
    }

    private static function retrieve_xml_errors( array $errors, array $xml ) : string {
        $return = [];
        foreach( $errors as $error ) {
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
            $errorStr .= htmlspecialchars(trim($error->message)) . " (Line: $error->line, Column: $error->column, Src: " . htmlspecialchars(trim($xml[$error->line - 1])) . ")";
            if ($error->file) {
                $errorStr .= " in file: $error->file";
            }
            array_push($return, $errorStr);
        }
        return implode('&#013;', $return);
    }

    private function validateCalendar( string $Calendar, int $Year, string $category, string $responseType, ConnectionInterface $to ) : void {
        if( $Calendar === 'VATICAN' ) {
            $req = "?nationalcalendar=VATICAN&year=$Year&calendartype=CIVIL&returntype=$responseType";
        } else {
            $req = "?$category=$Calendar&year=$Year&calendartype=CIVIL&returntype=$responseType";
        }
        $data = file_get_contents( self::LitCalBaseUrl . $req );
        if( $data !== false ) {
            $message = new stdClass();
            $message->type = "success";
            $message->text = "The $category of $Calendar for the year $Year exists";
            $message->classes = ".calendar-$Calendar.file-exists.year-$Year";
            $this->sendMessage( $to, $message );

            switch( $responseType ) {
                case "XML":
                    libxml_use_internal_errors(true);
                    $xml = new DOMDocument();
                    $xml->loadXML( $data );
                    //$xml = simplexml_load_string( $data );
                    $xmlArr = explode("\n", $data);
                    if ($xml === false) {
                        $message = new stdClass();
                        $message->type = "error";
                        $errors = libxml_get_errors();
                        $errorString = self::retrieve_xml_errors( $errors, $xmlArr );
                        libxml_clear_errors();
                        $message->text = "There was an error decoding the $category of $Calendar for the year $Year from the URL " . self::LitCalBaseUrl . $req . " as XML: " . $errorString;
                        $message->classes = ".calendar-$Calendar.json-valid.year-$Year";
                        $this->sendMessage( $to, $message );
                    } else {
                        $message = new stdClass();
                        $message->type = "success";
                        $message->text = "The $category of $Calendar for the year $Year was successfully decoded as XML";
                        $message->classes = ".calendar-$Calendar.json-valid.year-$Year";
                        $this->sendMessage( $to, $message );

                        $validationResult = $xml->schemaValidate('https://litcal.johnromanodorazio.com/api/dev/schemas/LiturgicalCalendar.xsd');
                        if( $validationResult ) {
                            $message = new stdClass();
                            $message->type = "success";
                            $message->text = "The $category of $Calendar for the year $Year was successfully validated against the Schema https://litcal.johnromanodorazio.com/api/dev/schemas/LiturgicalCalendar.xsd";
                            $message->classes = ".calendar-$Calendar.schema-valid.year-$Year";
                            $this->sendMessage( $to, $message );
                        } else {
                            $errors = libxml_get_errors();
                            $errorString = self::retrieve_xml_errors( $errors, $xmlArr );
                            libxml_clear_errors();
                            $message = new stdClass();
                            $message->type = "error";
                            $message->text = $errorString;
                            $message->classes = ".calendar-$Calendar.schema-valid.year-$Year";
                            $this->sendMessage( $to, $message );
                        }
                    }
                break;
                case "ICS":
                    try {
                        $vcalendar = VObject\Reader::read( $data );
                    } catch (InvalidDataException $ex) {
                        $vcalendar = json_encode( $ex );
                    }
                    if( $vcalendar instanceof VObject\Document ) {
                        $message = new stdClass();
                        $message->type = "success";
                        $message->text = "The $category of $Calendar for the year $Year was successfully decoded as ICS";
                        $message->classes = ".calendar-$Calendar.json-valid.year-$Year";
                        $this->sendMessage( $to, $message );

                        $result = $vcalendar->validate();
                        if( count($result) === 0 ) {
                            $message = new stdClass();
                            $message->type = "success";
                            $message->text = "The $category of $Calendar for the year $Year was successfully validated according the iCalendar Schema https://tools.ietf.org/html/rfc5545";
                            $message->classes = ".calendar-$Calendar.schema-valid.year-$Year";
                            $this->sendMessage( $to, $message );
                        } else {
                            $message = new stdClass();
                            $message->type = "error";
                            $errorStrings = [];
                            foreach( $result as $error ) {
                                $errorLevel = new ICSErrorLevel( $error['level'] );
                                $errorStrings[] = $errorLevel . ": " . $error['message'] . " ::: " . $error['node']->getValue();
                            }
                            $message->text = implode('&#013;', $errorStrings ) || "validation encountered " . count( $result ) . " errors";
                            $message->classes = ".calendar-$Calendar.schema-valid.year-$Year";
                            $this->sendMessage( $to, $message );
                        }
                    } else {
                        $message = new stdClass();
                        $message->type = "error";
                        $message->text = "There was an error decoding the $category of $Calendar for the year $Year from the URL " . self::LitCalBaseUrl . $req . " as ICS: parsing resulted in type " .gettype( $vcalendar ) . " | " . $vcalendar;
                        $message->classes = ".calendar-$Calendar.json-valid.year-$Year";
                        $this->sendMessage( $to, $message );
                    }
                    break;
                case "JSON":
                default:
                    $jsonData = json_decode( $data );
                    if( json_last_error() === JSON_ERROR_NONE ) {
                        $message = new stdClass();
                        $message->type = "success";
                        $message->text = "The $category of $Calendar for the year $Year was successfully decoded as JSON";
                        $message->classes = ".calendar-$Calendar.json-valid.year-$Year";
                        $this->sendMessage( $to, $message );
        
                        $validationResult = $this->validateDataAgainstSchema( $jsonData, LitSchema::LITCAL );
                        if( gettype( $validationResult ) === 'boolean' && $validationResult === true ) {
                            $message = new stdClass();
                            $message->type = "success";
                            $message->text = "The $category of $Calendar for the year $Year was successfully validated against the Schema " . LitSchema::LITCAL;
                            $message->classes = ".calendar-$Calendar.schema-valid.year-$Year";
                            $this->sendMessage( $to, $message );
                        }
                        else if( gettype( $validationResult === 'object' ) ) {
                            $validationResult->classes = ".calendar-$Calendar.schema-valid.year-$Year";
                            $this->sendMessage( $to, $validationResult );
                        }
                    } else {
                        $message = new stdClass();
                        $message->type = "error";
                        $message->text = "There was an error decoding the $category of $Calendar for the year $Year from the URL " . self::LitCalBaseUrl . $req . " as JSON: " . json_last_error_msg();
                        $message->classes = ".calendar-$Calendar.json-valid.year-$Year";
                        $this->sendMessage( $to, $message );
                    }
            }
        } else {
            $message = new stdClass();
            $message->type = "error";
            $message->text = "The $category of $Calendar for the year $Year does not exist at the URL " . self::LitCalBaseUrl . $req;
            $message->classes = ".calendar-$Calendar.file-exists.year-$Year";
            $this->sendMessage( $to, $message );
        }
    }

    private function executeUnitTest( string $Test, string $Calendar, int $Year, string $category, ConnectionInterface $to ) : void {
        if( $Calendar === 'VATICAN' ) {
            $req = "?nationalcalendar=VATICAN&year=$Year&calendartype=CIVIL";
        } else {
            $req = "?$category=$Calendar&year=$Year&calendartype=CIVIL";
        }
        $jsonData = json_decode( file_get_contents( self::LitCalBaseUrl . $req ) );
        if( json_last_error() === JSON_ERROR_NONE ) {
            $TestClass = new $Test( $Test );
            $TestClass::$testObject = $jsonData;
            $testResult = $TestClass->test();
            if( gettype( $testResult ) === 'boolean' && $testResult === true ) {
                $message = new stdClass();
                $message->type = "success";
                $message->text = "$Test passed for the Calendar $Calendar for the year $Year";
                $message->classes = ".$Test.year-{$Year}.test-valid";
                $message->test = $Test;
                $this->sendMessage( $to, $message );
            }
            else if( gettype( $testResult ) === 'object' ) {
                $testResult->classes = ".$Test.year-{$Year}.test-valid";
                $testResult->test = $Test;
                $testResult->jsonData = $jsonData;
                $this->sendMessage( $to, $testResult );
            }
        }
    }

    private function validateDataAgainstSchema( object|array $data, string $schemaUrl ) : bool|object {
        $res = false;
        try {
            $schema = Schema::import( $schemaUrl );
            $schema->in($data);
            $res = true;
        } catch (InvalidValue|Exception $e) {
            $message = new stdClass();
            $message->type = "error";
            $message->text = LitSchema::ERROR_MESSAGES[ $schemaUrl ] . PHP_EOL . $e->getMessage();
            return $message;
        }
        return $res;
    }

}

class ICSErrorLevel {
    const int REPAIRED = 1;
    const int WARNING = 2;
    const int FATAL = 3;
    const ERROR_STRING = [
        null,
        'Repaired value',
        'Warning',
        'Fatal Error'
    ];
    private string $errorString;

    public function __construct( int $errorLevel ) {
        $this->errorString = static::ERROR_STRING[ $errorLevel ];
    }

    public function __toString() {
        return $this->errorString;
    }
}
