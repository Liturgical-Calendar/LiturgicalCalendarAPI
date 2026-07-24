<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Tests\Support\GoldenMaster;

/**
 * Regression for issue #739: CalendarHandler::prepareL10N() leaks LANGUAGE across
 * requests. A Latin (default) calendar request that follows a translated request in
 * the same process must still emit Latin/English message templates, not the previous
 * request's language — the situation a persistent PHP_CLI_SERVER worker creates when
 * two consecutive requests both miss the response cache.
 */
final class CalendarHandlerLocaleLeakTest extends AbstractHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Force Router::isLocalhost() true so handle() bypasses the response cache and
        // actually runs prepareL10N() for every request (the leak is invisible when a
        // request is served from ServerCache).
        $_SERVER['SERVER_NAME'] = 'localhost';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['SERVER_NAME']);
        // Never let this test's deliberate locale pollution leak into later tests.
        setlocale(LC_ALL, 'C');
        putenv('LANGUAGE');
        parent::tearDown();
    }

    public function testLatinCalendarAfterItalianRequestKeepsLatinMessages(): void
    {
        $returnTypes = [ReturnTypeParam::JSON, ReturnTypeParam::YAML, ReturnTypeParam::XML, ReturnTypeParam::ICS];

        // 1) A translated (Italian) request first — prepareL10N sets setlocale(it_IT)
        //    and putenv(LANGUAGE=it_IT:...).
        $itHandler = new CalendarHandler(['nation', 'IT', '2024']);
        $itHandler->setAllowedReturnTypes($returnTypes);
        $itResponse = $itHandler->handle($this->requestFor('GET', '/calendar/nation/IT/2024', ['Accept' => 'application/json', 'Accept-Language' => 'it']));
        self::assertSame(200, $itResponse->getStatusCode());

        // 2) Then the General (Latin) calendar in the SAME process.
        $latinHandler = new CalendarHandler(['2024']);
        $latinHandler->setAllowedReturnTypes($returnTypes);
        $latinResponse = $latinHandler->handle($this->requestFor('GET', '/calendar/2024', ['Accept' => 'application/json']));
        self::assertSame(200, $latinResponse->getStatusCode());

        // Strongest possible check: the Latin calendar must be byte-identical to its
        // frozen golden master. If LANGUAGE leaked from the Italian request, the
        // gettext-translated message templates come back Italian and this diverges.
        $actual   = GoldenMaster::normalize($this->decodeJsonBody($latinResponse));
        $expected = json_decode((string) file_get_contents(GoldenMaster::fixturePath('general-2024')), true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals($expected, $actual, 'Latin calendar leaked the previous request language (#739).');
    }
}
