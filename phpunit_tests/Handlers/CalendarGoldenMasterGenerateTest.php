<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Tests\Support\GoldenMaster;
use PHPUnit\Framework\Attributes\Group;

/**
 * Writes the Roman-calendar golden-master fixtures under
 * phpunit_tests/fixtures/golden-master/. This is a fixture generator, not a
 * regression check: it runs against the current (unmodified) CalendarHandler
 * and freezes its output as the behaviour-preservation baseline for the
 * upcoming Rite strategy extraction. The regression test that diffs future
 * runs against these fixtures lives separately (see the Ambrosian Rite plan).
 *
 * Regenerating twice must produce byte-identical fixtures — that's what
 * proves GoldenMaster::normalize() has stripped every volatile field.
 */
#[Group('golden-master-generate')]
final class CalendarGoldenMasterGenerateTest extends AbstractHandlerTestCase
{
    public function testWriteFixtures(): void
    {
        // Defensive require: Support\GoldenMaster was added alongside this
        // test class, so a Composer classmap regenerated before it existed
        // may not resolve the PSR-4 lookup yet. Idempotent no-op once the
        // classmap is current.
        require_once __DIR__ . '/../Support/GoldenMaster.php';

        $dir = dirname(GoldenMaster::fixturePath('x'));
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        foreach (GoldenMaster::MATRIX as $case) {
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

            $decoded    = $this->decodeJsonBody($response);
            $normalized = GoldenMaster::normalize($decoded);
            file_put_contents(
                GoldenMaster::fixturePath($case['label']),
                json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"
            );
        }

        self::assertCount(count(GoldenMaster::MATRIX), glob($dir . '/*.json') ?: []);
    }
}
