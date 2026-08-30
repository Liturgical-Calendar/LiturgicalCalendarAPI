<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\SourceData\ChangeRequestSchemaValidator;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataSchemaResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeRequestSchemaValidator::class)]
final class ChangeRequestSchemaValidatorTest extends TestCase
{
    private const NATION_I18N = 'jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/en.json';

    private static string $projectRoot;

    private string $savedApiFilePath = '';

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = dirname(__DIR__, 3);
    }

    protected function setUp(): void
    {
        // LitSchema::path() prefixes Router::$apiFilePath, and the validator imports schemas
        // through it. Pin it the way AbstractHandlerTestCase does, and restore afterwards.
        $this->savedApiFilePath = isset(Router::$apiFilePath) ? Router::$apiFilePath : '';
        Router::$apiFilePath    = self::$projectRoot . DIRECTORY_SEPARATOR;
    }

    protected function tearDown(): void
    {
        Router::$apiFilePath = $this->savedApiFilePath;
    }

    /**
     * @param list<array{path: string, operation?: ChangeOperation, content: ?string}> $files
     * @return array<int, array<string, mixed>>
     */
    private static function rows(array $files): array
    {
        return array_map(
            static fn (array $f): array => [
                'path'      => $f['path'],
                'operation' => ( $f['operation'] ?? ChangeOperation::UPDATE )->value,
                'content'   => $f['content'],
            ],
            $files
        );
    }

    public function testContentThatSatisfiesItsSchemaProducesNoViolations(): void
    {
        $violations = ( new ChangeRequestSchemaValidator() )->violations(self::rows([
            ['path' => self::NATION_I18N, 'content' => '{"StFrancisAssisi":"Saint Francis of Assisi"}'],
        ]));

        self::assertSame([], $violations);
    }

    public function testContentThatViolatesItsSchemaIsReportedWithPathAndSchemaName(): void
    {
        // LitCalTranslation.json's additionalProperties are strings; a number is not one.
        $violations = ( new ChangeRequestSchemaValidator() )->violations(self::rows([
            ['path' => self::NATION_I18N, 'content' => '{"StFrancisAssisi":42}'],
        ]));

        self::assertCount(1, $violations);
        self::assertSame(self::NATION_I18N, $violations[0]['path']);
        // The bare filename, never the server path — see LitSchema::name().
        self::assertSame('LitCalTranslation.json', $violations[0]['schema']);
        self::assertNotSame('', $violations[0]['detail']);
    }

    public function testUnparseableContentIsAViolation(): void
    {
        $violations = ( new ChangeRequestSchemaValidator() )->violations(self::rows([
            ['path' => self::NATION_I18N, 'content' => '{"StFrancisAssisi": '],
        ]));

        self::assertCount(1, $violations);
        self::assertStringContainsString('not valid JSON', $violations[0]['detail']);
    }

    /**
     * The predicate is "does this row carry bytes", not the operation and not
     * `metadata.deletes_resource`. An empty object would fail `LitCalTranslation.json`'s
     * `minProperties`, so this only passes because a content-less row is never checked at all.
     */
    public function testARowWithNoContentIsNeverChecked(): void
    {
        $violations = ( new ChangeRequestSchemaValidator() )->violations(self::rows([
            ['path' => self::NATION_I18N, 'operation' => ChangeOperation::DELETE, 'content' => null],
        ]));

        self::assertSame([], $violations);
    }

    /**
     * A row carrying bytes but no usable path cannot be resolved to a schema, and must be passed
     * over rather than reported as a violation — the same direction the unmapped-path case takes,
     * and for the same reason: refusing an approval over something no schema claims would jam the
     * queue on a batch nothing found fault with.
     */
    public function testARowWithAnEmptyPathIsSkippedRatherThanRefused(): void
    {
        $violations = ( new ChangeRequestSchemaValidator() )->violations(self::rows([
            ['path' => '', 'operation' => ChangeOperation::UPDATE, 'content' => '{"nonsense":true}'],
        ]));

        self::assertSame([], $violations);
    }

    /**
     * A locale-drop batch: one DELETE for the locale file, one UPDATE restaging the calendar's
     * own translations. `operation = 'delete'` says nothing about the batch, and the row that
     * does carry content is checked exactly as it would be on its own.
     */
    public function testOnlyTheContentBearingRowsOfAMixedBatchAreChecked(): void
    {
        $violations = ( new ChangeRequestSchemaValidator() )->violations(self::rows([
            [
                'path'      => 'jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/de.json',
                'operation' => ChangeOperation::DELETE,
                'content'   => null,
            ],
            ['path' => self::NATION_I18N, 'content' => '{"StFrancisAssisi":42}'],
        ]));

        self::assertCount(1, $violations);
        self::assertSame(self::NATION_I18N, $violations[0]['path']);
    }

    public function testEveryOffendingRowIsReported(): void
    {
        $violations = ( new ChangeRequestSchemaValidator() )->violations(self::rows([
            ['path' => self::NATION_I18N, 'content' => '{"StFrancisAssisi":42}'],
            [
                'path'    => 'jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/it.json',
                'content' => '{"not a valid event key":"x"}',
            ],
        ]));

        self::assertCount(2, $violations);
    }

    public function testAPathNoSchemaGovernsIsNotAViolation(): void
    {
        $violations = ( new ChangeRequestSchemaValidator() )->violations(self::rows([
            [
                'path'    => 'jsondata/sourcedata/rite/roman/missals/propriumdetempore/propriumdetempore.json',
                'content' => '{"anything":"at all"}',
            ],
        ]));

        self::assertSame([], $violations);
    }

    /**
     * The gate is only worth anything if it agrees with the repository it is guarding: every
     * committed source file whose family this validator claims must pass it. A failure here
     * means either the path table maps a family to the wrong schema, or `jsondata` has drifted
     * from its own schemas — both of which would otherwise surface as spurious refusals of
     * perfectly good change requests.
     */
    public function testEveryCommittedSourceFileTheResolverClaimsAlreadyValidates(): void
    {
        $rows     = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::$projectRoot . '/jsondata', \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'json') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(self::$projectRoot) + 1);
            if (SourceDataSchemaResolver::forPath($relative) === null) {
                continue;
            }
            $rows[] = [
                'path'      => $relative,
                'operation' => ChangeOperation::UPDATE->value,
                'content'   => (string) file_get_contents($file->getPathname()),
            ];
        }

        self::assertNotEmpty($rows, 'the resolver claimed no committed source file — the assertion would be vacuous');

        $violations = ( new ChangeRequestSchemaValidator() )->violations($rows);

        self::assertSame(
            [],
            $violations,
            'committed source data must satisfy the schemas this gate enforces: '
            . implode(', ', array_map(static fn (array $v): string => $v['path'], $violations))
        );
    }
}
