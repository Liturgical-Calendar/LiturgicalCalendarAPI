<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Tests\Support\GoldenMaster;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Re-runs the frozen Roman-calendar golden-master matrix against the live
 * CalendarHandler and asserts byte-identical normalized output. This is the
 * behaviour-preservation gate the Ambrosian Rite strategy extraction
 * (Tasks 4-5) must keep passing after every change.
 *
 * Unlike CalendarGoldenMasterGenerateTest (which writes the fixtures), this
 * test only reads them and must never modify phpunit_tests/fixtures/.
 */
final class CalendarGoldenMasterTest extends AbstractHandlerTestCase
{
    /**
     * @return iterable<string, array{0: array{
     *     label: string,
     *     method: string,
     *     uri: string,
     *     headers: array<string,string>,
     *     pathParams: array<int,string>
     * }}>
     */
    public static function caseProvider(): iterable
    {
        foreach (GoldenMaster::MATRIX as $case) {
            yield $case['label'] => [$case];
        }
    }

    /**
     * @param array{
     *     label: string,
     *     method: string,
     *     uri: string,
     *     headers: array<string,string>,
     *     pathParams: array<int,string>
     * } $case
     */
    #[DataProvider('caseProvider')]
    public function testMatchesFixture(array $case): void
    {
        $fixture = GoldenMaster::fixturePath($case['label']);
        self::assertFileExists($fixture, "Missing fixture for {$case['label']}; run the generate test first.");

        $handler = new CalendarHandler($case['pathParams']);
        $handler->setAllowedReturnTypes([
            ReturnTypeParam::JSON,
            ReturnTypeParam::YAML,
            ReturnTypeParam::XML,
            ReturnTypeParam::ICS,
        ]);
        $request  = $this->requestFor($case['method'], $case['uri'], $case['headers']);
        $response = $handler->handle($request);
        self::assertSame(200, $response->getStatusCode(), $case['label']);

        $actual   = GoldenMaster::normalize($this->decodeJsonBody($response));
        $expected = json_decode((string) file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);

        self::assertEquals($expected, $actual, "Golden-master drift for {$case['label']}");
    }
}
