<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http\Middleware;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Http\Middleware\OpenFgaAuthorizationMiddleware;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\RiteScopedObjectId;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;

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
     * Pins RiteScopedObjectId's own contract — used elsewhere for calendar-naming types (issue
     * #786) and by forMissals()'s national-edition branch, but deliberately NOT by its
     * typical-edition branch: see testARomanTypicalEditionStaysBareOnGeneralRomanCalendar()
     * below for why a missal id is not one of the ids this class needs to disambiguate.
     *
     * Named for what THIS test exercises — the general-purpose `qualify()` utility, called
     * directly — not for how a typical-edition missal id is actually treated in practice, which
     * is the opposite: forMissals() deliberately keeps that id bare (see the test just named).
     */
    public function testRiteScopedObjectIdQualifyPrependsTheRite(): void
    {
        self::assertSame('roman/EDITIO_TYPICA_1970', RiteScopedObjectId::qualify(Rite::ROMAN, 'EDITIO_TYPICA_1970'));
        self::assertSame('ambrosian/EDITIO_TYPICA_2024', RiteScopedObjectId::qualify(Rite::AMBROSIAN, 'EDITIO_TYPICA_2024'));
    }

    public function testAQualifiedIdRoundTrips(): void
    {
        $parsed = RiteScopedObjectId::parse('ambrosian/EDITIO_TYPICA_2024');
        self::assertNotNull($parsed);
        self::assertSame(Rite::AMBROSIAN, $parsed[0]);
        self::assertSame('EDITIO_TYPICA_2024', $parsed[1]);
    }

    /**
     * The two tests above exercise only RiteScopedObjectId, which predates this task. They pin a
     * contract forMissals() partly depends on (its national-edition branch) but never invoke the
     * changed method itself. These three do: they drive
     * OpenFgaAuthorizationMiddleware::forMissals() through process() and assert, via the mocked
     * OpenFgaClient::check() call, the exact [type, id] pair it produced.
     *
     * Missal ids are unique across every rite (MissalCatalogTest::testTheRitesDoNotShareIds), so
     * — unlike a nation or diocese code — a typical edition's id needs no rite qualifier and
     * stays bare, exactly like `temporale` and `decrees` on the same general_roman_calendar type
     * (issue #953; see AccessRequestRepository::GRC_OBJECT_IDS, which enumerates it that way).
     */
    public function testARomanTypicalEditionStaysBareOnGeneralRomanCalendar(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects(self::once())
            ->method('check')
            ->with(self::anything(), self::anything(), 'general_roman_calendar:EDITIO_TYPICA_1970')
            ->willReturn(true);

        $middleware = OpenFgaAuthorizationMiddleware::forMissals($client, 'EDITIO_TYPICA_1970', Rite::ROMAN);
        $request    = ( new ServerRequest('PATCH', '/missals/EDITIO_TYPICA_1970') )
            ->withAttribute('oidc_user', ['sub' => 'abc', 'roles' => ['calendar_editor']]);

        $response = $middleware->process($request, $this->nextHandler);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testTheAmbrosianTypicalEditionAlsoStaysBareOnGeneralRomanCalendar(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects(self::once())
            ->method('check')
            ->with(self::anything(), self::anything(), 'general_roman_calendar:EDITIO_TYPICA_2024')
            ->willReturn(true);

        $middleware = OpenFgaAuthorizationMiddleware::forMissals($client, 'EDITIO_TYPICA_2024', Rite::AMBROSIAN);
        $request    = ( new ServerRequest('PATCH', '/missals/ambrosian/EDITIO_TYPICA_2024') )
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

    /**
     * ChangeResource::missal()'s own docblock says it MUST mirror forMissals() exactly: the
     * middleware decides whether the caller MAY write, ChangeResource decides what the recorded
     * proposal is ABOUT, and a reviewer later checks permissions against ChangeResource's id. A
     * fix-round defect in an earlier draft of this task rite-qualified forMissals() without
     * updating ChangeResource::missal() to match, which would have made the two silently
     * disagree for every typical edition. Nothing enforced the mirror — this does, for all three
     * shapes forMissals() can produce: Roman typical, Ambrosian typical, Roman national.
     *
     * @return list<array{0: string, 1: Rite}>
     */
    public static function missalIdAndRiteProvider(): array
    {
        return [
            'Roman typical edition'     => ['EDITIO_TYPICA_1970', Rite::ROMAN],
            'Ambrosian typical edition' => ['EDITIO_TYPICA_2024', Rite::AMBROSIAN],
            'Roman national edition'    => ['US_2011', Rite::ROMAN],
        ];
    }

    #[DataProvider('missalIdAndRiteProvider')]
    public function testForMissalsAndChangeResourceMissalAgreeOnTheSameObject(string $missalId, Rite $rite): void
    {
        $client     = $this->createStub(OpenFgaClient::class);
        $middleware = OpenFgaAuthorizationMiddleware::forMissals($client, $missalId, $rite);

        $reflected  = new ReflectionClass($middleware);
        $objectType = $reflected->getProperty('objectType')->getValue($middleware);
        $objectId   = $reflected->getProperty('fixedObjectId')->getValue($middleware);

        $resource = ChangeResource::missal($missalId, $rite);

        self::assertSame($objectType, $resource->type, 'forMissals() and ChangeResource::missal() must agree on the object TYPE');
        self::assertSame($objectId, $resource->id, 'forMissals() and ChangeResource::missal() must agree on the object ID');
    }
}
