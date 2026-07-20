<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeMap;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Utilities;
use PHPUnit\Framework\TestCase;

final class AmbrosianProprioDeTemporeDataTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // JsonData::path() concatenates Router::$apiFilePath; populate it.
        Router::getApiPaths();
    }

    /** @return array<string,string> */
    private function loadNames(string $locale): array
    {
        $file = strtr(JsonData::AMBROSIAN_TEMPORALE_I18N_FILE->path(), ['{locale}' => $locale]);
        /** @var array<string,string> $names */
        $names = Utilities::jsonFileToArray($file);
        return $names;
    }

    public function testDataFileLoadsIntoMapWithItalianNames(): void
    {
        $raw   = Utilities::jsonFileToObjectArray(JsonData::AMBROSIAN_TEMPORALE_FILE->path());
        $names = $this->loadNames('it');
        $map   = PropriumDeTemporeMap::fromObject($raw);
        $map->setNames($names);

        // Sentinel keys the engine depends on:
        foreach (['Advent1', 'Advent6', 'Circoncisione', 'Lent5', 'AshesMonday', 'SabatoTradSymb', 'DedicationDuomo', 'ChristKing', 'Pentecost'] as $key) {
            $this->assertTrue($map->offsetExists($key), "Missing temporal key: $key");
        }
    }

    public function testItalianAndLatinI18nCoverEveryDataKey(): void
    {
        $raw = Utilities::jsonFileToObjectArray(JsonData::AMBROSIAN_TEMPORALE_FILE->path());
        $it  = $this->loadNames('it');
        $la  = $this->loadNames('la');
        foreach ($raw as $event) {
            $key = $event->event_key;
            $this->assertArrayHasKey($key, $it, "it.json missing name for $key");
            $this->assertArrayHasKey($key, $la, "la.json missing name for $key");
        }
    }
}
