<?php

namespace Johnrdorazio\LitCal\Params;

use Johnrdorazio\LitCal\Enum\LitLocale;
use Johnrdorazio\LitCal\Enum\StatusCode;
use Johnrdorazio\LitCal\Paths\Decrees;

class DecreesParams
{
    public ?string $Locale = null;

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
                            $error = "Invalid value `$value` for param `locale`, valid values are: "
                                . implode(', ', LitLocale::$AllAvailableLocales);
                            Decrees::produceErrorResponse(StatusCode::BAD_REQUEST, $error);
                        }
                        break;
                }
            }
        }
    }
}
