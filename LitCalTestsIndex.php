<?php
include_once( 'vendor/autoload.php' );
include_once( 'includes/enums/RequestContentType.php' );

use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;

error_reporting(E_ALL);
ini_set("display_errors", 1);



class STATUS_CODE {
    const UNPROCESSABLE_CONTENT = 422;
    const SERVICE_UNAVAILABLE   = 503;
    const METHOD_NOT_ALLOWED    = 405;
    const UNSUPPORTED_MEDIA_TYPE= 415;
}

class LitCalTestsIndex {
    private static array $acceptedRequestMethods = [ 'GET', 'PUT' ];
    private static array $propsToSanitize = [
        "description",
        "appliesTo",
        "excludes",
        "assertions",
        "nationalcalendar",
        "diocesancalendar",
        "nationalcalendars",
        "diocesancalendars",
        "assertion",
        "comment"
    ];

    const STATUS_CODES = [
        STATUS_CODE::METHOD_NOT_ALLOWED     => " 405 Method Not Allowed",
        STATUS_CODE::UNSUPPORTED_MEDIA_TYPE => " 415 Unsupported Media Type",
        STATUS_CODE::UNPROCESSABLE_CONTENT  => " 422 Unprocessable Content",
        STATUS_CODE::SERVICE_UNAVAILABLE    => " 503 Service Unavailable"
    ];

    private static function sanitizeString( string $str ): string {
        return htmlspecialchars( strip_tags( $str ) );
    }

    private static function sanitizeObjectValues( object &$data ): void {
        foreach( $data as $prop => $value ) {
            if( in_array( $prop, self::$propsToSanitize ) ) {
                if( is_object( $value ) ) {
                    self::sanitizeObjectValues( $data[ $prop ] );
                } else {
                    if( is_array( $value ) ) {
                        foreach( $data[ $prop ] as $idx => $item ) {
                            $data[ $prop ][ $idx ] = self::sanitizeString( $item );
                        }
                    }
                    else if ( is_string( $value ) ) {
                        $data[ $prop ] = self::sanitizeString( $data[ $prop ] );
                    }
                }
            }
        }
    }

    private static function handleGetRequest(): string|false {
        $testSuite = [];

        $testsFolder = dirname(__FILE__) . '/tests';
        $it = new DirectoryIterator("glob://$testsFolder/*Test.json");
        foreach($it as $f) {
            $fileName       = $f->getFilename();
            $testContents   = file_get_contents( 'tests/' . $fileName );
            $testSuite[]    = json_decode( $testContents, true );
        }
        return json_encode( $testSuite, JSON_PRETTY_PRINT );
    }

    private static function produceErrorResponse( int $statusCode, string $description ): string {
        header( $_SERVER[ "SERVER_PROTOCOL" ] . self::STATUS_CODES[ $statusCode ], true, $statusCode );
        $message = new stdClass;
        $message->status = "ERROR";
        $message->response = "Resource not Created";
        $message->description = $description;
        return json_encode( $message );
}

    private static function handlePutRequest(): string|false {
        if( $_SERVER[ 'CONTENT_TYPE' ] !== RequestContentType::JSON ) {
            return self::produceErrorResponse( STATUS_CODE::UNSUPPORTED_MEDIA_TYPE, "This endpoint can only handle PUT requests with Content Type application/json, instead the request had a Content Type of " . $_SERVER[ 'CONTENT_TYPE' ] );
        } else {
            $json = file_get_contents( 'php://input' );
            $data = json_decode( $json );

            if( json_last_error() !== JSON_ERROR_NONE ) {
                return self::produceErrorResponse( STATUS_CODE::UNPROCESSABLE_CONTENT, "The Unit Test you are attempting to create was not valid JSON" );
            }

            // Validate incoming data against unit test schema
            $schemaFile = dirname(__FILE__) . '/schemas/LitCalTest.json';
            $schemaContents = file_get_contents( $schemaFile );
            $jsonSchema = json_decode( $schemaContents );

            if( json_last_error() !== JSON_ERROR_NONE ) {
                return self::produceErrorResponse( STATUS_CODE::SERVICE_UNAVAILABLE, "The server errored out while attempting to process your request; this a server error, not an error in the request. Please report the incident to the system administrator." );
            }

            try {
                $result = [];
                $schema = Schema::import( $jsonSchema );
                $schema->in($data);
                $message = new stdClass();
                $message->status = "SUCCESS";
                $message->response = "Unit Test {$data->name} was correctly validated against schema " . $schemaFile;
                $result[] = $message;
            } catch (InvalidValue|Exception $e) {
                return self::produceErrorResponse( STATUS_CODE::UNPROCESSABLE_CONTENT, "The Unit Test you are attempting to create was incorrectly validated against schema " . $schemaFile . ": " . $e->getMessage() );
            }

            // Sanitize data to avoid any possibility of script injection
            self::sanitizeObjectValues( $data );

            $bytesWritten = file_put_contents( 'tests/' . $data->name . '.json', $json );
            if( false === $bytesWritten ) {
                return self::produceErrorResponse( STATUS_CODE::SERVICE_UNAVAILABLE, "The server did not succeed in writing to disk the Unit Test. Please try again later or contact the service administrator for support." );
            } else {
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 201 Created", true, 201 );
                $message = new stdClass;
                $message->status = "OK";
                $message->response = "Resource Created";
                return json_encode( $message );
            }

        }
    }

    public static function handleRequest(): string|false {
        if( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
            header( "Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}" );
            header( 'Access-Control-Allow-Credentials: true' );
        }
        else {
            header( 'Access-Control-Allow-Origin: *' );
        }
        header( 'Access-Control-Max-Age: 86400' );    // cache for 1 day
        header( 'Cache-Control: must-revalidate, max-age=259200' );
        header( 'Content-Type: application/json' );
        $response = '';
        if( in_array( $_SERVER['REQUEST_METHOD'], self::$acceptedRequestMethods ) ) {
            switch( $_SERVER['REQUEST_METHOD'] ) {
                case 'GET':
                    $response = self::handleGetRequest();
                    break;
                case 'PUT':
                    $response = self::handlePutRequest();
                    break;
                default:
                    $response = self::produceErrorResponse( STATUS_CODE::METHOD_NOT_ALLOWED, "This endpoint can only accept requests utilizing methods GET or PUT" );
            }
        } else {
            $response = self::produceErrorResponse( STATUS_CODE::METHOD_NOT_ALLOWED, "This endpoint can only accept requests utilizing methods GET or PUT" );
        }
        return $response;
    }

}

echo LitCalTestsIndex::handleRequest();
exit(0);
