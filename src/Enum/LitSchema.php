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
    case I18N              = '/LitCalTranslation.json';
    case METADATA          = '/LitCalMetadata.json';
    case LITCAL            = '/LitCal.json';
    case EVENTS            = '/LitCalEventsPath.json';
    case TESTS             = '/LitCalTestsPath.json';
    case TEST_SRC          = '/LitCalTest.json';
    case MISSALS           = '/LitCalMissalsPath.json';
    case EASTER            = '/LitCalEasterPath.json';
    case DATA              = '/LitCalDataPath.json';
    case SCHEMAS           = '/LitCalSchemasPath.json';

    public function path(): string
    {
        return JsonData::SCHEMAS_FOLDER->path() . $this->value;
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
            LitSchema::I18N              => $ERRMSG . 'Translation data not created / updated',
            LitSchema::METADATA => $ERRMSG . 'LitCalMetadata not valid',
            LitSchema::LITCAL   => $ERRMSG . 'LitCal not valid',
            LitSchema::EVENTS   => $ERRMSG . 'Events path data not valid',
            LitSchema::TESTS    => $ERRMSG . 'Tests path data not valid',
            LitSchema::TEST_SRC => $ERRMSG . 'Test data not valid',
            LitSchema::MISSALS  => $ERRMSG . 'Missals path data not valid',
            LitSchema::EASTER   => $ERRMSG . 'Easter path data not valid',
            LitSchema::DATA     => $ERRMSG . 'Data path data not valid',
            LitSchema::SCHEMAS  => $ERRMSG . 'Schemas path data not valid'
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
            LitSchema::I18N->path()              => LitSchema::I18N,
            LitSchema::METADATA->path()          => LitSchema::METADATA,
            LitSchema::LITCAL->path()            => LitSchema::LITCAL,
            LitSchema::EVENTS->path()            => LitSchema::EVENTS,
            LitSchema::TESTS->path()             => LitSchema::TESTS,
            LitSchema::TEST_SRC->path()          => LitSchema::TEST_SRC,
            LitSchema::MISSALS->path()           => LitSchema::MISSALS,
            LitSchema::EASTER->path()            => LitSchema::EASTER,
            LitSchema::DATA->path()              => LitSchema::DATA,
            LitSchema::SCHEMAS->path()           => LitSchema::SCHEMAS,
            default                              => throw new ValidationException('Invalid schema URL: ' . $url)
        };
    }
}
