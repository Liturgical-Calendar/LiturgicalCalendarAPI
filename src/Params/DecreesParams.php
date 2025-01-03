<?php

namespace LiturgicalCalendar\Api\Params;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\StatusCode;
use LiturgicalCalendar\Api\Paths\Decrees;

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
                switch ($key) {
                    case 'locale':
                        $value = \Locale::canonicalize($value);
                        if (LitLocale::isValid($value)) {
                            $this->Locale = $value;
                        } else {
                            $error = "Invalid value `$value` for param `locale`, valid values are: la, la_VA, "
                                . implode(', ', LitLocale::$AllAvailableLocales);
                            Decrees::produceErrorResponse(StatusCode::BAD_REQUEST, $error);
                        }
                        break;
                }
            }
        }
    }
}
