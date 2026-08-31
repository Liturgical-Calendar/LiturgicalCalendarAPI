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
    /**
     * `/lectionary` responses — the section index and a single event's readings (#942).
     *
     * OUTPUT, not SOURCE, and distinct from {@see self::LECTIONARY}, which validates one locale
     * file of the stored corpus. This schema validates the aggregation the route builds *over*
     * those files: which tier answered, which locales carry an entry, and which of those entries
     * are the empty-string placeholder.
     */
    case LECTIONARY_PATH = '/LitCalLectionaryPath.json';
    case METADATA        = '/LitCalMetadata.json';
    case LITCAL          = '/LitCal.json';
    case EVENTS          = '/LitCalEventsPath.json';
    case TESTS           = '/LitCalTestsPath.json';
    case TEST_SRC        = '/LitCalTest.json';
    case MISSALS         = '/LitCalMissalsPath.json';
    /**
     * `GET /missals/{missal_id}` — the sanctorale rows of one Missal (#941).
     *
     * Separate from {@see self::MISSALS}, which validates the `/missals` INDEX. openapi.json used
     * to document this route's 200 with the index's row shape, which the route does not emit.
     */
    case MISSAL_SANCTORALE = '/LitCalMissalSanctoralePath.json';
    /**
     * `GET /missals/{missal_id}/i18n` — every locale's sanctorale names for one Missal (#941).
     */
    case MISSAL_TRANSLATIONS = '/LitCalMissalTranslationsPath.json';
    case EASTER              = '/LitCalEasterPath.json';
    case DATA                = '/LitCalDataPath.json';
    case SCHEMAS             = '/LitCalSchemasPath.json';
    case VALIDATIONS         = '/LitCalValidationsPath.json';
    /**
     * `jsondata/supportedLocales.json` — the curated set of officially supported locales.
     *
     * SOURCE, not OUTPUT: these are bytes the repository stores and a change request writes,
     * not a response this API emits. The file sits beside `jsondata/sourcedata` rather than
     * inside it, which is a fact about where reference resources live, not about what the
     * schema validates — see {@see SchemaRole::SOURCE}.
     *
     * Deliberately NOT a `CheckableItem`. `/validations` enumerates the source corpus a
     * calendar is assembled from — calendars, missals, lectionary folders — and answers
     * "exists / parses / validates / covers" for each. This resource is a single top-level
     * file with no locale-folder shape to cover, and its real invariant is not its shape but
     * whether the locales it names actually have their data — which `composer lint:locales`
     * and `/health`'s `locale_readiness` block already assert, far more strongly than a
     * schema could.
     */
    case SUPPORTED_LOCALES = '/SupportedLocales.json';
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
            LitSchema::MISSAL_SANCTORALE   => $ERRMSG . 'Missal sanctorale path data not valid',
            LitSchema::MISSAL_TRANSLATIONS => $ERRMSG . 'Missal translations path data not valid',
            LitSchema::LECTIONARY_PATH     => $ERRMSG . 'Lectionary path data not valid',
            LitSchema::EASTER   => $ERRMSG . 'Easter path data not valid',
            LitSchema::DATA     => $ERRMSG . 'Data path data not valid',
            LitSchema::SCHEMAS  => $ERRMSG . 'Schemas path data not valid',
            LitSchema::VALIDATIONS => $ERRMSG . 'Validations path data not valid',
            LitSchema::SUPPORTED_LOCALES => $ERRMSG . 'Supported locales resource not created / updated',
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
            LitSchema::SUPPORTED_LOCALES,
            LitSchema::LECTIONARY        => SchemaRole::SOURCE,
            LitSchema::LITCAL,
            LitSchema::METADATA,
            LitSchema::EVENTS,
            LitSchema::TESTS,
            LitSchema::MISSALS,
            LitSchema::MISSAL_SANCTORALE,
            LitSchema::MISSAL_TRANSLATIONS,
            LitSchema::LECTIONARY_PATH,
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
            LitSchema::MISSAL_SANCTORALE->path()   => LitSchema::MISSAL_SANCTORALE,
            LitSchema::MISSAL_TRANSLATIONS->path() => LitSchema::MISSAL_TRANSLATIONS,
            LitSchema::LECTIONARY_PATH->path()     => LitSchema::LECTIONARY_PATH,
            LitSchema::EASTER->path()            => LitSchema::EASTER,
            LitSchema::DATA->path()              => LitSchema::DATA,
            LitSchema::SCHEMAS->path()           => LitSchema::SCHEMAS,
            LitSchema::VALIDATIONS->path()       => LitSchema::VALIDATIONS,
            LitSchema::SUPPORTED_LOCALES->path() => LitSchema::SUPPORTED_LOCALES,
            LitSchema::WEBSOCKET_MESSAGE->path() => LitSchema::WEBSOCKET_MESSAGE,
            LitSchema::WEBSOCKET_FRAME->path()   => LitSchema::WEBSOCKET_FRAME,
            default                              => throw new ValidationException('Invalid schema URL: ' . $url)
        };
    }
}
