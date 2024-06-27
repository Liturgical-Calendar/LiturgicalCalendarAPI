<?php

namespace Johnrdorazio\LitCal\Params;

use Johnrdorazio\LitCal\Enum\LitLocale;
use Johnrdorazio\LitCal\Enum\StatusCode;
use Johnrdorazio\LitCal\Paths\Missals;

class MissalsParams
{
    public ?string $Year          = null;
    public ?string $Locale        = null;
    private ?string $baseLocale   = null;
    private array $availableLangs = [];

    public function __construct(array $DATA = [])
    {
        $this->setData($DATA);
    }

    public function setData(array $DATA = [])
    {
        if (count($DATA)) {
            foreach ($DATA as $key => $value) {
                $key = strtoupper($key);
                switch ($key) {
                    case 'LOCALE':
                        if ($value !== 'la' && $value !== 'LA') {
                            $value = \Locale::canonicalize($value);
                        }
                        if (LitLocale::isValid($value)) {
                            $this->Locale = $value;
                        } else {
                            $error = "Locale `$value` set in param `locale` is not supported by this server, supported locales are: "
                                . implode(', ', LitLocale::$AllAvailableLocales);
                            Missals::produceErrorResponse(StatusCode::BAD_REQUEST, $error);
                        }
                        $this->baseLocale = \Locale::getPrimaryLanguage($value);
                        if (false === in_array($this->baseLocale, $this->availableLangs)) {
                            Missals::produceErrorResponse(
                                StatusCode::BAD_REQUEST,
                                "Locale `$value` ({$this->baseLocale}) set in param `locale` is not a valid locale for the requested Missal, valid locales are: "
                                    . implode(', ', $this->availableLangs)
                            );
                        }
                        break;
                    case 'YEAR':
                        $this->enforceYearValidity($value);
                        break;
                    case 'REGION':
                        //TODO:
                        break;
                }
            }
        }
    }

    public function setAvailableLangs(array $langs)
    {
        $this->availableLangs = $langs;
    }

    private function enforceYearValidity(string $value)
    {
        if (is_numeric($value) && ctype_digit($value) && strlen($value) === 4) {
            $this->Year = $value;
        } else {
            $description = 'Year parameter is of type String, but is not a numeric String with 4 digits';
            Missals::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }
}
