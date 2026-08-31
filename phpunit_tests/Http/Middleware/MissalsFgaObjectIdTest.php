<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http\Middleware;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Http\Middleware\OpenFgaAuthorizationMiddleware;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\RiteScopedObjectId;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MissalsFgaObjectIdTest extends TestCase
{
    private RequestHandlerInterface $nextHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nextHandler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    /**
     * The ids are interim: #955 generalises general_roman_calendar into a rite-level tier. The
     * rite qualifier is the part that survives that work, which is why it is introduced now.
     */
    public function testATypicalEditionIsQualifiedByItsRite(): void
    {
        self::assertSame('roman/EDITIO_TYPICA_1970', RiteScopedObjectId::qualify(Rite::ROMAN, 'EDITIO_TYPICA_1970'));
        self::assertSame('ambrosian/EDITIO_2024', RiteScopedObjectId::qualify(Rite::AMBROSIAN, 'EDITIO_2024'));
    }

    public function testAQualifiedIdRoundTrips(): void
    {
        $parsed = RiteScopedObjectId::parse('ambrosian/EDITIO_2024');
        self::assertNotNull($parsed);
        self::assertSame(Rite::AMBROSIAN, $parsed[0]);
        self::assertSame('EDITIO_2024', $parsed[1]);
    }

    /**
     * The tests above exercise only RiteScopedObjectId, which predates this task. They pin the
     * contract forMissals() depends on but never invoke the changed method itself. These three
     * do: they drive OpenFgaAuthorizationMiddleware::forMissals() through process() and assert,
     * via the mocked OpenFgaClient::check() call, the exact [type, id] pair it produced.
     */
    public function testARomanTypicalEditionProducesARiteQualifiedGeneralRomanCalendarObject(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects(self::once())
            ->method('check')
            ->with(self::anything(), self::anything(), 'general_roman_calendar:roman/EDITIO_TYPICA_1970')
            ->willReturn(true);

        $middleware = OpenFgaAuthorizationMiddleware::forMissals($client, 'EDITIO_TYPICA_1970', Rite::ROMAN);
        $request    = ( new ServerRequest('PATCH', '/missals/EDITIO_TYPICA_1970') )
            ->withAttribute('oidc_user', ['sub' => 'abc', 'roles' => ['calendar_editor']]);

        $response = $middleware->process($request, $this->nextHandler);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testTheAmbrosianTypicalEditionProducesARiteQualifiedGeneralRomanCalendarObject(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects(self::once())
            ->method('check')
            ->with(self::anything(), self::anything(), 'general_roman_calendar:ambrosian/EDITIO_2024')
            ->willReturn(true);

        $middleware = OpenFgaAuthorizationMiddleware::forMissals($client, 'EDITIO_2024', Rite::AMBROSIAN);
        $request    = ( new ServerRequest('PATCH', '/missals/ambrosian/EDITIO_2024') )
            ->withAttribute('oidc_user', ['sub' => 'abc', 'roles' => ['calendar_editor']]);

        $response = $middleware->process($request, $this->nextHandler);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testARomanNationalEditionProducesARiteQualifiedNationalCalendarObject(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects(self::once())
            ->method('check')
            ->with(self::anything(), self::anything(), 'national_calendar:roman/US')
            ->willReturn(true);

        $middleware = OpenFgaAuthorizationMiddleware::forMissals($client, 'US_2011', Rite::ROMAN);
        $request    = ( new ServerRequest('PUT', '/missals/US_2011') )
            ->withAttribute('oidc_user', ['sub' => 'abc', 'roles' => ['calendar_editor']]);

        $response = $middleware->process($request, $this->nextHandler);
        self::assertSame(200, $response->getStatusCode());
    }
}
