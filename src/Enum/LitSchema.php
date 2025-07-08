<?php

namespace LiturgicalCalendar\Api\Enum;

class LitSchema
{
    private const SCHEMA_BASE_PATH = API_BASE_PATH . '/jsondata/schemas';
    public const DIOCESAN          = self::SCHEMA_BASE_PATH . '/DiocesanCalendar.json';
    public const NATIONAL          = self::SCHEMA_BASE_PATH . '/NationalCalendar.json';
    public const PROPRIUMDESANCTIS = self::SCHEMA_BASE_PATH . '/PropriumDeSanctis.json';
    public const PROPRIUMDETEMPORE = self::SCHEMA_BASE_PATH . '/PropriumDeTempore.json';
    public const WIDERREGION       = self::SCHEMA_BASE_PATH . '/WiderRegionCalendar.json';
    public const DECREES           = self::SCHEMA_BASE_PATH . '/LitCalDecreesPath.json';
    public const DECREES_SRC       = self::SCHEMA_BASE_PATH . '/LitCalDecreesSource.json';
    public const I18N              = self::SCHEMA_BASE_PATH . '/LitCalTranslation.json';
    public const METADATA          = self::SCHEMA_BASE_PATH . '/LitCalMetadata.json';
    public const LITCAL            = self::SCHEMA_BASE_PATH . '/LitCal.json';
    public const EVENTS            = self::SCHEMA_BASE_PATH . '/LitCalEventsPath.json';
    public const TESTS             = self::SCHEMA_BASE_PATH . '/LitCalTestsPath.json';
    public const TEST_SRC          = self::SCHEMA_BASE_PATH . '/LitCalTest.json';
    public const MISSALS           = self::SCHEMA_BASE_PATH . '/LitCalMissalsPath.json';
    public const EASTER            = self::SCHEMA_BASE_PATH . '/LitCalEasterPath.json';
    public const DATA              = self::SCHEMA_BASE_PATH . '/LitCalDataPath.json';
    public const SCHEMAS           = self::SCHEMA_BASE_PATH . '/LitCalSchemasPath.json';

    private const ERRMSG        = 'Schema validation error: ';
    public const ERROR_MESSAGES = [
        LitSchema::DIOCESAN          => self::ERRMSG . 'Diocesan Calendar not created / updated',
        LitSchema::NATIONAL          => self::ERRMSG . 'National Calendar not created / updated',
        LitSchema::PROPRIUMDESANCTIS => self::ERRMSG . 'Proprium de Sanctis data not created / updated',
        LitSchema::PROPRIUMDETEMPORE => self::ERRMSG . 'Proprium de Tempore data not created / updated',
        LitSchema::WIDERREGION       => self::ERRMSG . 'Wider Region data not created / updated',
        LitSchema::DECREES           => self::ERRMSG . 'Memorials from Decrees data not created / updated',
        LitSchema::DECREES_SRC       => self::ERRMSG . 'Memorials from Decrees Source data not created / updated',
        LitSchema::I18N              => self::ERRMSG . 'Translation data not created / updated',
        LitSchema::METADATA          => self::ERRMSG . 'LitCalMetadata not valid',
        LitSchema::LITCAL            => self::ERRMSG . 'LitCal not valid',
        LitSchema::EVENTS            => self::ERRMSG . 'Events path data not valid',
        LitSchema::TESTS             => self::ERRMSG . 'Tests path data not valid',
        LitSchema::TEST_SRC          => self::ERRMSG . 'Test data not valid',
        LitSchema::MISSALS           => self::ERRMSG . 'Missals path data not valid',
        LitSchema::EASTER            => self::ERRMSG . 'Easter path data not valid',
        LitSchema::DATA              => self::ERRMSG . 'Data path data not valid',
        LitSchema::SCHEMAS           => self::ERRMSG . 'Schemas path data not valid'
    ];
}
