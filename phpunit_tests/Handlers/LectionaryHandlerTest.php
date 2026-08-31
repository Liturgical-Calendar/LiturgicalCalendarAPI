<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\LectionaryHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use Swaggest\JsonSchema\Schema;

/**
 * `GET /lectionary/{rite}/sanctorale[/{event_key}]` — issue #942.
 *
 * The four distinctions this route exists to make are asserted against the real corpus rather
 * than against literal `event_key` fixtures, and the fixtures are discovered by reading the
 * lectionary files directly with `json_decode` — an oracle independent of the handler's own code
 * path. That is deliberate on two counts: a hard-coded key is a hostage to renames happening in
 * parallel (issue #939 is renaming one right now), and a discovered fixture proves the property
 * holds of the data as it actually is rather than of the one row somebody remembered.
 */
#[CoversClass(LectionaryHandler::class)]
final class LectionaryHandlerTest extends AbstractHandlerTestCase
{
    /** @var array<string,array<string,mixed>>|null decoded rite-level sanctorum files, keyed by locale */
    private static ?array $riteFiles = null;

    /**
     * The rite-level sanctorum locale files, decoded straight off disk.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function riteFiles(): array
    {
        if (null === self::$riteFiles) {
            $folder = JsonData::LECTIONARY_SAINTS_FOLDER->path();
            $files  = glob($folder . '/*.json');
            self::assertIsArray($files);
            self::assertNotEmpty($files, 'the rite-level sanctorale lectionary folder is empty');
            sort($files);

            $decoded = [];
            foreach ($files as $file) {
                $raw = file_get_contents($file);
                self::assertIsString($raw);
                /** @var array<string,mixed> $map */
                $map                               = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $decoded[basename($file, '.json')] = $map;
            }
            self::$riteFiles = $decoded;
        }

        return self::$riteFiles;
    }

    /**
     * @param string[] $pathParams
     * @return array<string,mixed>
     */
    private function get(array $pathParams, Rite $rite = Rite::ROMAN): array
    {
        $uri      = '/lectionary/' . $rite->value . '/' . implode('/', $pathParams);
        $response = ( new LectionaryHandler($pathParams, $rite) )->handle($this->requestFor('GET', $uri));
        self::assertSame(200, $response->getStatusCode());
        return $this->decodeJsonBody($response);
    }

    // ------------------------------------------------------------------ HTTP shape

    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new LectionaryHandler(['sanctorale']) )->handle(
            $this->requestFor('OPTIONS', '/lectionary/sanctorale', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testPutIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new LectionaryHandler(['sanctorale']) )->handle(
            $this->requestFor('PUT', '/lectionary/sanctorale', [], ['first_reading' => 'nope'])
        );
    }

    public function testBarePathIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        ( new LectionaryHandler() )->handle($this->requestFor('GET', '/lectionary'));
    }

    public function testUnknownSectionIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        ( new LectionaryHandler(['temporale']) )->handle($this->requestFor('GET', '/lectionary/temporale'));
    }

    public function testTooManyPathParamsIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        ( new LectionaryHandler(['sanctorale', 'SomeEvent', 'extra']) )
            ->handle($this->requestFor('GET', '/lectionary/sanctorale/SomeEvent/extra'));
    }

    public function testUnknownEventKeyIsNotFoundUnderARiteThatHasALectionary(): void
    {
        // The contrast that makes the Ambrosian 200 below meaningful: under a rite that DOES have
        // a lectionary, an event nobody curated is a 404, not an "unavailable" body.
        $this->expectException(NotFoundException::class);
        ( new LectionaryHandler(['sanctorale', 'NotARealEventKeyAtAll'], Rite::ROMAN) )
            ->handle($this->requestFor('GET', '/lectionary/sanctorale/NotARealEventKeyAtAll'));
    }

    // ------------------------------------------------------- resolve both tiers, say which one

    public function testIndexNamesEveryTierThatCarriesReadings(): void
    {
        $body = $this->get(['sanctorale']);

        self::assertTrue($body['lectionary_available']);
        self::assertSame('roman', $body['rite']);
        self::assertSame('sanctorale', $body['section']);
        self::assertIsArray($body['sources']);

        $tiers = array_column($body['sources'], 'tier', 'source_id');
        self::assertSame('rite', $tiers['roman'] ?? null, 'the rite-level corpus must be reported as its own tier');
        self::assertContains('missal', $tiers, 'no per-missal lectionary was reported, but RomanMissal maps at least one');

        // Every source declares the locales it has files for, and how many keys it carries.
        foreach ($body['sources'] as $source) {
            self::assertNotEmpty($source['locales']);
            self::assertGreaterThan(0, $source['event_key_count']);
        }
    }

    public function testAnEventCarriedByBothTiersReportsBothSeparately(): void
    {
        $index = $this->get(['sanctorale']);

        // Discover an event_key the index says is carried by more than one source, rather than
        // naming one: the national missals' lectionary keys overlap the rite-level corpus, and
        // which key does so is data, not contract.
        $multi = null;
        foreach ($index['litcal_lectionary'] as $item) {
            if (count($item['sources']) > 1) {
                $multi = $item;
                break;
            }
        }
        self::assertNotNull($multi, 'no event_key is carried by more than one lectionary source');

        $body = $this->get(['sanctorale', $multi['event_key']]);
        self::assertSame($multi['event_key'], $body['event_key']);
        self::assertCount(
            count($multi['sources']),
            $body['readings'],
            'the item response must answer from exactly the sources the index said carry the event'
        );

        $pairs = array_map(
            static fn (array $r): string => $r['tier'] . ':' . $r['source_id'],
            $body['readings']
        );
        self::assertSame(count($pairs), count(array_unique($pairs)), 'each source must be reported once');
        self::assertContains('rite:roman', $pairs);
        self::assertNotEmpty(
            array_filter($pairs, static fn (string $p): bool => str_starts_with($p, 'missal:')),
            'a key carried by a national missal must name that missal, not be merged into the rite tier'
        );
    }

    // -------------------------------------------------------------- absent is not empty

    public function testALocaleThatOmitsAnEventIsReportedAsWithoutAnEntryNotAsAnEmptyOne(): void
    {
        $files = self::riteFiles();

        // Find a key some locale files carry and others do not — the state that a per-locale
        // request cannot tell apart from "translated but empty".
        $union = [];
        foreach ($files as $map) {
            foreach (array_keys($map) as $key) {
                $union[$key] = true;
            }
        }

        $partialKey = null;
        foreach (array_keys($union) as $key) {
            $absent = array_keys(array_filter($files, static fn (array $m): bool => false === array_key_exists($key, $m)));
            if ([] !== $absent && count($absent) < count($files)) {
                $partialKey = $key;
                break;
            }
        }
        self::assertNotNull(
            $partialKey,
            'no event_key in the rite-level corpus is present in some locales and absent from others, '
            . 'so this distinction cannot be exercised against real data any more'
        );

        $body = $this->get(['sanctorale', $partialKey]);
        $rite = null;
        foreach ($body['readings'] as $source) {
            if ($source['tier'] === 'rite') {
                $rite = $source;
                break;
            }
        }
        self::assertNotNull($rite);

        $expectedWith    = array_values(array_keys(array_filter($files, static fn (array $m): bool => array_key_exists($partialKey, $m))));
        $expectedWithout = array_values(array_keys(array_filter($files, static fn (array $m): bool => false === array_key_exists($partialKey, $m))));

        self::assertSame($expectedWith, $rite['locales_with_entry']);
        self::assertSame($expectedWithout, $rite['locales_without_entry']);
        self::assertNotEmpty($rite['locales_without_entry']);

        // The locales that omit it appear in neither `entries` nor the empty-entry list: "no entry"
        // and "an entry that is empty" are different answers, and only one of them is true here.
        foreach ($expectedWithout as $locale) {
            self::assertArrayNotHasKey($locale, $rite['entries']);
            self::assertNotContains($locale, $rite['locales_with_empty_entry']);
        }
        self::assertSame($expectedWith, array_keys($rite['entries']));
    }

    public function testAnEntryWhoseFieldsAreEmptyStringsIsReportedAsPresentAndEmpty(): void
    {
        $files = self::riteFiles();

        // A key every locale carries, with every field an empty string in at least one of them:
        // the placeholder convention this corpus uses for "structure in place, readings unwritten".
        $emptyKey = null;
        foreach (array_keys($files[array_key_first($files)]) as $key) {
            $inAll = array_all($files, static fn (array $m): bool => array_key_exists($key, $m));
            if (false === $inAll) {
                continue;
            }
            $allEmpty = array_all(
                $files,
                static fn (array $m): bool => array_all(
                    $m[$key],
                    static fn (mixed $v): bool => is_string($v) && $v === ''
                )
            );
            if ($allEmpty) {
                $emptyKey = $key;
                break;
            }
        }
        self::assertNotNull($emptyKey, 'no all-empty placeholder entry found in the rite-level corpus');

        $body = $this->get(['sanctorale', $emptyKey]);
        $rite = null;
        foreach ($body['readings'] as $source) {
            if ($source['tier'] === 'rite') {
                $rite = $source;
                break;
            }
        }
        self::assertNotNull($rite);

        // Present in every locale — and flagged empty in every locale, rather than dropped.
        self::assertSame(array_keys($files), $rite['locales_with_entry']);
        self::assertSame([], $rite['locales_without_entry']);
        self::assertSame(array_keys($files), $rite['locales_with_empty_entry']);
        foreach (array_keys($files) as $locale) {
            self::assertArrayHasKey($locale, $rite['entries']);
            foreach ($rite['entries'][$locale] as $value) {
                self::assertSame('', $value);
            }
        }
    }

    public function testAnEntryWithReadingsIsNotFlaggedEmpty(): void
    {
        $files = self::riteFiles();

        $contentKey    = null;
        $contentLocale = null;
        foreach ($files as $locale => $map) {
            foreach ($map as $key => $entry) {
                if (is_array($entry) && array_any($entry, static fn (mixed $v): bool => is_string($v) && $v !== '')) {
                    $contentKey    = (string) $key;
                    $contentLocale = (string) $locale;
                    break 2;
                }
            }
        }
        self::assertNotNull($contentKey);
        self::assertNotNull($contentLocale);

        $body = $this->get(['sanctorale', $contentKey]);
        $rite = null;
        foreach ($body['readings'] as $source) {
            if ($source['tier'] === 'rite') {
                $rite = $source;
                break;
            }
        }
        self::assertNotNull($rite);

        self::assertContains($contentLocale, $rite['locales_with_entry']);
        self::assertNotContains(
            $contentLocale,
            $rite['locales_with_empty_entry'],
            "{$contentLocale} has written readings for {$contentKey}, so it must not be reported as an empty entry"
        );
        self::assertNotEmpty($rite['entries'][$contentLocale]);
    }

    // ------------------------------------------------------------ a rite with no lectionary

    public function testAmbrosianIndexReportsNoLectionaryRatherThanNothingCurated(): void
    {
        $body = $this->get(['sanctorale'], Rite::AMBROSIAN);

        self::assertSame('ambrosian', $body['rite']);
        self::assertFalse($body['lectionary_available']);
        self::assertSame([], $body['sources']);
        self::assertSame([], $body['litcal_lectionary']);
        self::assertArrayHasKey('message', $body);
        self::assertStringContainsString('ambrosian', $body['message']);
    }

    public function testAmbrosianEventReportsNoLectionaryRatherThanNotFound(): void
    {
        // The same event_key is a 404 under a rite that has a lectionary and does not carry it
        // (asserted above); under a rite that has none it must be a well-formed "not available".
        $body = $this->get(['sanctorale', 'NotARealEventKeyAtAll'], Rite::AMBROSIAN);

        self::assertFalse($body['lectionary_available']);
        self::assertSame([], $body['readings']);
        self::assertSame('NotARealEventKeyAtAll', $body['event_key']);
        self::assertArrayHasKey('message', $body);
    }

    // ------------------------------------------------------------------ response schema

    public function testResponsesValidateAgainstTheLectionaryPathSchema(): void
    {
        $index = $this->get(['sanctorale']);
        self::assertNotEmpty($index['litcal_lectionary']);
        $someKey = $index['litcal_lectionary'][0]['event_key'];

        $cases = [
            'roman index'     => [['sanctorale'], Rite::ROMAN],
            'roman event'     => [['sanctorale', $someKey], Rite::ROMAN],
            'ambrosian index' => [['sanctorale'], Rite::AMBROSIAN],
            'ambrosian event' => [['sanctorale', $someKey], Rite::AMBROSIAN],
        ];

        foreach ($cases as $label => [$pathParams, $rite]) {
            $response = ( new LectionaryHandler($pathParams, $rite) )->handle(
                $this->requestFor('GET', '/lectionary/' . $rite->value . '/' . implode('/', $pathParams))
            );
            self::assertSame(200, $response->getStatusCode(), $label);
            $decoded = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);
            Schema::import(LitSchema::LECTIONARY_PATH->path())->in($decoded);
            $this->addToAssertionCount(1);
        }
    }
}
