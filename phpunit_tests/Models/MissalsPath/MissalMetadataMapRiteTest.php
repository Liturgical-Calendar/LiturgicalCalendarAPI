<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\MissalsPath;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadataMap;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissalMetadataMap::class)]
final class MissalMetadataMapRiteTest extends TestCase
{
    private static string $savedApiPath;
    private static string $savedApiFilePath;

    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
        self::$savedApiPath     = Router::$apiPath;
        self::$savedApiFilePath = Router::$apiFilePath;
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiPath     = self::$savedApiPath;
        Router::$apiFilePath = self::$savedApiFilePath;
    }

    public function testTheRomanIndexIsUnchanged(): void
    {
        $map = new MissalMetadataMap(Rite::ROMAN);
        $map->buildIndex();

        $ids = $map->getMissalIDs();
        self::assertContains('EDITIO_TYPICA_1970', $ids);
        self::assertContains('US_2011', $ids);
        self::assertNotContains('EDITIO_TYPICA_2024', $ids, 'the Ambrosian edition must not leak into the Roman index');

        $metadata = $map->getMissalMetadata('EDITIO_TYPICA_1970');
        self::assertNotNull($metadata);
        self::assertSame('VA', $metadata->region);
        self::assertStringEndsWith('/missals/roman/EDITIO_TYPICA_1970', (string) $metadata->api_path);
    }

    /**
     * The Ambrosian folder is `propriumdesanctis_2024`, which the old, since-removed folder-name
     * regex read with region `VA` — the wrong region, and a collision with the Roman namespace,
     * after which RomanMissal::getName() threw and the endpoint 503'd (#953).
     *
     * The Ambrosian typical edition's id was later renamed to `EDITIO_TYPICA_2024` (#953 round 1),
     * which happens to be the exact string that old derivation produced — so the id assertion
     * below can no longer, by itself, tell a correctly-declared id from a reintroduced folder-name
     * regex. `region` still can: the old derivation defaulted to `VA`, MissalSource declares
     * `AMBROSIAN`, and that assertion is what actually pins this regression now.
     */
    public function testTheAmbrosianIndexUsesTheDeclaredIdNotTheFolderName(): void
    {
        $map = new MissalMetadataMap(Rite::AMBROSIAN);
        $map->buildIndex();

        self::assertSame(['EDITIO_TYPICA_2024'], $map->getMissalIDs());

        $metadata = $map->getMissalMetadata('EDITIO_TYPICA_2024');
        self::assertNotNull($metadata);
        self::assertSame('AMBROSIAN', $metadata->region);
        self::assertSame(2024, $metadata->year_published);
        self::assertSame(['it', 'la'], $metadata->locales);
        self::assertStringEndsWith('/missals/ambrosian/EDITIO_TYPICA_2024', (string) $metadata->api_path);
    }

    /**
     * The index is memoised in APCu under one key. Keyed per rite it must not be, or the first
     * rite to build serves the other for the whole 600-second TTL — a collision that survives the
     * request that caused it and so is unusually hard to attribute.
     */
    public function testTheTwoRiteIndexesDoNotShareACacheEntry(): void
    {
        $roman = new MissalMetadataMap(Rite::ROMAN);
        $roman->buildIndex();
        $ambrosian = new MissalMetadataMap(Rite::AMBROSIAN);
        $ambrosian->buildIndex();

        self::assertNotContains('EDITIO_TYPICA_2024', $roman->getMissalIDs());
        self::assertSame(['EDITIO_TYPICA_2024'], $ambrosian->getMissalIDs());

        // Rebuild Roman AFTER Ambrosian: a shared key would now serve the Ambrosian entry.
        $romanAgain = new MissalMetadataMap(Rite::ROMAN);
        $romanAgain->buildIndex();
        self::assertContains('EDITIO_TYPICA_1970', $romanAgain->getMissalIDs());
        self::assertNotContains('EDITIO_TYPICA_2024', $romanAgain->getMissalIDs());
    }
}
