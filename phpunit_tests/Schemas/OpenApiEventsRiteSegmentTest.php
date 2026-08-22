<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Models\Metadata\MetadataDiocesanCalendarItem;
use LiturgicalCalendar\Api\Params\EventsParams;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `/events` sibling of {@see OpenApiDataRiteSegmentTest}.
 *
 * `Router::extractRiteSegment()` accepts an optional leading rite segment on `events` exactly as
 * it does on `calendar` and `data`, and `Router::canonicalRiteUrl()` answers a bare request with a
 * `Link: rel="canonical"` naming the explicit form — but openapi.json documented only
 * `/events/roman`, leaving `/events/roman/nation/{calendar_id}` and
 * `/events/roman/diocese/{calendar_id}` undescribed. The API therefore pointed clients at a
 * canonical URL its own contract did not contain (#848, first reported in #818).
 *
 * As in the `/data` sibling, both halves are asserted against the runtime rule
 * ({@see EventsParams::validateRiteCompatibility()}) rather than against a second hardcoded list,
 * so the document cannot drift away from the routing grammar: the Ambrosian rite has no national
 * layer, and a documented `/events/ambrosian/nation/{calendar_id}` would be a lie a generated
 * client would act on.
 */
final class OpenApiEventsRiteSegmentTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $openapi;

    private static string $savedApiPath     = '';
    private static string $savedApiFilePath = '';

    public static function setUpBeforeClass(): void
    {
        $raw = file_get_contents(dirname(__DIR__, 2) . '/jsondata/schemas/openapi.json');
        self::assertIsString($raw, 'Could not read openapi.json');

        /** @var array<string,mixed> $decoded */
        $decoded       = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::$openapi = $decoded;

        // EventsParams builds the calendars metadata index in-process from local source data,
        // so pin Router::$apiPath/$apiFilePath the way the production Router does (same setup
        // as EventsParamsTest).
        self::$savedApiPath     = isset(Router::$apiPath) ? Router::$apiPath : '';
        self::$savedApiFilePath = isset(Router::$apiFilePath) ? Router::$apiFilePath : '';
        Router::$apiPath        = '';
        Router::$apiFilePath    = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiPath     = self::$savedApiPath;
        Router::$apiFilePath = self::$savedApiFilePath;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function paths(): array
    {
        /** @var array<string,array<string,mixed>> $paths */
        $paths = self::$openapi['paths'];

        return $paths;
    }

    /**
     * Whether the handler's parameter object accepts this rite for this tier, asked of the same
     * object the running API asks. `null` for the tier means the bare rite root (`/events/roman`),
     * which addresses the rite's own catalog and takes no calendar id.
     */
    private static function tierIsAccepted(Rite $rite, ?string $tier): bool
    {
        $params = [];

        if ($tier === 'nation') {
            $nations = ( new EventsParams() )->calendarsMetadata->national_calendars_keys;
            self::assertNotEmpty($nations, 'precondition: at least one national calendar exists');
            $params['national_calendar'] = $nations[0];
        }

        if ($tier === 'diocese') {
            // Derived from the diocese metadata rather than named here: a diocese is addressable
            // under exactly the rite it declares, so "does this rite have a diocesan tier" is the
            // same question as "does any diocese declare this rite".
            $diocese = array_find(
                ( new EventsParams() )->calendarsMetadata->diocesan_calendars,
                static fn (MetadataDiocesanCalendarItem $item): bool => $item->rite === $rite
            );

            if (null === $diocese) {
                return false;
            }

            $params['diocesan_calendar'] = $diocese->calendar_id;
        }

        try {
            $eventsParams = new EventsParams($params);
            $eventsParams->setRite($rite);
            $eventsParams->validateRiteCompatibility();
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string,array{0:Rite,1:?string}>
     */
    public static function riteAndTierCombinations(): array
    {
        $combinations = [];

        foreach (Rite::cases() as $rite) {
            foreach ([null, 'nation', 'diocese'] as $tier) {
                $combinations[$rite->value . ' / ' . ( $tier ?? 'rite root' )] = [$rite, $tier];
            }
        }

        return $combinations;
    }

    /**
     * The contract must list exactly the rite-qualified `/events` paths the API accepts — no more
     * (a documented 400 is a lie a generated client will act on) and no fewer (the omission is
     * what #818 reports).
     */
    #[DataProvider('riteAndTierCombinations')]
    public function testRiteQualifiedPathIsDocumentedExactlyWhenItIsAccepted(Rite $rite, ?string $tier): void
    {
        $suffix = null === $tier ? '' : "/{$tier}/{calendar_id}";
        $path   = "/events/{$rite->value}{$suffix}";

        if (self::tierIsAccepted($rite, $tier)) {
            self::assertArrayHasKey(
                $path,
                self::paths(),
                "the API accepts {$path} but openapi.json does not document it"
            );
        } else {
            self::assertArrayNotHasKey(
                $path,
                self::paths(),
                "openapi.json documents {$path}, which the API refuses"
            );
        }
    }

    /**
     * The bare spelling stays documented alongside the canonical one: it is what the existing
     * browser clients build, and it is the form the `Link: rel="canonical"` header is emitted on.
     *
     * @return array<string,array{0:string}>
     */
    public static function bareEventsPaths(): array
    {
        return [
            '/events'                       => ['/events'],
            '/events/nation/{calendar_id}'  => ['/events/nation/{calendar_id}'],
            '/events/diocese/{calendar_id}' => ['/events/diocese/{calendar_id}']
        ];
    }

    #[DataProvider('bareEventsPaths')]
    public function testTheBareFormOfEveryTierStaysDocumented(string $path): void
    {
        self::assertArrayHasKey($path, self::paths(), 'the bare form of every tier must stay documented');
    }

    /**
     * The rite segment selects which catalog is built; it does not change what may be done to it.
     * `Router`'s `events` case wires GET and POST for both the rite root and a two-part tier path,
     * and the rite segment does not alter the part count it keys off.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function bareAndCanonicalPathPairs(): array
    {
        return [
            'rite root' => ['/events', '/events/roman'],
            'nation'    => ['/events/nation/{calendar_id}', '/events/roman/nation/{calendar_id}'],
            'diocese'   => ['/events/diocese/{calendar_id}', '/events/roman/diocese/{calendar_id}']
        ];
    }

    #[DataProvider('bareAndCanonicalPathPairs')]
    public function testTheCanonicalFormMirrorsTheBareFormsOperationSurface(string $bare, string $canonical): void
    {
        $paths = self::paths();
        self::assertArrayHasKey($bare, $paths);
        self::assertArrayHasKey($canonical, $paths);

        /** @var array<string,mixed> $barePathItem */
        $barePathItem = $paths[$bare];
        /** @var array<string,mixed> $canonicalPathItem */
        $canonicalPathItem = $paths[$canonical];

        self::assertSame(
            self::operationMethods($barePathItem),
            self::operationMethods($canonicalPathItem),
            "{$canonical} must document the same methods as {$bare}"
        );
    }

    /**
     * A diocese is addressable under exactly the rite it declares: asking for an Ambrosian diocese
     * under the Roman rite (or the reverse) is refused with a `400 Bad Request` by
     * {@see EventsParams::validateRiteCompatibility()}. Both spellings of the diocesan path can
     * produce that refusal — the bare one because it resolves to the Roman rite — so both have to
     * declare it, or a client generated from this document has no branch for the one status it
     * will actually meet on a wrong-rite diocese.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function diocesanOperationsThatCanRefuseOnRite(): array
    {
        $operations = [];

        foreach (['get', 'post'] as $method) {
            foreach (['/events/diocese/{calendar_id}', '/events/roman/diocese/{calendar_id}', '/events/ambrosian/diocese/{calendar_id}'] as $path) {
                $operations[strtoupper($method) . ' ' . $path] = [$path, $method];
            }
        }

        return $operations;
    }

    #[DataProvider('diocesanOperationsThatCanRefuseOnRite')]
    public function testTheRiteMismatchRefusalIsDeclared(string $path, string $method): void
    {
        $paths = self::paths();
        self::assertArrayHasKey($path, $paths);

        /** @var array<string,array<string,array<string,mixed>>> $pathItem */
        $pathItem = $paths[$path];
        self::assertArrayHasKey($method, $pathItem);

        self::assertArrayHasKey(
            '400',
            $pathItem[$method]['responses'],
            strtoupper($method) . " {$path} can refuse a diocese of the wrong rite with 400, which it does not declare"
        );
    }

    /**
     * The HTTP methods a path item documents, sorted, with any non-method key (`parameters`,
     * `summary`, `description`, `servers`) filtered out — those are legal siblings of the
     * operations in an OpenAPI path item and are not operations themselves.
     *
     * @param array<string,mixed> $pathItem
     *
     * @return list<string>
     */
    private static function operationMethods(array $pathItem): array
    {
        $methods = array_values(array_intersect(
            array_keys($pathItem),
            ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace']
        ));
        sort($methods);

        return $methods;
    }
}
