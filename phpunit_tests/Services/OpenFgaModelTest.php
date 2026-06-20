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
}
