<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\Admin\LocalesAdminHandler;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Services\Locale\LocaleReadinessChecker;
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
        // Every candidate's flag agrees with the official list, rather than naming a locale
        // that is unofficial today and would break this the day it is promoted. Asked through
        // the same predicate the handler uses: a regional catalogue such as `pt_BR` counts as
        // official when its language is, which list membership alone would not say.
        foreach ($body['candidates'] as $candidate) {
            self::assertSame(
                SupportedLocales::isOfficial($candidate['locale']),
                $candidate['official'],
                "{$candidate['locale']}: the official flag must follow the official list"
            );
        }
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
        // Not a hardcoded verdict: whether a real locale is ready is a fact about the
        // translation data, which every sync can change (LocaleReadinessCheckerTest pins
        // verdicts against fixtures). What the handler owes is to report the checker's
        // verdict and the official list faithfully, whatever they are today.
        $body   = $this->json(['locales', 'hr'], '/admin/locales/hr', $this->globalAdmin());
        $report = ( new LocaleReadinessChecker() )->check('hr');

        self::assertSame('hr', $body['locale']);
        self::assertSame(SupportedLocales::isOfficial('hr'), $body['official']);
        self::assertSame($report->ready(), $body['ready']);
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
