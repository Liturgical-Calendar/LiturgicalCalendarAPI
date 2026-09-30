<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Tests\Support\PinsRouterPathsTrait;
use LiturgicalCalendar\Tests\Support\ShadowProjectRootTrait;
use PHPUnit\Framework\TestCase;

final class HealthWiderRegionMembershipTest extends TestCase
{
    use PinsRouterPathsTrait;
    use ShadowProjectRootTrait;

    public static function setUpBeforeClass(): void
    {
        self::pinRouterPaths();
    }

    public static function tearDownAfterClass(): void
    {
        self::restoreRouterPaths();
    }

    public function testTheShippedDataHasNoDrift(): void
    {
        $block = Health::buildWiderRegionMembershipStatus();

        self::assertSame('ok', $block['status']);
        self::assertSame([], $block['drift']);
    }

    public function testANationDeclaringAnUnknownOrUnlistingRegionIsReported(): void
    {
        $root = self::createShadowProjectRoot(dirname(__DIR__) . DIRECTORY_SEPARATOR, 'litcal-health-membership');
        try {
            $file = $root . '/jsondata/sourcedata/rite/roman/calendars/nations/IT/IT.json';
            $it   = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($it);
            $it['metadata']['wider_regions'] = ['europe', 'americas', 'atlantis'];
            file_put_contents($file, json_encode($it, JSON_THROW_ON_ERROR));

            $block = Health::buildWiderRegionMembershipStatus($root . DIRECTORY_SEPARATOR);

            self::assertSame('warning', $block['status']);
            self::assertCount(2, $block['drift']['IT']);
            self::assertStringContainsString('americas', $block['drift']['IT'][0]);
            self::assertStringContainsString('atlantis', $block['drift']['IT'][1]);
        } finally {
            self::removeTree($root);
        }
    }
}
