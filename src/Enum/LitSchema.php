<?php

namespace LiturgicalCalendar\Api\Enum;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;

enum LitSchema: string
{
    case DIOCESAN          = '/DiocesanCalendar.json';
    case NATIONAL          = '/NationalCalendar.json';
    case PROPRIUMDESANCTIS = '/PropriumDeSanctis.json';
    case PROPRIUMDETEMPORE = '/PropriumDeTempore.json';
    case WIDERREGION       = '/WiderRegionCalendar.json';
    case DECREES           = '/LitCalDecreesPath.json';
    case DECREES_SRC       = '/LitCalDecreesSource.json';
    case DECREE_WRITE      = '/LitCalDecreeWritePayload.json';
    case I18N              = '/LitCalTranslation.json';
    case LECTIONARY        = '/Lectionary.json';
    case METADATA          = '/LitCalMetadata.json';
    case LITCAL            = '/LitCal.json';
    case EVENTS            = '/LitCalEventsPath.json';
    case TESTS             = '/LitCalTestsPath.json';
    case TEST_SRC          = '/LitCalTest.json';
    case MISSALS           = '/LitCalMissalsPath.json';
    case EASTER            = '/LitCalEasterPath.json';
    case DATA              = '/LitCalDataPath.json';
    case SCHEMAS           = '/LitCalSchemasPath.json';
    case VALIDATIONS       = '/LitCalValidationsPath.json';
    case WEBSOCKET_MESSAGE = '/WebSocketMessage.json';
    case WEBSOCKET_FRAME   = '/WebSocketFrame.json';

    public function path(): string
    {
        return JsonData::SCHEMAS_FOLDER->path() . $this->value;
    }

    /**
     * The schema's file name, e.g. `LitCal.json`, without the server's absolute
     * filesystem path. Use this (rather than {@see self::path()}) anywhere the schema
     * is named in text that reaches an unauthenticated client, such as a WebSocket
     * validation frame — {@see self::path()} would otherwise leak the deployment's
     * directory layout.
     */
    public function name(): string
    {
        return ltrim($this->value, '/');
    }

    public function error(): string
    {
        $ERRMSG = 'Schema validation error: ';
        return match ($this) {
            LitSchema::DIOCESAN          => $ERRMSG . 'Diocesan Calendar not created / updated',
            LitSchema::NATIONAL          => $ERRMSG . 'National Calendar not created / updated',
            LitSchema::PROPRIUMDESANCTIS => $ERRMSG . 'Proprium de Sanctis data not created / updated',
            LitSchema::PROPRIUMDETEMPORE => $ERRMSG . 'Proprium de Tempore data not created / updated',
            LitSchema::WIDERREGION       => $ERRMSG . 'Wider Region data not created / updated',
            LitSchema::DECREES           => $ERRMSG . 'Memorials from Decrees data not created / updated',
            LitSchema::DECREES_SRC       => $ERRMSG . 'Memorials from Decrees Source data not created / updated',
            LitSchema::DECREE_WRITE      => $ERRMSG . 'Decree write payload not valid',
            LitSchema::I18N              => $ERRMSG . 'Translation data not created / updated',
            LitSchema::LECTIONARY        => $ERRMSG . 'Lectionary data not created / updated',
            LitSchema::METADATA => $ERRMSG . 'LitCalMetadata not valid',
            LitSchema::LITCAL   => $ERRMSG . 'LitCal not valid',
            LitSchema::EVENTS   => $ERRMSG . 'Events path data not valid',
            LitSchema::TESTS    => $ERRMSG . 'Tests path data not valid',
            LitSchema::TEST_SRC => $ERRMSG . 'Test data not valid',
            LitSchema::MISSALS  => $ERRMSG . 'Missals path data not valid',
            LitSchema::EASTER   => $ERRMSG . 'Easter path data not valid',
            LitSchema::DATA     => $ERRMSG . 'Data path data not valid',
            LitSchema::SCHEMAS  => $ERRMSG . 'Schemas path data not valid',
            LitSchema::VALIDATIONS => $ERRMSG . 'Validations path data not valid',
            LitSchema::WEBSOCKET_MESSAGE => $ERRMSG . 'WebSocket message not valid',
            LitSchema::WEBSOCKET_FRAME   => $ERRMSG . 'WebSocket frame not valid'
        };
    }

    /**
     * What this schema is for — see {@see SchemaRole}.
     *
     * Exhaustive on purpose, with no default arm: a new schema must state its role or fail static
     * analysis. The alternative is the silent misclassification this enum exists to stop, which costs
     * either a source file validated against the shape of an API response, or an output schema loosened
     * to admit a source-only shape.
     */
    public function role(): SchemaRole
    {
        return match ($this) {
            LitSchema::DIOCESAN,
            LitSchema::NATIONAL,
            LitSchema::WIDERREGION,
            LitSchema::PROPRIUMDESANCTIS,
            LitSchema::PROPRIUMDETEMPORE,
            LitSchema::DECREES_SRC,
            LitSchema::I18N,
            LitSchema::TEST_SRC,
            LitSchema::LECTIONARY        => SchemaRole::SOURCE,
            LitSchema::LITCAL,
            LitSchema::METADATA,
            LitSchema::EVENTS,
            LitSchema::TESTS,
            LitSchema::MISSALS,
            LitSchema::EASTER,
            LitSchema::DATA,
            LitSchema::SCHEMAS,
            LitSchema::VALIDATIONS,
            LitSchema::DECREES           => SchemaRole::OUTPUT,
            LitSchema::DECREE_WRITE      => SchemaRole::PAYLOAD,
            LitSchema::WEBSOCKET_MESSAGE,
            LitSchema::WEBSOCKET_FRAME   => SchemaRole::PROTOCOL
        };
    }

    public static function fromURL(string $url): LitSchema
    {
        return match ($url) {
            LitSchema::DIOCESAN->path()          => LitSchema::DIOCESAN,
            LitSchema::NATIONAL->path()          => LitSchema::NATIONAL,
            LitSchema::PROPRIUMDESANCTIS->path() => LitSchema::PROPRIUMDESANCTIS,
            LitSchema::PROPRIUMDETEMPORE->path() => LitSchema::PROPRIUMDETEMPORE,
            LitSchema::WIDERREGION->path()       => LitSchema::WIDERREGION,
            LitSchema::DECREES->path()           => LitSchema::DECREES,
            LitSchema::DECREES_SRC->path()       => LitSchema::DECREES_SRC,
            LitSchema::DECREE_WRITE->path()      => LitSchema::DECREE_WRITE,
            LitSchema::I18N->path()              => LitSchema::I18N,
            LitSchema::LECTIONARY->path()        => LitSchema::LECTIONARY,
            LitSchema::METADATA->path()          => LitSchema::METADATA,
            LitSchema::LITCAL->path()            => LitSchema::LITCAL,
            LitSchema::EVENTS->path()            => LitSchema::EVENTS,
            LitSchema::TESTS->path()             => LitSchema::TESTS,
            LitSchema::TEST_SRC->path()          => LitSchema::TEST_SRC,
            LitSchema::MISSALS->path()           => LitSchema::MISSALS,
            LitSchema::EASTER->path()            => LitSchema::EASTER,
            LitSchema::DATA->path()              => LitSchema::DATA,
            LitSchema::SCHEMAS->path()           => LitSchema::SCHEMAS,
            LitSchema::VALIDATIONS->path()       => LitSchema::VALIDATIONS,
            LitSchema::WEBSOCKET_MESSAGE->path() => LitSchema::WEBSOCKET_MESSAGE,
            LitSchema::WEBSOCKET_FRAME->path()   => LitSchema::WEBSOCKET_FRAME,
            default                              => throw new ValidationException('Invalid schema URL: ' . $url)
        };
    }
}
