<?php

namespace Johnrdorazio\LitCal\Params;

use Johnrdorazio\LitCal\Enum\LitLocale;
use Johnrdorazio\LitCal\Enum\StatusCode;
use Johnrdorazio\LitCal\Paths\Missal;

class MissalParams
{
    public ?string $Locale = null;
    public ?string $Year   = null;

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
                            $value === \Locale::canonicalize($value);
                        }
                        if (LitLocale::isValid($value)) {
                            $this->Locale = $value;
                        } else {
                            Missal::produceErrorResponse(StatusCode::BAD_REQUEST, "Invalid value `$value` for param `locale`");
                        }
                        break;
                    case 'YEAR':
                        $this->enforceYearValidity($value);
                        break;
                }
            }
        }
    }

    private function enforceYearValidity(string $value)
    {
        if (is_numeric($value) && ctype_digit($value) && strlen($value) === 4) {
            $this->Year = $value;
        } else {
            $description = 'Year parameter is of type String, but is not a numeric String with 4 digits';
            Missal::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }
}
