<?php

namespace Johnrdorazio\LitCal\Enum;

class LitSchema
{
    private const SCHEMA_BASE_PATH = (defined('API_BASE_PATH') ? API_BASE_PATH : 'https://litcal.johnromanodorazio.com/api/dev') . Route::SCHEMAS->value;
    public const INDEX             = self::SCHEMA_BASE_PATH . "/Index.json";
    public const DIOCESAN          = self::SCHEMA_BASE_PATH . "/DiocesanCalendar.json";
    public const NATIONAL          = self::SCHEMA_BASE_PATH . "/NationalCalendar.json";
    public const PROPRIUMDESANCTIS = self::SCHEMA_BASE_PATH . "/PropriumDeSanctis.json";
    public const PROPRIUMDETEMPORE = self::SCHEMA_BASE_PATH . "/PropriumDeTempore.json";
    public const WIDERREGION       = self::SCHEMA_BASE_PATH . "/WiderRegionCalendar.json";
    public const DECREEMEMORIALS   = self::SCHEMA_BASE_PATH . "/MemorialsFromDecrees.json";
    public const I18N              = self::SCHEMA_BASE_PATH . "/LitCalTranslation.json";
    public const METADATA          = self::SCHEMA_BASE_PATH . "/LitCalMetadata.json";
    public const LITCAL            = self::SCHEMA_BASE_PATH . "/LitCal.json";

    public const ERROR_MESSAGES = [
        LitSchema::INDEX             => "Schema validation error: Index not updated",
        LitSchema::DIOCESAN          => "Schema validation error: Diocesan Calendar not created / updated",
        LitSchema::NATIONAL          => "Schema validation error: National Calendar not created / updated",
        LitSchema::PROPRIUMDESANCTIS => "Schema validation error: Proprium de Sanctis data not created / updated",
        LitSchema::PROPRIUMDETEMPORE => "Schema validation error: Proprium de Tempore data not created / updated",
        LitSchema::WIDERREGION       => "Schema validation error: Wider Region data not created / updated",
        LitSchema::DECREEMEMORIALS   => "Schema validation error: Memorials from Decrees data not created / updated",
        LitSchema::I18N              => "Schema validation error: Translation data not created / updated",
        LitSchema::METADATA          => "Schema validation error: LitCalMetadata not valid",
        LitSchema::LITCAL            => "Schema validation error: LitCal not valid"
    ];
}
