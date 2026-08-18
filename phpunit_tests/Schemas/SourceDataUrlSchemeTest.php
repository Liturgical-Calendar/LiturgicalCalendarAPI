<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

/**
 * Regression tests for issue #789: `format: uri` does not constrain the URI scheme.
 *
 * `javascript:alert(1)` is a perfectly well-formed RFC 3986 URI, so a `format: uri`
 * assertion accepts it. The source-data `url` values are interpolated into `href`
 * attributes in the calendar `messages[]` output, so the schemas additionally pin
 * them to `^https://`.
 *
 * Two complementary guarantees are covered here:
 *
 * 1. every `url` actually shipped in `jsondata/sourcedata/` satisfies `^https://`,
 *    so the tightened schemas do not reject the existing corpus;
 * 2. each of the six schema declarations really does reject a `javascript:` URL
 *    while still accepting an `https://` URL containing a `%s` sprintf placeholder
 *    (several Vatican URLs carry a language placeholder).
 */
final class SourceDataUrlSchemeTest extends TestCase
{
    private const DANGEROUS_URL = 'javascript:alert(1)';

    private const BENIGN_URL = 'https://www.vatican.va/content/paul-vi/%s/apost_letters/documents/hf_p-vi_apl_19641024_pacis-nuntius.html';

    private static bool $routerInitialized = false;

    /**
     * Router::getApiPaths() is idempotent, but a flag keeps repeated calls cheap.
     * It is invoked from the data providers too, which run before setUpBeforeClass().
     */
    private static function initRouter(): void
    {
        if (false === self::$routerInitialized) {
            Router::getApiPaths();
            self::$routerInitialized = true;
        }
    }

    public static function setUpBeforeClass(): void
    {
        self::initRouter();
    }

    /**
     * The six source-data declarations that govern a `url` value which ends up inside an `href`.
     *
     * @return array<string, array{LitSchema, string}>
     */
    private static function urlSchemaPointers(): array
    {
        return [
            'DiocesanCalendar litcal[].metadata.url'      => [LitSchema::DIOCESAN, '/definitions/LitCal/items/properties/metadata/properties/url'],
            'NationalCalendar setProperty/grade url'      => [LitSchema::NATIONAL, '/definitions/LitCalSetPropertyGrade/properties/metadata/properties/url'],
            'NationalCalendar setProperty/name url'       => [LitSchema::NATIONAL, '/definitions/LitCalSetPropertyName/properties/metadata/properties/url'],
            'NationalCalendar makePatron url'             => [LitSchema::NATIONAL, '/definitions/LitCalMakePatron/properties/metadata/properties/url'],
            'WiderRegionCalendar createNew metadata url'  => [LitSchema::WIDERREGION, '/definitions/MetadataCreateNew/properties/url'],
            'WiderRegionCalendar makePatron metadata url' => [LitSchema::WIDERREGION, '/definitions/MetadataMakePatron/properties/url'],
        ];
    }

    /**
     * @return \Generator<string, array{LitSchema, string}>
     */
    public static function urlSchemaPointerProvider(): \Generator
    {
        foreach (self::urlSchemaPointers() as $label => $pointer) {
            yield $label => $pointer;
        }
    }

    /**
     * Every `url` string value found anywhere under `jsondata/sourcedata/`, one case per value,
     * keyed by `<relative file path>#<json pointer>` so a failure names the offending location.
     *
     * @return \Generator<string, array{string, string, string}>
     */
    public static function shippedSourceDataUrlProvider(): \Generator
    {
        self::initRouter();

        $sourceDataFolder = JsonData::SOURCEDATA_FOLDER->path();
        $iterator         = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDataFolder, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var string[] $files */
        $files = [];
        foreach ($iterator as $fileInfo) {
            if ($fileInfo instanceof \SplFileInfo && $fileInfo->isFile() && 'json' === $fileInfo->getExtension()) {
                $files[] = $fileInfo->getPathname();
            }
        }
        sort($files);

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if (false === $contents) {
                continue;
            }

            $decoded  = json_decode($contents);
            $relative = str_replace($sourceDataFolder . DIRECTORY_SEPARATOR, '', $file);

            $urls = [];
            self::collectUrls($decoded, '', $urls);
            foreach ($urls as $pointer => $url) {
                yield $relative . '#' . $pointer => [$relative, $pointer, $url];
            }
        }
    }

    /**
     * Recursively collect every `url` property whose value is a string, indexed by JSON pointer.
     *
     * @param array<string, string> $collected
     * @param-out array<string, string> $collected
     */
    private static function collectUrls(mixed $node, string $pointer, array &$collected): void
    {
        if ($node instanceof \stdClass) {
            foreach (get_object_vars($node) as $key => $value) {
                $childPointer = $pointer . '/' . str_replace(['~', '/'], ['~0', '~1'], $key);
                if ('url' === $key && is_string($value)) {
                    $collected[$childPointer] = $value;
                }
                self::collectUrls($value, $childPointer, $collected);
            }
        } elseif (is_array($node)) {
            foreach ($node as $index => $value) {
                self::collectUrls($value, $pointer . '/' . (string) $index, $collected);
            }
        }
    }

    /**
     * Guard against the shipped-data provider silently degenerating to a handful of cases
     * (or to none at all) if the source-data tree is ever moved or restructured.
     */
    public function testShippedSourceDataProviderIsNotVacuous(): void
    {
        $count = iterator_count(self::shippedSourceDataUrlProvider());
        $this->assertGreaterThanOrEqual(20, $count, 'Expected the shipped source data to contain a meaningful number of `url` values');
    }

    #[DataProvider('shippedSourceDataUrlProvider')]
    public function testShippedSourceDataUrlUsesHttpsScheme(string $relativePath, string $pointer, string $url): void
    {
        $this->assertMatchesRegularExpression(
            '/^https:\/\//',
            $url,
            sprintf('`%s` at JSON pointer `%s` must use the https:// scheme, got: %s', $relativePath, $pointer, $url)
        );
    }

    #[DataProvider('urlSchemaPointerProvider')]
    public function testSchemaRejectsDangerousUrlScheme(LitSchema $litSchema, string $pointer): void
    {
        $schema = self::importSubSchema($litSchema, $pointer);

        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        $schema->in(self::DANGEROUS_URL);
    }

    #[DataProvider('urlSchemaPointerProvider')]
    public function testSchemaAcceptsHttpsUrlWithSprintfPlaceholder(LitSchema $litSchema, string $pointer): void
    {
        $schema = self::importSubSchema($litSchema, $pointer);

        $this->assertSame(self::BENIGN_URL, $schema->in(self::BENIGN_URL));
    }

    /**
     * Validating a whole document (rather than the isolated `url` sub-schema) proves that the
     * constraint is actually reached along the real validation path used for national calendars.
     */
    public function testNationalCalendarDocumentRejectsDangerousUrlScheme(): void
    {
        $document = self::decodeSourceFile('rite/roman/calendars/nations/IT/IT.json');
        $schema   = Schema::import(LitSchema::NATIONAL->path());

        // The shipped document must still validate as-is.
        $schema->in($document);

        $mutated = self::decodeSourceFile('rite/roman/calendars/nations/IT/IT.json');
        $this->assertIsArray($mutated->litcal);

        $mutatedCount = 0;
        foreach ($mutated->litcal as $litCalItem) {
            if ($litCalItem instanceof \stdClass && isset($litCalItem->metadata->url)) {
                $litCalItem->metadata->url = self::DANGEROUS_URL;
                ++$mutatedCount;
            }
        }
        $this->assertGreaterThan(0, $mutatedCount, 'Expected the Italian national calendar to declare at least one metadata.url');

        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        $schema->in($mutated);
    }

    private static function decodeSourceFile(string $relativePath): \stdClass
    {
        $path     = JsonData::SOURCEDATA_FOLDER->path() . '/' . $relativePath;
        $contents = file_get_contents($path);
        self::assertNotFalse($contents, "Unable to read source file: $relativePath");

        $decoded = json_decode($contents);
        self::assertInstanceOf(\stdClass::class, $decoded, "Unable to decode source file: $relativePath");

        return $decoded;
    }

    /**
     * Import a single sub-schema of a source-data schema file, addressed by JSON pointer.
     */
    private static function importSubSchema(LitSchema $litSchema, string $pointer): Schema
    {
        self::initRouter();

        $path = realpath($litSchema->path());
        self::assertNotFalse($path, 'Unable to resolve schema path for ' . $litSchema->name);

        $schema = Schema::import('file://' . $path . '#' . $pointer);
        self::assertInstanceOf(Schema::class, $schema);

        return $schema;
    }
}
