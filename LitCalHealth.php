<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

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
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

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
                            property_exists( $messageReceived, 'category' )
                        ) {
                            $this->validateCalendar( $messageReceived->calendar, $messageReceived->year, $messageReceived->category, $from );
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

    private function validateCalendar( string $Calendar, int $Year, string $category, ConnectionInterface $to ) : void {
        if( $Calendar === 'VATICAN' ) {
            $req = "?year=$Year";
        } else {
            $req = "?$category=$Calendar&year=$Year";
        }
        $data = file_get_contents( self::LitCalBaseUrl . $req );
        if( $data !== false ) {
            $message = new stdClass();
            $message->type = "success";
            $message->text = "The $category of $Calendar for the year $Year exists";
            $message->classes = ".calendar-$Calendar.file-exists.year-$Year";
            $this->sendMessage( $to, $message );

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
            $req = "?year=$Year";
        } else {
            $req = "?$category=$Calendar&year=$Year";
        }
        $jsonData = json_decode( file_get_contents( self::LitCalBaseUrl . $req ) );
        if( json_last_error() === JSON_ERROR_NONE ) {
            $TestClass = new $Test;
            $TestClass::$testObject = $jsonData;
            $testResult = $TestClass->test();
            if( gettype( $testResult ) === 'boolean' && $testResult === true ) {
                $message = new stdClass();
                $message->type = "success";
                $message->text = "$Test passed for the Calendar $Calendar for the year $Year";
                $message->classes = ".$Test.year-{$Year}.test-valid";
                $this->sendMessage( $to, $message );
            }
            else if( gettype( $testResult ) === 'object' ) {
                $testResult->classes = ".$Test.year-{$Year}.test-valid";
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


$LitCalHealth = new LitCalHealth();
