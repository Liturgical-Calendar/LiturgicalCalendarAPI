<?php

class LitSchema {

    const INDEX             = "https://litcal.org/api/dev/schemas/Index.json";
    const DIOCESAN          = "https://litcal.org/api/dev/schemas/DiocesanCalendar.json";
    const NATIONAL          = "https://litcal.org/api/dev/schemas/NationalCalendar.json";
    const PROPRIUMDESANCTIS = "https://litcal.org/api/dev/schemas/PropriumDeSanctis.json";
    const PROPRIUMDETEMPORE = "https://litcal.org/api/dev/schemas/PropriumDeTempore.json";
    const WIDERREGION       = "https://litcal.org/api/dev/schemas/WiderRegionCalendar.json";
    const DECREEMEMORIALS   = "https://litcal.org/api/dev/schemas/MemorialsFromDecrees.json";
    const I18N              = "https://litcal.org/api/dev/schemas/LitCalTranslation.json";
    const METADATA          = "https://litcal.org/api/dev/schemas/LitCalMetadata.json";

    const ERROR_MESSAGES = [
        LitSchema::INDEX             => "Schema validation error: Index not updated",
        LitSchema::DIOCESAN          => "Schema validation error: Diocesan Calendar not created / updated",
        LitSchema::NATIONAL          => "Schema validation error: National Calendar not created / updated",
        LitSchema::PROPRIUMDESANCTIS => "Schema validation error: Proprium de Sanctis data not created / updated",
        LitSchema::PROPRIUMDETEMPORE => "Schema validation error: Proprium de Tempore data not created / updated",
        LitSchema::WIDERREGION       => "Schema validation error: Wider Region data not created / updated",
        LitSchema::DECREEMEMORIALS   => "Schema validation error: Memorials from Decrees data not created / updated",
        LitSchema::I18N              => "Schema validation error: Translation data not created / updated",
        LitSchema::METADATA          => "Schema validation error: LitCalMetadata not valid"
    ];

}
