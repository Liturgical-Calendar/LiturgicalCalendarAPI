<?php

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\LitMassVariousNeeds;
use PHPUnit\Framework\TestCase;

class LitMassVariousNeedsTest extends TestCase
{
    public function testLatinKeysMatchCasesNames()
    {
        $cases = array_map(fn($case) => $case->name, LitMassVariousNeeds::cases());
        $this->assertEquals(array_keys(LitMassVariousNeeds::LATIN), $cases);
    }
}
