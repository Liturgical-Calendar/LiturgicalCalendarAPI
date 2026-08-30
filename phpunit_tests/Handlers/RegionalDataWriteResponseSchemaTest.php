<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\RegionalDataHandler;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode;
use LiturgicalCalendar\Tests\Support\OpenApiPathItemTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Swaggest\JsonSchema\Schema;

/**
 * The `/data/*` write responses, validated against the response schemas `openapi.json` declares
 * for them (#933).
 *
 * Those schemas are `additionalProperties: false` and used to declare only `success`,
 * `disposition` and `change_request`, while `RegionalDataHandler` also sets `$responseObj->data`
 * on every one of them. The disagreement was a **wrong-red**: a strict response validator run
 * against a correct server rejected a response the API is designed to return, and a client
 * generated from the document did not know the key existed even though the frontend already read
 * it. `data` is now documented; this class is what stops the two drifting apart again.
 *
 * # Why queue mode
 *
 * Finishing a write in disk mode would create real files under `jsondata/sourcedata` — the reason
 * `RegionalDataHandlerTest` stops short of one. Queue mode runs the identical response-assembly
 * code (the `data` assignment is unconditional and sits above the write-mode branch) without the
 * handler touching the filesystem, exactly as `RegionalDataQueueModeTest` does.
 *
 * That is also the interesting disposition. `data` is set from the raw payload whatever the mode,
 * so under a queued write it is the PROPOSED payload and not a stored resource — the trap the
 * documented description warns about, and the one a client that skips the `disposition` branch
 * falls into.
 *
 * # Why six drives and a fourteen-operation static check
 *
 * The document has fourteen affected operations, but the handler has six response-assembly sites:
 * the rite-qualified spellings (`/data/roman/nation/{key}`, `/data/ambrosian/diocese/{key}`)
 * route to the same code as the bare ones. So the six are driven for real, and
 * {@see testEveryDataWriteOperationDocumentsData()} asserts that all fourteen documented schemas
 * carry an identical `data` declaration — which is what carries the live evidence across to the
 * spellings that share the code.
 */
#[CoversClass(RegionalDataHandler::class)]
final class RegionalDataWriteResponseSchemaTest extends AbstractHandlerTestCase
{
    use OpenApiPathItemTrait;

    protected static bool $requiresDatabase = true;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        Connection::getInstance()->exec('TRUNCATE TABLE sourcedata_change_requests RESTART IDENTITY CASCADE');

        foreach ([SourceDataWriteMode::FLAG, 'OPENFGA_API_URL', 'OPENFGA_STORE_ID', 'OPENFGA_MODEL_ID'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? false;
        }

        // A store id that cannot exist, so every FGA check fails closed and the submission stays
        // `submitted` rather than being auto-approved — the disposition under which `data` is at
        // its most misleading, and therefore the one worth validating.
        $_ENV[SourceDataWriteMode::FLAG] = 'true';
        $_ENV['OPENFGA_API_URL']         = 'http://localhost:8083';
        $_ENV['OPENFGA_STORE_ID']        = 'no-such-store-regional-response-schema-test';
        $_ENV['OPENFGA_MODEL_ID']        = 'no-such-model-regional-response-schema-test';
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if (false === $value) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        $this->originalEnv = [];

        parent::tearDown();
    }

    /**
     * The fourteen `/data/*` write responses this contract covers, enumerated independently of
     * the document being checked.
     *
     * Discovery alone cannot guard a contract it reads from its own subject: if a path were
     * dropped from `openapi.json`, or a response lost `disposition`, a purely discovered provider
     * would yield fewer cases and every remaining one would still pass — the suite would go green
     * on a shrinking contract. So the set lives here, and {@see testTheDocumentedWriteSurfaceIsExactlyTheKnownFourteen}
     * asserts the document still matches it in both directions.
     *
     * Adding a rite therefore reds this list on purpose. That is the point: a new rite's write
     * routes inherit the `data` trap documented below, and someone should say so deliberately
     * rather than have it picked up silently.
     *
     * @return list<array{0:string, 1:string, 2:string}>
     */
    private static function expectedWriteOperations(): array
    {
        $targets = [];
        foreach (
            [
                '/data/nation/{key}',
                '/data/roman/nation/{key}',
                '/data/diocese/{key}',
                '/data/roman/diocese/{key}',
                '/data/widerregion/{key}',
                '/data/roman/widerregion/{key}',
                '/data/ambrosian/diocese/{key}',
            ] as $path
        ) {
            // PUT creates (201), PATCH updates (200); both assemble the body the same way.
            $targets[] = [$path, 'put', '201'];
            $targets[] = [$path, 'patch', '200'];
        }

        return $targets;
    }

    /**
     * @return array<string, array{0:string, 1:string, 2:string}>
     */
    public static function documentedWriteOperations(): array
    {
        $operations = [];
        foreach (self::expectedWriteOperations() as [$path, $method, $status]) {
            $operations[strtoupper($method) . ' ' . $path . ' ' . $status] = [$path, $method, $status];
        }

        return $operations;
    }

    /**
     * The enumerated set and the document agree, in both directions.
     *
     * Checked as a set rather than a count: a count catches a deletion but not a swap, and the
     * failure message for a mismatched count tells a reader nothing about which operation moved.
     */
    public function testTheDocumentedWriteSurfaceIsExactlyTheKnownFourteen(): void
    {
        $discovered = [];
        foreach (self::openapiPaths() as $path => $pathItem) {
            if (!str_starts_with($path, '/data/')) {
                continue;
            }

            foreach (array_intersect(self::operationMethods($pathItem), ['put', 'patch']) as $method) {
                /** @var array<string, array<string, mixed>> $responses */
                $responses = $pathItem[$method]['responses'];
                foreach ($responses as $status => $response) {
                    if (null === self::jsonSchemaOf($response)) {
                        continue;
                    }
                    $discovered[] = strtoupper($method) . ' ' . $path . ' ' . $status;
                }
            }
        }

        $expected = array_keys(self::documentedWriteOperations());
        sort($discovered);
        sort($expected);

        self::assertSame(
            $expected,
            $discovered,
            'The set of /data/* write responses in openapi.json has changed. If a rite or route was '
            . 'added, extend expectedWriteOperations() — the new operation inherits the `data` contract.'
        );
    }

    /**
     * Every documented write response declares `disposition`.
     *
     * Asserted rather than used as a provider filter: filtering on it would let a response that
     * lost the key vanish from the run instead of failing it.
     */
    #[DataProvider('documentedWriteOperations')]
    public function testEveryDataWriteOperationDocumentsDisposition(string $path, string $method, string $status): void
    {
        /** @var array<string, array<string, array<string, mixed>>> $pathItem */
        $pathItem = self::openapiPaths()[$path];
        $schema   = self::jsonSchemaOf($pathItem[$method]['responses'][$status]);

        self::assertIsArray($schema);
        self::assertIsArray($schema['properties'] ?? null);
        self::assertArrayHasKey(
            'disposition',
            $schema['properties'],
            strtoupper($method) . " {$path} ({$status}) must document `disposition`; it is what tells a client whether `data` was stored"
        );
    }

    /**
     * Every affected operation declares `data`, and declares it identically — the response is
     * assembled by one code path per verb, so fourteen divergent descriptions of the same key
     * would be fourteen chances to describe it wrongly.
     */
    #[DataProvider('documentedWriteOperations')]
    public function testEveryDataWriteOperationDocumentsData(string $path, string $method, string $status): void
    {
        /** @var array<string, array<string, array<string, mixed>>> $pathItem */
        $pathItem = self::openapiPaths()[$path];
        $schema   = self::jsonSchemaOf($pathItem[$method]['responses'][$status]);

        self::assertIsArray($schema);
        self::assertArrayHasKey('properties', $schema);
        self::assertIsArray($schema['properties']);
        self::assertArrayHasKey(
            'data',
            $schema['properties'],
            strtoupper($method) . " {$path} ({$status}) is additionalProperties:false and the handler emits `data`"
        );

        /** @var array<string, mixed> $data */
        $data = $schema['properties']['data'];
        self::assertSame('object', $data['type'] ?? null);
        self::assertIsString($data['description'] ?? null);

        // The trap, stated in the document and not only in a commit message: `data` is the
        // proposed payload unless the disposition says otherwise.
        self::assertStringContainsString('disposition', $data['description']);
        self::assertStringContainsString('applied', $data['description']);

        // Not required: the response carries it today, but the documented direction is to stop
        // emitting it on a non-`applied` disposition, and a client must not be built to need it.
        $required = $schema['required'] ?? [];
        self::assertIsArray($required);
        self::assertNotContains('data', $required);
    }

    /**
     * The six response-assembly sites in `RegionalDataHandler`, each with the operation in the
     * document that describes it.
     *
     * IT is patched rather than created because `updateI18nFiles()` requires the locale files it
     * updates to exist on disk; its single declared locale is `it_IT`, which is what the payload
     * declares. Nothing is written either way — queue mode stages, it does not save.
     *
     * @return array<string, array{0:list<string>, 1:?Rite, 2:string, 3:string, 4:array<string,mixed>, 5:int, 6:string, 7:string, 8:string}>
     */
    public static function writeDrives(): array
    {
        return [
            'PUT /data/nation/{key}'        => [
                ['nation', 'MT'],
                null,
                'PUT',
                '/data/nation/MT',
                self::nationalPayload('MT'),
                201,
                '/data/nation/{key}',
                'put',
                '201',
            ],
            'PATCH /data/nation/{key}'      => [
                ['nation', 'IT'],
                null,
                'PATCH',
                '/data/nation/IT',
                self::nationalPayload('IT'),
                200,
                '/data/nation/{key}',
                'patch',
                '200',
            ],
            'PUT /data/diocese/{key}'       => [
                ['diocese', 'aachen_de'],
                null,
                'PUT',
                '/data/diocese/aachen_de',
                self::newDiocesanPayload(),
                201,
                '/data/diocese/{key}',
                'put',
                '201',
            ],
            'PATCH /data/diocese/{key}'     => [
                ['diocese', 'novara_it'],
                Rite::AMBROSIAN,
                'PATCH',
                '/data/diocese/novara_it',
                self::existingDiocesanPayload(),
                200,
                '/data/ambrosian/diocese/{key}',
                'patch',
                '200',
            ],
            'PUT /data/widerregion/{key}'   => [
                ['widerregion', 'Africa'],
                null,
                'PUT',
                '/data/widerregion/Africa',
                self::widerRegionPayload('Africa'),
                201,
                '/data/widerregion/{key}',
                'put',
                '201',
            ],
            'PATCH /data/widerregion/{key}' => [
                ['widerregion', 'Europe'],
                null,
                'PATCH',
                '/data/widerregion/Europe',
                self::widerRegionPayload('Europe'),
                200,
                '/data/widerregion/{key}',
                'patch',
                '200',
            ],
        ];
    }

    /**
     * The regression guard proper: the response the handler really produces, validated against
     * the schema the document really declares.
     *
     * @param list<string>        $handlerArgs
     * @param array<string,mixed> $payload
     */
    #[DataProvider('writeDrives')]
    public function testTheRealResponseValidatesAgainstTheDocumentedSchema(
        array $handlerArgs,
        ?Rite $rite,
        string $method,
        string $route,
        array $payload,
        int $expectedStatus,
        string $docPath,
        string $docMethod,
        string $docStatus
    ): void {
        $handler  = $rite === null ? new RegionalDataHandler($handlerArgs) : new RegionalDataHandler($handlerArgs, $rite);
        $headers  = $method === 'PATCH' ? ['Accept-Language' => 'it-IT'] : [];
        $response = $handler->handle(
            $this->withOidcUser($this->requestFor($method, $route, $headers, $payload), 'editor-1')
        );

        self::assertSame($expectedStatus, $response->getStatusCode(), (string) $response->getBody());

        $body = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $body);

        // The premise: the handler does emit `data`, unconditionally, even though this write was
        // queued and nothing was stored. If this ever stops being true, the documented
        // description above has become a lie and must be revised with it.
        self::assertObjectHasProperty('data', $body);
        self::assertSame('submitted', $body->disposition ?? null);

        Schema::import(self::pointerTo($docPath, $docMethod, $docStatus))->in($body);
        $this->addToAssertionCount(1);
    }

    /**
     * A `$ref`-able URI naming one response schema inside `openapi.json`, with the JSON Pointer
     * escaping (`/` → `~1`) the path keys need.
     */
    private static function pointerTo(string $path, string $method, string $status): string
    {
        $escaped = str_replace(['~', '/'], ['~0', '~1'], $path);

        return dirname(__DIR__, 2) . '/jsondata/schemas/openapi.json'
            . "#/paths/{$escaped}/{$method}/responses/{$status}/content/application~1json/schema";
    }

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $openapi = null;

    /**
     * Loaded lazily: the data providers enumerate the document, and PHPUnit runs providers before
     * any fixture method.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function openapiPaths(): array
    {
        if (null === self::$openapi) {
            $raw = file_get_contents(dirname(__DIR__, 2) . '/jsondata/schemas/openapi.json');
            if (false === $raw) {
                throw new \RuntimeException('Could not read openapi.json');
            }
            /** @var array<string, mixed> $decoded */
            $decoded       = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            self::$openapi = $decoded;
        }

        /** @var array<string, array<string, mixed>> $paths */
        $paths = self::$openapi['paths'];

        return $paths;
    }

    /**
     * The `application/json` schema of a response object, or null when it declares no JSON body.
     *
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>|null
     */
    private static function jsonSchemaOf(array $response): ?array
    {
        $content = $response['content'] ?? null;
        if (!is_array($content) || !isset($content['application/json'])) {
            return null;
        }

        /** @var array<string, mixed> $json */
        $json   = $content['application/json'];
        $schema = $json['schema'] ?? null;

        return is_array($schema) ? $schema : null;
    }

    /**
     * A schema-valid national-calendar payload. `wider_region` is Europe for both fixture nations,
     * and the single declared locale matches the one i18n file the PATCH path expects to find.
     *
     * @return array<string, mixed>
     */
    private static function nationalPayload(string $nation): array
    {
        $locale = $nation === 'IT' ? 'it_IT' : 'en_MT';

        return [
            'litcal'   => [
                [
                    'liturgical_event' => ['event_key' => 'StGeorgeMartyr', 'grade' => 4],
                    'metadata'         => [
                        'action'     => 'makePatron',
                        'since_year' => 1868,
                        'url'        => 'https://www.vatican.va/',
                    ],
                ],
            ],
            'settings' => [
                'epiphany'               => 'JAN6',
                'ascension'              => 'SUNDAY',
                'corpus_christi'         => 'SUNDAY',
                'eternal_high_priest'    => false,
                'holydays_of_obligation' => [
                    'Christmas'            => true,
                    'Epiphany'             => false,
                    'Ascension'            => false,
                    'CorpusChristi'        => false,
                    'MaryMotherOfGod'      => true,
                    'ImmaculateConception' => true,
                    'Assumption'           => true,
                    'StJoseph'             => false,
                    'StsPeterPaulAp'       => false,
                    'AllSaints'            => false,
                ],
            ],
            'metadata' => [
                'nation'       => $nation,
                'wider_region' => 'Europe',
                'missals'      => ['IT_1983'],
                'locales'      => [$locale],
            ],
            'i18n'     => [
                $locale => ['StGeorgeMartyr' => 'Saint George, Martyr'],
            ],
        ];
    }

    /**
     * A diocese with a schema-valid name that has no calendar in the tree, so PUT is a genuine
     * create rather than a conflict.
     *
     * @return array<string, mixed>
     */
    private static function newDiocesanPayload(): array
    {
        return [
            'litcal'   => [
                [
                    'liturgical_event' => [
                        'event_key' => 'StsProtaseGervase',
                        'color'     => ['red'],
                        'grade'     => 3,
                        'common'    => ['Proper'],
                        'day'       => 19,
                        'month'     => 6,
                    ],
                    'metadata'         => ['since_year' => 2024, 'form_rownum' => 0],
                ],
            ],
            'metadata' => [
                'nation'       => 'DE',
                'diocese_id'   => 'aachen_de',
                'diocese_name' => 'Aachen',
                'locales'      => ['de_DE'],
                'timezone'     => 'Europe/Berlin',
                'rite'         => 'roman',
            ],
            'i18n'     => [
                'de_DE' => ['StsProtaseGervase' => 'Heilige Protasius und Gervasius'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function existingDiocesanPayload(): array
    {
        return [
            'litcal'   => [
                [
                    'liturgical_event' => [
                        'event_key' => 'StsProtaseGervase',
                        'color'     => ['morello'],
                        'grade'     => 3,
                        'common'    => ['Proper'],
                        'day'       => 19,
                        'month'     => 6,
                    ],
                    'metadata'         => ['since_year' => 2024, 'form_rownum' => 0],
                ],
            ],
            'metadata' => [
                'nation'       => 'IT',
                'diocese_id'   => 'novara_it',
                'diocese_name' => 'Diocesi di Novara',
                'locales'      => ['it_IT'],
                'timezone'     => 'Europe/Rome',
                'rite'         => 'ambrosian',
            ],
            'i18n'     => [
                'it_IT' => ['StsProtaseGervase' => 'Santi Protaso e Gervaso'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function widerRegionPayload(string $region): array
    {
        return [
            'litcal'             => [
                [
                    'liturgical_event' => ['event_key' => 'StBenedict', 'grade' => 4],
                    'metadata'         => [
                        'action'       => 'makePatron',
                        'since_year'   => 1964,
                        'url'          => 'https://www.vatican.va/',
                        'url_lang_map' => ['it' => 'it', 'la' => 'la'],
                    ],
                ],
            ],
            'national_calendars' => ['Italy' => 'IT', 'France' => 'FR'],
            'metadata'           => [
                'wider_region' => $region,
                'locales'      => ['it_IT'],
            ],
            'i18n'               => [
                'it_IT' => ['StBenedict' => 'San Benedetto'],
            ],
        ];
    }
}
