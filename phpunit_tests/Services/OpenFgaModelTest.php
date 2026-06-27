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

    public function testNoTypeDefinesDeleter(): void
    {
        $model = $this->loadModel();
        foreach ($model['type_definitions'] as $def) {
            if (!isset($def['relations'])) {
                continue;
            }
            self::assertArrayNotHasKey('deleter', $def['relations'], "{$def['type']} still defines deleter");
            self::assertArrayNotHasKey(
                'deleter',
                $def['metadata']['relations'] ?? [],
                "{$def['type']} still has deleter metadata"
            );
        }
    }

    public function testEditorAndViewerAreUnionsOfAdmin(): void
    {
        $model = $this->loadModel();
        $types = array_column($model['type_definitions'], 'relations', 'type');
        foreach (['national_calendar', 'diocesan_calendar', 'wider_region', 'general_roman_calendar'] as $t) {
            $editorChildren = $types[$t]['editor']['union']['child'];
            self::assertContains(['this' => []], $editorChildren, "$t editor missing this");
            self::assertContains(['computedUserset' => ['relation' => 'admin']], $editorChildren, "$t editor missing admin");

            $viewerChildren = $types[$t]['viewer']['union']['child'];
            self::assertContains(['computedUserset' => ['relation' => 'editor']], $viewerChildren, "$t viewer missing editor");
            self::assertContains(['computedUserset' => ['relation' => 'admin']], $viewerChildren, "$t viewer missing admin");
        }
    }

    public function testWiderRegionHasMemberNationTtu(): void
    {
        $model = $this->loadModel();
        $types = array_column($model['type_definitions'], 'relations', 'type');
        $meta  = array_column($model['type_definitions'], 'metadata', 'type');

        self::assertArrayHasKey('member_nation', $types['wider_region']);
        self::assertSame(
            [['type' => 'national_calendar']],
            $meta['wider_region']['relations']['member_nation']['directly_related_user_types']
        );

        $adminChildren = $types['wider_region']['admin']['union']['child'];
        self::assertContains([
            'tupleToUserset' => [
                'tupleset'        => ['relation' => 'member_nation'],
                'computedUserset' => ['relation' => 'admin'],
            ],
        ], $adminChildren, 'wider_region admin missing member_nation TTU');
    }
}
