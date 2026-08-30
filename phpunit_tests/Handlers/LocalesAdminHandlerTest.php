<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\Admin\LocalesAdminHandler;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Services\SupportedLocales;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(LocalesAdminHandler::class)]
final class LocalesAdminHandlerTest extends AbstractHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SupportedLocales::reset();
    }

    /** @param array<string, mixed>|null $oidcUser */
    private function request(string $path, ?array $oidcUser): ServerRequestInterface
    {
        $request = $this->requestFor('GET', $path);

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
        return $this->decodeJsonBody(
            ( new LocalesAdminHandler($pathParams) )->handle($this->request($path, $oidcUser))
        );
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
     * `curation` is derived from the deployment's actual write mode, not hardcoded. This
     * asserts the invariant that holds in every mode — the only thing this suite can say
     * without dictating the environment it runs in; `LocalesAdminCurationTest` forces each
     * mode in turn and pins its exact prose.
     *
     * The old assertion here was `writable === false` and a reason naming #902, which had
     * already shipped: a constant that had quietly become a lie.
     */
    public function testTheListReportsTheRealCurationState(): void
    {
        $body = $this->json(['locales'], '/admin/locales', $this->globalAdmin());

        self::assertContains($body['curation']['mode'], ['change_request', 'disk', 'misconfigured']);
        self::assertSame(
            $body['curation']['mode'] !== 'misconfigured',
            $body['curation']['writable'],
            'writable must follow the mode, never be asserted independently of it'
        );
        // The frontend renders this verbatim, so it must read as prose in every branch.
        self::assertNotSame('', $body['curation']['reason']);
        self::assertStringNotContainsString('#902', $body['curation']['reason']);
    }

    /**
     * `/admin/locales/{locale}/promote` is a POST route. A GET on it must not be answered
     * as a readiness report for a locale called "promote".
     */
    public function testAGetOnACurationPathIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        ( new LocalesAdminHandler(['locales', 'hr', 'promote']) )
            ->handle($this->request('/admin/locales/hr/promote', $this->globalAdmin()));
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
