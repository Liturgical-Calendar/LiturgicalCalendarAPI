<?php

namespace Johnrdorazio\LitCal\Params;

use Johnrdorazio\LitCal\Paths\RegionalData;
use Johnrdorazio\LitCal\Enum\Route;
use Johnrdorazio\LitCal\Enum\StatusCode;
use Johnrdorazio\LitCal\Enum\LitLocale;
use Johnrdorazio\LitCal\Enum\RequestMethod;

class RegionalDataParams
{
    private ?object $calendars = null;
    public const array EXPECTED_CATEGORIES = [
        "nation"      => "NATIONALCALENDAR",
        "diocese"     => "DIOCESANCALENDAR",
        "widerregion" => "WIDERREGIONCALENDAR"
    ];
    public ?string $category = null;
    public ?string $key = null;
    public ?string $locale = null;
    public ?object $payload = null;

    public function __construct()
    {
        $calendarsRoute = (defined('API_BASE_PATH') ? API_BASE_PATH : 'https://litcal.johnromanodorazio.com/api/dev') . Route::CALENDARS->value;
        $metadataRaw = file_get_contents($calendarsRoute);
        if ($metadataRaw) {
            $metadata = json_decode($metadataRaw);
            if (JSON_ERROR_NONE === json_last_error() && property_exists($metadata, 'LitCalMetadata')) {
                $this->calendars = $metadata->LitCalMetadata;
                unset($this->calendars->NationalCalendars->VATICAN);
            }
        }
    }

    public function setData(object $data): bool
    {
        if (false === property_exists($data, 'category') || false === property_exists($data, 'key')) {
            RegionalData::produceErrorResponse(
                StatusCode::BAD_REQUEST,
                "Expected params `category` and `key` but either one or both not present"
            );
        }
        if (false === in_array($data->category, self::EXPECTED_CATEGORIES)) {
            RegionalData::produceErrorResponse(
                StatusCode::BAD_REQUEST,
                "Unexpected value '{$data->category}' for param `category`, acceptable values are: " . implode(', ', array_values(self::EXPECTED_CATEGORIES))
            );
        } else {
            $this->category = $data->category;
            switch ($data->category) {
                case 'NATIONALCALENDAR':
                    if (
                        false === property_exists($this->calendars->NationalCalendars, $data->key)
                        && RegionalData::$APICore->getRequestMethod() !== RequestMethod::PUT
                    ) {
                        $nationalCalendarsArr = array_keys(get_object_vars($this->calendars->NationalCalendars));
                        $validVals = implode(', ', $nationalCalendarsArr);
                        RegionalData::produceErrorResponse(StatusCode::BAD_REQUEST, "Invalid value {$data->key} for param `key`, valid values are: {$validVals}");
                    } else {
                        $this->key = $data->key;
                    }
                    // Check the request method: cannot DELETE National calendar data if it is still in use by a Diocesan calendar
                    if (RegionalData::$APICore->getRequestMethod() === RequestMethod::DELETE) {
                        foreach ($this->calendars->DiocesanCalendars as $key => $value) {
                            if ($value->nation === $data->key) {
                                RegionalData::produceErrorResponse(StatusCode::BAD_REQUEST, "Cannot DELETE National Calendar data while there are Diocesan calendars that depend on it. Currently, {$data->key} is in use by Diocesan calendar {$key}");
                            }
                        }
                    }
                    break;
                case 'DIOCESANCALENDAR':
                    if (
                        false === property_exists($this->calendars->DiocesanCalendars, $data->key)
                        && RegionalData::$APICore->getRequestMethod() !== RequestMethod::PUT
                    ) {
                        $validVals = implode(', ', array_keys(get_object_vars($this->calendars->DiocesanCalendars)));
                        RegionalData::produceErrorResponse(StatusCode::BAD_REQUEST, "Invalid value {$data->key} for param `key`, valid values are: {$validVals}");
                    } else {
                        $this->key = $data->key;
                    }
                    break;
                case 'WIDERREGIONCALENDAR':
                    if (
                        false === in_array($data->key, $this->calendars->WiderRegions)
                        && RegionalData::$APICore->getRequestMethod() !== RequestMethod::PUT
                    ) {
                        $validVals = implode(', ', $this->calendars->WiderRegions);
                        RegionalData::produceErrorResponse(StatusCode::BAD_REQUEST, "Invalid value {$data->key} for param `key`, valid values are: {$validVals}");
                    } else {
                        $this->key = $data->key;
                    }
                    // A locale parameter is required for WiderRegion data, whether supplied by the Accept-Language header or by a `locale` parameter
                    if (property_exists($data, 'locale')) {
                        $data->locale = \Locale::canonicalize($data->locale);
                        if (LitLocale::isValid($data->locale)) {
                            $this->locale = $data->locale;
                        } else {
                            RegionalData::produceErrorResponse(StatusCode::BAD_REQUEST, "Invalid value {$data->locale} for param `locale`");
                        }
                    } elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
                        $value = \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);
                        if (LitLocale::isValid($value)) {
                            $this->locale = $value;
                        } else {
                            RegionalData::produceErrorResponse(StatusCode::BAD_REQUEST, "Invalid value {$value} for Accept-Language header");
                        }
                    } else {
                        RegionalData::produceErrorResponse(StatusCode::BAD_REQUEST, "`locale` param or `Accept-Language` header required for Wider Region calendar data");
                    }
                    // Check the request method: cannot DELETE Wider Region calendar data if there are national calendars that depend on it
                    if (RegionalData::$APICore->getRequestMethod() === RequestMethod::DELETE) {
                        foreach ($this->calendars->NationalCalendarsMetadata as $key => $value) {
                            if (in_array($data->key, $value->widerRegions)) {
                                RegionalData::produceErrorResponse(StatusCode::BAD_REQUEST, "Cannot DELETE Wider Region calendar data while there are National calendars that depend on it. Currently {$data->key} is in use by {$key}");
                            }
                        }
                    }
                    break;
                default:
                    //nothing to do
            }
        }
        if (in_array(RegionalData::$APICore->getRequestMethod(), [RequestMethod::PUT,RequestMethod::PATCH])) {
            if (false === property_exists($data, 'payload') || false === $data->payload instanceof \stdClass) {
                RegionalData::produceErrorResponse(StatusCode::BAD_REQUEST, "Cannot create or update Calendar data without a payload");
            }
            switch ($this->category) {
                case 'NATIONALCALENDAR':
                    if (
                        false === property_exists($data->payload, 'LitCal')
                        || false === property_exists($data->payload, 'Metadata')
                        || false === property_exists($data->payload, 'Settings')
                    ) {
                        $message = "Cannot create or update National calendar data when the payload does not have required properties `LitCal`, `Metadata` or `Settings`. Payload was:\n" . json_encode($data->payload, JSON_PRETTY_PRINT);
                        RegionalData::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
                    }
                    break;
                case 'DIOCESANCALENDAR':
                    if (
                        false === property_exists($data->payload, 'CalData')
                        || false === property_exists($data->payload, 'Diocese')
                        || false === property_exists($data->payload, 'Nation')
                    ) {
                        $message = "Cannot create or update Diocesan calendar data when the payload does not have required properties `CalData`, `Diocese` or `Nation`. Payload was:\n" . json_encode($data->payload, JSON_PRETTY_PRINT);
                        RegionalData::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
                    }
                    break;
                case 'WIDERREGIONCALENDAR':
                    if (
                        false === property_exists($data->payload, 'LitCal')
                        || false === property_exists($data->payload, 'Metadata')
                        || false === property_exists($data->payload, 'NationalCalendars')
                        || false === property_exists($data->payload->Metadata, 'WiderRegion')
                        || false === property_exists($data->payload->Metadata, 'IsMultilingual')
                    ) {
                        $message = "Cannot create or update Wider Region calendar data when the payload does not have required properties `LitCal`, `NationalCalendars`, `Metadata`, `Metadata->WiderRegion`, `Metadata->IsMultilingual`. Payload was:\n" . json_encode($data->payload, JSON_PRETTY_PRINT);
                        RegionalData::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
                    }
                    if (
                        true === $data->payload->Metadata->IsMultilingual
                        && false === property_exists($data->payload->Metadata, 'Languages')
                    ) {
                        $message = "Cannot create or update Wider Region calendar data when the payload has value `true` for `Metadata->IsMultilingual` but does not have required array `Metadata->Languages`. Payload was:\n" . json_encode($data->payload, JSON_PRETTY_PRINT);
                        RegionalData::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
                    }
                    break;
            }
            $this->payload = $data->payload;
        }
        return true;
    }
}
