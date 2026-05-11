<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\ApplicationsHandler;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Repositories\ApiKeyRepository;
use LiturgicalCalendar\Api\Repositories\ApplicationRepository;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ApplicationsHandler::class)]
final class ApplicationsHandlerTest extends AbstractHandlerTestCase
{
    protected static bool $requiresDatabase = true;

    private function devUser(string $sub = 'dev-user-1'): array
    {
        return ['sub' => $sub, 'roles' => ['developer']];
    }

    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new ApplicationsHandler() )->handle(
            $this->requestFor('OPTIONS', '/applications', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testPutIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new ApplicationsHandler() )->handle(
            $this->requestFor('PUT', '/applications', [])
                ->withAttribute('oidc_user', $this->devUser())
        );
    }

    public function testMissingOidcUserIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        ( new ApplicationsHandler() )->handle($this->requestFor('GET', '/applications'));
    }

    public function testNonDeveloperIsForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        ( new ApplicationsHandler() )->handle(
            $this->requestFor('GET', '/applications')
                ->withAttribute('oidc_user', ['sub' => 'viewer', 'roles' => ['viewer']])
        );
    }

    public function testListReturnsOnlyUsersOwnApplications(): void
    {
        $repo = new ApplicationRepository(self::$pdo);
        $repo->create('dev-user-1', 'Mine');
        $repo->create('other-user', 'Theirs');

        $response = ( new ApplicationsHandler() )->handle(
            $this->requestFor('GET', '/applications')
                ->withAttribute('oidc_user', $this->devUser())
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertSame(1, $body['total']);
        self::assertSame('Mine', $body['applications'][0]['name']);
        self::assertArrayHasKey('uuid', $body['applications'][0]);
        self::assertSame(0, $body['applications'][0]['key_count']);
    }

    public function testCreateRequiresName(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('name is required');

        ( new ApplicationsHandler() )->handle(
            $this->requestFor('POST', '/applications', [], ['description' => 'no name'])
                ->withAttribute('oidc_user', $this->devUser())
        );
    }

    public function testCreateHappyPath(): void
    {
        $response = ( new ApplicationsHandler() )->handle(
            $this->requestFor('POST', '/applications', [], [
                'name'        => 'My App',
                'description' => 'desc',
                'website'     => 'https://example.test',
            ])->withAttribute('oidc_user', $this->devUser())
        );

        self::assertSame(201, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertSame('My App', $body['application']['name']);
        self::assertSame('pending', $body['application']['status']);
    }

    public function testGetApplicationByUuidRequiresOwnership(): void
    {
        $repo = new ApplicationRepository(self::$pdo);
        $row  = $repo->create('other-user', 'Theirs');
        /** @var string $id */
        $id = $row['id'];

        // Handler differentiates "not yours" from "doesn't exist" by emitting
        // ForbiddenException when the row is found but owned by someone else.
        $this->expectException(ForbiddenException::class);
        ( new ApplicationsHandler() )->handle(
            $this->requestFor('GET', '/applications/' . $id)
                ->withAttribute('oidc_user', $this->devUser())
        );
    }

    public function testInvalidPathIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new ApplicationsHandler() )->handle(
            $this->requestFor('GET', '/no-applications-here')
                ->withAttribute('oidc_user', $this->devUser())
        );
    }

    public function testListApiKeysForOwnedApprovedApp(): void
    {
        $appRepo = new ApplicationRepository(self::$pdo);
        $row     = $appRepo->create('dev-user-1', 'X');
        /** @var string $appId */
        $appId = $row['id'];
        $appRepo->approveApplication($appId, 'admin');

        $keyRepo = new ApiKeyRepository(self::$pdo);
        $keyRepo->generate($appId, 'k1');

        $response = ( new ApplicationsHandler() )->handle(
            $this->requestFor('GET', '/applications/' . $appId . '/keys')
                ->withAttribute('oidc_user', $this->devUser())
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertCount(1, $body['keys']);
        self::assertSame('k1', $body['keys'][0]['name']);
    }
}
