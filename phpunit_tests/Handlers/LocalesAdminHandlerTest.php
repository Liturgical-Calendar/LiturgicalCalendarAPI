<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\Admin\LocalesAdminHandler;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Services\SupportedLocales;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalesAdminHandler::class)]
final class LocalesAdminHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        SupportedLocales::reset();
    }

    /** @param array<string, mixed>|null $oidcUser */
    private function request(string $path, ?array $oidcUser): ServerRequest
    {
        $request = ( new ServerRequest('GET', $path) )->withHeader('Accept', 'application/json');

        return $oidcUser === null ? $request : $request->withAttribute('oidc_user', $oidcUser);
    }

    /** @return array<string, mixed> */
    private function globalAdmin(): array
    {
        return ['sub' => 'admin-1', 'roles' => ['admin']];
    }

    /** @param string[] $pathParams @return array<string, mixed> */
    private function json(array $pathParams, string $path, ?array $oidcUser): array
    {
        $response = ( new LocalesAdminHandler($pathParams) )->handle($this->request($path, $oidcUser));
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true);

        return $decoded;
    }

    public function testAnUnauthenticatedCallerIsRejected(): void
    {
        $this->expectException(UnauthorizedException::class);

        ( new LocalesAdminHandler(['locales']) )->handle($this->request('/admin/locales', null));
    }

    public function testANonAdminIsForbidden(): void
    {
        $this->expectException(ForbiddenException::class);

        ( new LocalesAdminHandler(['locales']) )
            ->handle($this->request('/admin/locales', ['sub' => 'editor-1', 'roles' => ['calendar_editor']]));
    }

    public function testTheListNamesEveryCandidateAndFlagsTheOfficialOnes(): void
    {
        $body = $this->json(['locales'], '/admin/locales', $this->globalAdmin());

        self::assertSame(SupportedLocales::official(), $body['official']);
        self::assertNotEmpty($body['candidates']);

        $byLocale = array_column($body['candidates'], null, 'locale');
        self::assertTrue($byLocale['en']['official']);
        self::assertTrue($byLocale['en']['ready']);
        self::assertFalse($byLocale['hr']['official']);
    }

    /**
     * An operator seeing a green "ready" must be told why there is no promote
     * button, rather than being left to wonder.
     */
    public function testTheListExplainsWhyCurationIsNotWritableYet(): void
    {
        $body = $this->json(['locales'], '/admin/locales', $this->globalAdmin());

        self::assertFalse($body['curation']['writable']);
        self::assertStringContainsString('#902', $body['curation']['reason']);
    }

    public function testASingleLocaleReturnsItsFullReport(): void
    {
        $body = $this->json(['locales', 'hr'], '/admin/locales/hr', $this->globalAdmin());

        self::assertSame('hr', $body['locale']);
        self::assertFalse($body['official']);
        self::assertFalse($body['ready']);
        self::assertNotEmpty($body['checks']);
    }

    public function testAnOfficialLocaleReportsReady(): void
    {
        $body = $this->json(['locales', 'la'], '/admin/locales/la', $this->globalAdmin());

        self::assertTrue($body['official']);
        self::assertTrue($body['ready']);
    }

    public function testALocaleWithNoResourcesIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        ( new LocalesAdminHandler(['locales', 'zz']) )
            ->handle($this->request('/admin/locales/zz', $this->globalAdmin()));
    }
}
