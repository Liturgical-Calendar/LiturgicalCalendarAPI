<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Admin;

use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Services\Locale\LocaleReadinessChecker;
use LiturgicalCalendar\Api\Services\SupportedLocales;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Curation surface for the officially supported locales (#904).
 *
 * - `GET /admin/locales`            — every candidate locale, official flag, readiness
 * - `GET /admin/locales/{locale}`   — the full readiness report for one locale
 *
 * Read-only by design, for now. Promotion writes `jsondata/supportedLocales.json`
 * on the deployed server, which the next deploy would overwrite — the problem
 * tracked in #902. Until change requests can carry that write, promotion stays a
 * reviewed pull request, and this endpoint exists to tell an operator whether
 * such a pull request would be safe to open.
 *
 * Restricted to global (Zitadel) admins: this is a governance decision about the
 * API's published contract, not a per-resource one, so resource-admin scopes do
 * not apply.
 */
final class LocalesAdminHandler extends AbstractHandler
{
    private ?LocaleReadinessChecker $checker;

    /**
     * @param string[] $requestPathParams
     */
    public function __construct(array $requestPathParams = [], ?LocaleReadinessChecker $checker = null)
    {
        parent::__construct($requestPathParams);

        $this->checker               = $checker;
        $this->allowedRequestMethods = [RequestMethod::GET];
        $this->allowedAcceptHeaders  = [AcceptHeader::JSON];
        $this->allowCredentials      = true;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = static::initResponse($request);
        $method   = RequestMethod::from($request->getMethod());

        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        }

        $response = $this->setAccessControlAllowOriginHeader($request, $response);
        $this->validateRequestMethod($request);

        $mime     = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
        $response = $response->withHeader('Content-Type', $mime)->withHeader('Cache-Control', 'no-store');

        /** @var array{sub?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');
        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }

        if (false === OidcAuthMiddleware::isAdmin($oidcUser)) {
            throw new ForbiddenException('Global admin role required to curate supported locales');
        }

        $locale = $this->requestedLocale();

        return $this->encodeResponseBody(
            $response,
            null === $locale ? $this->listPayload() : $this->reportPayload($locale)
        );
    }

    /**
     * The `{locale}` segment of `/admin/locales/{locale}`, when present.
     *
     * `requestPathParams` carries the segments after `/admin`, so index 0 is
     * `locales` itself.
     */
    private function requestedLocale(): ?string
    {
        $segment = $this->requestPathParams[1] ?? null;

        return is_string($segment) && $segment !== '' ? $segment : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayload(): array
    {
        $checker = $this->checker();
        $reports = array_map(
            static fn (string $locale): array => [
                'locale'   => $locale,
                'official' => SupportedLocales::isOfficial($locale),
                'ready'    => $checker->check($locale)->ready(),
                'summary'  => $checker->check($locale)->describe(),
            ],
            $checker->knownLocales()
        );

        return [
            'official'   => SupportedLocales::official(),
            'candidates' => $reports,
            // Surfaced rather than left implicit: an operator who sees a green
            // "ready" here should know why there is no promote button yet.
            'curation'   => [
                'writable' => false,
                'reason'   => 'Promotion writes jsondata/supportedLocales.json, which the next deploy would overwrite (see issue #902). Promote by opening a pull request against that file.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportPayload(string $locale): array
    {
        $checker = $this->checker();

        if (false === in_array($locale, $checker->knownLocales(), true)) {
            throw new NotFoundException(sprintf('No resources found for locale "%s"', $locale));
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) json_encode($checker->check($locale)), true);

        return $payload;
    }

    private function checker(): LocaleReadinessChecker
    {
        return $this->checker ??= new LocaleReadinessChecker();
    }
}
