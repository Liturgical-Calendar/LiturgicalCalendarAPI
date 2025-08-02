<?php

namespace LiturgicalCalendar\Api\Models\Lectionary;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;

final class ReadingsCommons implements \JsonSerializable
{
    public LitCommons $litCommons;

    public function __construct(LitCommons $litCommons)
    {
        $this->litCommons = $litCommons;
    }

    public function jsonSerialize(): string
    {
        return $this->litCommons->fullTranslate(LitLocale::$PRIMARY_LANGUAGE);
    }
}
