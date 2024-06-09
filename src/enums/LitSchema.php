<?php

namespace LitCal\enum;

class LitSchema
{
    public const INDEX             = "https://litcal.johnromanodorazio.com/api/dev/schemas/Index.json";
    public const DIOCESAN          = "https://litcal.johnromanodorazio.com/api/dev/schemas/DiocesanCalendar.json";
    public const NATIONAL          = "https://litcal.johnromanodorazio.com/api/dev/schemas/NationalCalendar.json";
    public const PROPRIUMDESANCTIS = "https://litcal.johnromanodorazio.com/api/dev/schemas/PropriumDeSanctis.json";
    public const PROPRIUMDETEMPORE = "https://litcal.johnromanodorazio.com/api/dev/schemas/PropriumDeTempore.json";
    public const WIDERREGION       = "https://litcal.johnromanodorazio.com/api/dev/schemas/WiderRegionCalendar.json";
    public const DECREEMEMORIALS   = "https://litcal.johnromanodorazio.com/api/dev/schemas/MemorialsFromDecrees.json";
    public const I18N              = "https://litcal.johnromanodorazio.com/api/dev/schemas/LitCalTranslation.json";
    public const METADATA          = "https://litcal.johnromanodorazio.com/api/dev/schemas/LitCalMetadata.json";
    public const LITCAL            = "https://litcal.johnromanodorazio.com/api/dev/schemas/LitCal.json";

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
