<?php

namespace LiturgicalCalendar\Tests\Services;

use PHPUnit\Framework\TestCase;

class OpenFgaModelTest extends TestCase
{
    /** @return array<string, mixed> */
    private function loadModel(): array
    {
        $json = file_get_contents(__DIR__ . '/../../scripts/openfga-model.json');
        self::assertIsString($json);
        $model = json_decode($json, true);
        self::assertIsArray($model);
        return $model;
    }

    public function testGeneralRomanCalendarTypeExistsWithStandardRelations(): void
    {
        $model = $this->loadModel();
        $types = [];
        foreach ($model['type_definitions'] as $def) {
            $types[$def['type']] = $def;
        }

        self::assertArrayHasKey('general_roman_calendar', $types);
        $grc = $types['general_roman_calendar'];
        foreach (['admin', 'viewer', 'editor', 'deleter'] as $relation) {
            self::assertArrayHasKey($relation, $grc['relations']);
            self::assertSame(
                [['type' => 'user']],
                $grc['metadata']['relations'][$relation]['directly_related_user_types']
            );
        }
    }

    public function testScopedTestTypesPresentWithFourRelations(): void
    {
        $model = json_decode((string) file_get_contents(__DIR__ . '/../../scripts/openfga-model.json'), true);
        $types = array_column($model['type_definitions'], 'relations', 'type');
        foreach (['national_calendar_test', 'diocesan_calendar_test', 'general_roman_calendar_test'] as $t) {
            $this->assertArrayHasKey($t, $types, "missing type $t");
            $this->assertSame(['admin', 'viewer', 'editor', 'deleter'], array_keys($types[$t]));
        }
    }
}
