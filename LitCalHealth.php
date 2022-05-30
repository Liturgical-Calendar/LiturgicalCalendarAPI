<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

include_once( 'includes/enums/LitSchema.php' );
include_once( 'tests/NativityJohnBaptistTest.php' );
include_once( 'tests/StJaneFrancesDeChantalTest.php' );
include_once( 'vendor/autoload.php' );

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
                        if( property_exists( $messageReceived, 'validate' ) ) {
                            $this->executeValidation( $messageReceived->validate, $from );
                        }
                        break;
                    case 'validateCalendar':
                        if(
                            property_exists( $messageReceived, 'category' ) &&
                            property_exists( $messageReceived, 'year' ) &&
                            property_exists( $messageReceived, 'calendar' )
                        ) {
                            $this->validateCalendar( $messageReceived->calendar, $messageReceived->year, $messageReceived->category, $from );
                        }
                        break;
                    case 'executeUnitTest':
                        if(
                            property_exists( $messageReceived, 'category' ) &&
                            property_exists( $messageReceived, 'year' )
                        ) {
                            $this->executeUnitTest( $messageReceived->category, $messageReceived->year, $from );
                        }
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


    const SchemaValidation = [
        "LitCalMetadata"                => "https://litcal.johnromanodorazio.com/api/dev/LitCalMetadata.php",
        "PropriumDeTempore"             => "data/propriumdetempore.json",
        "PropriumDeSanctis1970"         => "data/propriumdesanctis_1970/propriumdesanctis_1970.json",
        "PropriumDeSanctis2002"         => "data/propriumdesanctis_2002/propriumdesanctis_2002.json",
        "PropriumDeSanctis2008"         => "data/propriumdesanctis_2008/propriumdesanctis_2008.json",
        "PropriumDeSanctisITALY1983"    => "data/propriumdesanctis_ITALY_1983/propriumdesanctis_ITALY_1983.json",
        "PropriumDeSanctisUSA2011"      => "data/propriumdesanctis_USA_2011/propriumdesanctis_USA_2011.json",
        "MemorialsFromDecrees"          => "data/memorialsFromDecrees/memorialsFromDecrees.json",
        "RegionalCalendarsIndex"        => "nations/index.json"
    ];

    const DataPathToSchema = [
        "https://litcal.johnromanodorazio.com/api/dev/LitCalMetadata.php"       => LitSchema::METADATA,
        "data/propriumdetempore.json"                                           => LitSchema::PROPRIUMDETEMPORE,
        "data/propriumdesanctis_1970/propriumdesanctis_1970.json"               => LitSchema::PROPRIUMDESANCTIS,
        "data/propriumdesanctis_2002/propriumdesanctis_2002.json"               => LitSchema::PROPRIUMDESANCTIS,
        "data/propriumdesanctis_2008/propriumdesanctis_2008.json"               => LitSchema::PROPRIUMDESANCTIS,
        "data/propriumdesanctis_ITALY_1983/propriumdesanctis_ITALY_1983.json"   => LitSchema::PROPRIUMDESANCTIS,
        "data/propriumdesanctis_USA_2011/propriumdesanctis_USA_2011.json"       => LitSchema::PROPRIUMDESANCTIS,
        "data/memorialsFromDecrees/memorialsFromDecrees.json"                   => LitSchema::DECREEMEMORIALS,
        "nations/index.json"                                                    => LitSchema::INDEX
    ];

    const LitCalBaseUrl = "https://litcal.johnromanodorazio.com/api/dev/LitCalEngine.php";

    private object $CalendarData;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
    }

    private function executeValidation( string $validate, ConnectionInterface $to ) {
        if( array_key_exists( $validate, LitCalHealth::SchemaValidation ) ) {
            $dataPath = LitCalHealth::SchemaValidation[ $validate ];
            $schema = LitCalHealth::DataPathToSchema[ $dataPath ];
            $data = file_get_contents( $dataPath );
            if( $data !== false ) {
                $message = new stdClass();
                $message->type = "success";
                $message->text = "The Data file $dataPath exists";
                $message->classes = ".$validate.file-exists";
                $this->sendMessage( $to, $message );

                $jsonData = json_decode( $data );
                if( json_last_error() === JSON_ERROR_NONE ) {
                    $message = new stdClass();
                    $message->type = "success";
                    $message->text = "The Data file $dataPath was successfully decoded as JSON";
                    $message->classes = ".$validate.json-valid";
                    $this->sendMessage( $to, $message );

                    $validationResult = $this->validateDataAgainstSchema( $jsonData, $schema );
                    if( gettype( $validationResult ) === 'boolean' && $validationResult === true ) {
                        $message = new stdClass();
                        $message->type = "success";
                        $message->text = "The Data file $dataPath was successfully validated against the Schema $schema";
                        $message->classes = ".$validate.schema-valid";
                        $this->sendMessage( $to, $message );
                    }
                    else if( gettype( $validationResult === 'object' ) ) {
                        $validationResult->classes = ".$validate.schema-valid";
                        $this->sendMessage( $to, $validationResult );
                    }
                } else {
                    $message = new stdClass();
                    $message->type = "error";
                    $message->text = "There was an error decoding the Data file $dataPath as JSON: " . json_last_error_msg();
                    $message->classes = ".$validate.json-valid";
                    $this->sendMessage( $to, $message );
                }

            } else {
                $message = new stdClass();
                $message->type = "error";
                $message->text = "Data file $dataPath does not exist";
                $message->classes = ".$validate.file-exists";
                $this->sendMessage( $to, $message );
            }
        } else {
            $message = new stdClass();
            $message->type = "error";
            $message->text = "The validation requested \"{$validate}\" does not seem to be a supported validation";
            $this->sendMessage( $to, $message );
        }
    }

    private function validateCalendar( string $Calendar, int $Year, string $type, ConnectionInterface $to ) : void {
        if( $Calendar === 'VATICAN' ) {
            $req = "?year=$Year";
        } else {
            $req = "?$type=$Calendar&year=$Year";
        }
        $data = file_get_contents( self::LitCalBaseUrl . $req );
        if( $data !== false ) {
            $message = new stdClass();
            $message->type = "success";
            $message->text = "The $type of $Calendar for the year $Year exists";
            $message->classes = ".calendar-$Calendar.file-exists.year-$Year";
            $this->sendMessage( $to, $message );

            $jsonData = json_decode( $data );
            if( json_last_error() === JSON_ERROR_NONE ) {
                $message = new stdClass();
                $message->type = "success";
                $message->text = "The $type of $Calendar for the year $Year was successfully decoded as JSON";
                $message->classes = ".calendar-$Calendar.json-valid.year-$Year";
                $this->sendMessage( $to, $message );

                $validationResult = $this->validateDataAgainstSchema( $jsonData, LitSchema::LITCAL );
                if( gettype( $validationResult ) === 'boolean' && $validationResult === true ) {
                    $message = new stdClass();
                    $message->type = "success";
                    $message->text = "The $type of $Calendar for the year $Year was successfully validated against the Schema " . LitSchema::LITCAL;
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
                $message->text = "There was an error decoding the $type of $Calendar for the year $Year from the URL " . self::LitCalBaseUrl . $req . " as JSON: " . json_last_error_msg();
                $message->classes = ".calendar-$Calendar.json-valid.year-$Year";
                $this->sendMessage( $to, $message );
            }
        } else {
            $message = new stdClass();
            $message->type = "error";
            $message->text = "The $type of $Calendar for the year $Year does not exist at the URL " . self::LitCalBaseUrl . $req;
            $message->classes = ".calendar-$Calendar.file-exists.year-$Year";
            $this->sendMessage( $to, $message );
        }
    }

    private function executeUnitTest( string $test, int $year, ConnectionInterface $to ) {
        switch( $test ) {
            case 'testJohnBaptist':
                $this->testJohnBaptist( $year, $to );
                break;
            case 'testStJaneFrancesDeChantalMoved':
                $this->testStJaneFrancesDeChantalMoved( $year, $to, 'StJaneFrancesDeChantalMoved' );
                break;
            case 'testStJaneFrancesDeChantalOverridden':
                $this->testStJaneFrancesDeChantalMoved( $year, $to, 'StJaneFrancesDeChantalOverridden' );
                break;
        }
    }

    private function testJohnBaptist( int $Year, ConnectionInterface $to ) : void {
        $req = "?year=$Year";
        $data = file_get_contents( self::LitCalBaseUrl . $req );
        if( $data !== false ) {
            $message = new stdClass();
            $message->type = "success";
            $message->text = "The Universal Calendar for the year $Year exists";
            $message->classes = ".nativityjohnbaptist.year-{$Year}.file-exists";
            $this->sendMessage( $to, $message );

            $jsonData = json_decode( $data );
            if( json_last_error() === JSON_ERROR_NONE ) {
                $message = new stdClass();
                $message->type = "success";
                $message->text = "The Universal Calendar for the year $Year was successfully decoded as JSON";
                $message->classes = ".nativityjohnbaptist.year-{$Year}.json-valid";
                $this->sendMessage( $to, $message );

                $validationResult = $this->validateDataAgainstSchema( $jsonData, LitSchema::LITCAL );
                if( gettype( $validationResult ) === 'boolean' && $validationResult === true ) {
                    $message = new stdClass();
                    $message->type = "success";
                    $message->text = "The Universal Calendar for the year $Year was successfully validated against the Schema " . LitSchema::LITCAL;
                    $message->classes = ".nativityjohnbaptist.year-{$Year}.schema-valid";
                    $this->sendMessage( $to, $message );
                    NativityJohnBaptistTest::$testObject = $jsonData;
                    $NativityJohnBaptistTest = new NativityJohnBaptistTest;
                    $testResult = $NativityJohnBaptistTest->testJune23();
                    if( gettype( $testResult ) === 'boolean' && $testResult === true ) {
                        $message = new stdClass();
                        $message->type = "success";
                        $message->text = "Nativity of John the Baptist test passed for the Universal Calendar for the year $Year";
                        $message->classes = ".nativityjohnbaptist.year-{$Year}.test-valid";
                        $this->sendMessage( $to, $message );
                    }
                    else if( gettype( $testResult ) === 'object' ) {
                        $testResult->classes = ".nativityjohnbaptist.year-{$Year}.test-valid";
                        $this->sendMessage( $to, $testResult );
                    }
                }
                else if( gettype( $validationResult === 'object' ) ) {
                    $validationResult->classes = ".nativityjohnbaptist.year-{$Year}.schema-valid";
                    $this->sendMessage( $to, $validationResult );
                }
            } else {
                $message = new stdClass();
                $message->type = "error";
                $message->text = "There was an error decoding the Universal Calendar for the year $Year from the URL " . self::LitCalBaseUrl . $req . " as JSON: " . json_last_error_msg();
                $message->classes = ".nativityjohnbaptist.year-{$Year}.json-valid";
                $this->sendMessage( $to, $message );
            }
        } else {
            $message = new stdClass();
            $message->type = "error";
            $message->text = "The Universal Calendar for the year $Year does not exist at the URL " . self::LitCalBaseUrl . $req;
            $message->classes = ".nativityjohnbaptist.year-{$Year}.file-exists";
            $this->sendMessage( $to, $message );
        }
    }

    private function testStJaneFrancesDeChantalMoved( int $Year, ConnectionInterface $to, string $test ) : void {
        $req = "?year=$Year";
        $data = file_get_contents( self::LitCalBaseUrl . $req );
        if( $data !== false ) {
            $message = new stdClass();
            $message->type = "success";
            $message->text = "The Universal Calendar for the year $Year exists";
            $message->classes = ".{$test}.year-{$Year}.file-exists";
            $this->sendMessage( $to, $message );

            $jsonData = json_decode( $data );
            if( json_last_error() === JSON_ERROR_NONE ) {
                $message = new stdClass();
                $message->type = "success";
                $message->text = "The Universal Calendar for the year $Year was successfully decoded as JSON";
                $message->classes = ".{$test}.year-{$Year}.json-valid";
                $this->sendMessage( $to, $message );

                $validationResult = $this->validateDataAgainstSchema( $jsonData, LitSchema::LITCAL );
                if( gettype( $validationResult ) === 'boolean' && $validationResult === true ) {
                    $message = new stdClass();
                    $message->type = "success";
                    $message->text = "The Universal Calendar for the year $Year was successfully validated against the Schema " . LitSchema::LITCAL;
                    $message->classes = ".{$test}.year-{$Year}.schema-valid";
                    $this->sendMessage( $to, $message );
                    StJaneFrancesDeChantalTest::$testObject = $jsonData;
                    $StJaneFrancesDeChantalTest = new StJaneFrancesDeChantalTest;
                    if( $test === 'StJaneFrancesDeChantalMoved' ) {
                        $testResult = $StJaneFrancesDeChantalTest->testMovedOrNot();
                    }
                    else if( $test === 'StJaneFrancesDeChantalOverridden' ) {
                        $testResult = $StJaneFrancesDeChantalTest->testOverridden();
                    }
                    if( gettype( $testResult ) === 'boolean' && $testResult === true ) {
                        $message = new stdClass();
                        $message->type = "success";
                        $message->text = "Saint Jane Frances de Chantal test ($test) passed for the Universal Calendar for the year $Year";
                        $message->classes = ".{$test}.year-{$Year}.test-valid";
                        $this->sendMessage( $to, $message );
                    }
                    else if( gettype( $testResult ) === 'object' ) {
                        $testResult->classes = ".{$test}.year-{$Year}.test-valid";
                        $this->sendMessage( $to, $testResult );
                    }
                }
                else if( gettype( $validationResult === 'object' ) ) {
                    $validationResult->classes = ".{$test}.year-{$Year}.schema-valid";
                    $this->sendMessage( $to, $validationResult );
                }
            } else {
                $message = new stdClass();
                $message->type = "error";
                $message->text = "There was an error decoding the Universal Calendar for the year $Year from the URL " . self::LitCalBaseUrl . $req . " as JSON: " . json_last_error_msg();
                $message->classes = ".{$test}.year-{$Year}.json-valid";
                $this->sendMessage( $to, $message );
            }
        } else {
            $message = new stdClass();
            $message->type = "error";
            $message->text = "The Universal Calendar for the year $Year does not exist at the URL " . self::LitCalBaseUrl . $req;
            $message->classes = ".{$test}.year-{$Year}.file-exists";
            $this->sendMessage( $to, $message );
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
