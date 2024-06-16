<?php

namespace Johnrdorazio\LitCal\Params;

use Johnrdorazio\LitCal\Enum\LitLocale;

class MissalParams
{
    public ?string $locale = null;
    public ?string $nation = null;
    public ?int $year      = null;

    public function __construct(array $DATA = [])
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
                            $this->locale = $value;
                        } else {
                            //
                        }
                }
            }
        }
    }
}
