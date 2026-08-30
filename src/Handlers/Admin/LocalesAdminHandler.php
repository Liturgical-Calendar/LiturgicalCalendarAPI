<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Admin;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Handlers\Concerns\ResolvesFgaClient;
use LiturgicalCalendar\Api\Handlers\Concerns\WritesSourceData;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\Locale\LocaleReadinessChecker;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode;
use LiturgicalCalendar\Api\Services\SupportedLocales;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Curation surface for the officially supported locales (#904, #926).
 *
 * - `GET  /admin/locales`                   — every candidate locale, official flag, readiness
 * - `GET  /admin/locales/{locale}`           — the full readiness report for one locale
 * - `POST /admin/locales/{locale}/promote`   — add the locale to the official set
 * - `POST /admin/locales/{locale}/demote`    — remove it again
 *
 * **Where a promotion goes.** Through {@see WritesSourceData}, exactly like every other
 * source-data write: a reviewable change request where queue mode is configured, a direct
 * disk write where it is not. That is what #902 was waiting for, and it is why the endpoint
 * no longer has to tell operators to open a pull request by hand.
 *
 * **What a promotion is gated on, and what it is not.** Promotion is not a label — it
 * changes behaviour. `ReadingsMap::getReadings()` throws for an official locale and degrades
 * quietly for every other one, so promoting a locale whose data is incomplete converts silent
 * gaps into 500s on a calendar the API advertises. {@see LocaleReadinessChecker} is the
 * pre-flight for exactly that, and it is **not bypassable**: there is no `force` parameter and
 * no role that skips it. If the data is complete the checker says so; if the checker is wrong
 * the fix is to the checker, not a flag that routes around it.
 *
 * Demotion is deliberately NOT readiness-gated, and the asymmetry is the point. Removing a
 * locale from the official set LOOSENS enforcement — the same missing readings that would
 * have thrown now degrade quietly — so it can never turn a working calendar into a 500. What
 * it can do is silently change published output: `/calendars` stops advertising the locale and
 * affected events start serving nothing instead of erroring. That is a governance risk, not a
 * data-integrity one, so the control that applies to it is review (the change request and its
 * audit trail), not a readiness probe. Two integrity guards do still apply: the locale must
 * actually be official, and the last official locale may not be removed — an empty `official`
 * list is indistinguishable from an unreadable file, and {@see SupportedLocales::official()}
 * would silently substitute its built-in fallback for it.
 *
 * **Reading before writing.** `supportedLocales.json` is an AGGREGATE file — one file holding
 * the whole official set plus every candidate note — so the current content is read through
 * {@see WritesSourceData::unpublishedSourceContent()} rather than straight off disk. In queue
 * mode a submitter's previous promotion never reaches disk, and rebuilding from disk would
 * silently drop it.
 *
 * Restricted to global (Zitadel) admins: which locales the API declares supported is a
 * governance decision about its published contract, not a per-resource one, so resource-admin
 * scopes do not open the endpoint. They do decide what happens to a submission once it is
 * made: a caller holding `admin` on `general_roman_calendar:supported_locales` has their change
 * auto-approved, and everyone else's waits for a reviewer — the ordinary change-request rule.
 */
final class LocalesAdminHandler extends AbstractHandler
{
    use ResolvesFgaClient;
    use WritesSourceData;

    private const ACTION_PROMOTE = 'promote';

    private const ACTION_DEMOTE = 'demote';

    /** The one calendar that curates a supported-locale set. Mirrors the resource's own top-level key. */
    private const CALENDAR_KEY = 'general_roman_calendar';

    private ?LocaleReadinessChecker $checker;

    private ?LoggerInterface $auditLogger = null;

    /**
     * @param string[] $requestPathParams
     */
    public function __construct(array $requestPathParams = [], ?LocaleReadinessChecker $checker = null)
    {
        parent::__construct($requestPathParams);

        $this->checker               = $checker;
        $this->allowedRequestMethods = [RequestMethod::GET, RequestMethod::POST];
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

        if ($method === RequestMethod::POST) {
            // Captured before any staging, the same way every other write handler does it:
            // the change request's author is the authenticated identity of this request.
            $this->captureSubmitter($request);

            return $this->encodeResponseBody($response, $this->curate());
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
        if (count($this->requestPathParams) > 2) {
            // `/admin/locales/{locale}/promote` is a POST route; a GET on it is not a
            // readiness report for a locale called "promote".
            throw new NotFoundException('The readiness report path is /admin/locales/{locale}');
        }

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
            'curation'   => $this->curationState(),
        ];
    }

    /**
     * Whether curation is available here, and what a write would actually do.
     *
     * Computed rather than constant. `writable` answers the question the admin interface
     * asks — "will a promote/demote from this caller be accepted?" — and `mode` says what
     * accepting it means, because a change recorded as a reviewable request and a change
     * written straight to a file that the next deploy may overwrite are not the same
     * promise. The frontend renders `reason` verbatim, so it must read as prose to a human
     * in every branch, not only the refusing one.
     *
     * @return array{writable: bool, mode: string, reason: string}
     */
    private function curationState(): array
    {
        if (SourceDataWriteMode::isMisconfigured()) {
            return [
                'writable' => false,
                'mode'     => 'misconfigured',
                'reason'   => 'SOURCEDATA_CHANGE_REQUESTS is set, but Postgres and/or OpenFGA are not both reachable. '
                    . 'A promotion here would silently fall back to writing jsondata/supportedLocales.json on the very '
                    . 'deployment that asked for durable, reviewable edits, and the next deploy would revert it with no '
                    . 'trace. Curation is refused until the stack is reachable again.',
            ];
        }

        if (SourceDataWriteMode::changeRequestsEnabled()) {
            return [
                'writable' => true,
                'mode'     => 'change_request',
                'reason'   => 'A promotion or demotion is recorded as a reviewable change request against '
                    . 'jsondata/supportedLocales.json. It is approved immediately if you administer '
                    . 'general_roman_calendar:supported_locales, and waits for a reviewer otherwise.',
            ];
        }

        return [
            'writable' => true,
            'mode'     => 'disk',
            'reason'   => 'A promotion or demotion is written straight to jsondata/supportedLocales.json. '
                . 'A deployment that syncs its tree from git will overwrite the edit on the next deploy; set '
                . 'SOURCEDATA_CHANGE_REQUESTS to record curation as reviewable change requests instead.',
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

    /**
     * `POST /admin/locales/{locale}/{promote|demote}`.
     *
     * @return array<string, mixed>
     */
    private function curate(): array
    {
        if (count($this->requestPathParams) !== 3) {
            throw new NotFoundException(
                'Curation requires POST /admin/locales/{locale}/promote or POST /admin/locales/{locale}/demote'
            );
        }

        $locale = $this->requestPathParams[1];
        $action = $this->requestPathParams[2];

        if (false === in_array($action, [self::ACTION_PROMOTE, self::ACTION_DEMOTE], true)) {
            throw new NotFoundException(sprintf(
                'Unknown curation action "%s"; expected %s or %s',
                $action,
                self::ACTION_PROMOTE,
                self::ACTION_DEMOTE
            ));
        }

        if (SourceDataWriteMode::isMisconfigured()) {
            // Fail closed rather than falling back to disk, unlike the calendar write
            // handlers. They tolerate the fallback because refusing would stop calendar
            // editing outright; promotion is a rare governance action with an understood
            // manual alternative, so a silently-reverted edit is the worse outcome here.
            throw new ServiceUnavailableException($this->curationState()['reason']);
        }

        $resource = $this->readCuratedResource();
        $official = $this->officialFrom($resource);

        $official = $action === self::ACTION_PROMOTE
            ? $this->promoted($locale, $official)
            : $this->demoted($locale, $official);

        $set             = $resource[self::CALENDAR_KEY];
        $set['official'] = $official;

        if ($action === self::ACTION_PROMOTE && isset($set['candidates']) && is_array($set['candidates'])) {
            // A promoted locale is no longer a candidate, and the prose reason it was not
            // yet official is now false. Leaving it would make the file contradict itself.
            unset($set['candidates'][$locale]);
            if ($set['candidates'] === []) {
                unset($set['candidates']);
            }
        }

        $resource[self::CALENDAR_KEY] = $set;

        $path = JsonData::SUPPORTED_LOCALES_FILE->path();
        $this->stageFile($path, ChangeOperation::UPDATE, self::encode($resource));
        $result = $this->commitStagedFiles(ChangeResource::supportedLocales());

        // The memo is per process and would otherwise keep serving the pre-write list to
        // everything else in this request — including the payload assembled just below.
        SupportedLocales::reset();

        $this->auditLogger()->info('Supported locale curated', [
            'operation'   => strtoupper($action),
            'resource'    => 'supported_locales',
            'locale'      => $locale,
            'official'    => $official,
            'disposition' => $result['disposition'] ?? null,
        ]);

        return array_merge(
            [
                'locale'   => $locale,
                'action'   => $action,
                'official' => $official,
            ],
            $result
        );
    }

    /**
     * The official set with `$locale` added, or a refusal explaining why it may not be.
     *
     * @param list<string> $official
     * @return list<string>
     */
    private function promoted(string $locale, array $official): array
    {
        $checker = $this->checker();

        if (false === in_array($locale, $checker->knownLocales(), true)) {
            throw new NotFoundException(sprintf('No resources found for locale "%s"', $locale));
        }

        if (in_array($locale, $official, true)) {
            throw new ConflictException(sprintf('Locale "%s" is already officially supported', $locale));
        }

        $report = $checker->check($locale);
        if (false === $report->ready()) {
            throw new UnprocessableContentException(sprintf(
                'Locale "%s" is not ready to be promoted: %s. An official locale is served strictly — a missing '
                    . 'readings entry throws rather than degrading — so promoting it now would turn silent gaps into '
                    . '500s. Complete the data first; GET /admin/locales/%s names the exact files and event keys.',
                $locale,
                $report->describe(),
                $locale
            ));
        }

        $official[] = $locale;
        // Sorted, so the file keeps its alphabetical order and a promotion is a one-line diff
        // wherever the locale happens to land.
        sort($official);

        return $official;
    }

    /**
     * The official set with `$locale` removed, or a refusal explaining why it may not be.
     *
     * @param list<string> $official
     * @return list<string>
     */
    private function demoted(string $locale, array $official): array
    {
        if (false === in_array($locale, $official, true)) {
            throw new ConflictException(sprintf('Locale "%s" is not officially supported, so it cannot be demoted', $locale));
        }

        if (count($official) === 1) {
            throw new ConflictException(
                'Refusing to demote the last officially supported locale: an empty list is indistinguishable from '
                . 'an unreadable resource, and the API would silently fall back to its built-in list rather than '
                . 'supporting nothing. Promote a replacement first.'
            );
        }

        return array_values(array_filter($official, static fn (string $l): bool => $l !== $locale));
    }

    /**
     * The curated resource as it stands for THIS submitter — their own unpublished edit when
     * they have one in flight, otherwise the committed file.
     *
     * @return array<string, array<array-key, mixed>>
     */
    private function readCuratedResource(): array
    {
        $path = JsonData::SUPPORTED_LOCALES_FILE->path();
        $raw  = $this->unpublishedSourceContent($path);

        if ($raw === null) {
            $onDisk = is_file($path) ? file_get_contents($path) : false;
            $raw    = $onDisk === false ? null : $onDisk;
        }

        if ($raw === null) {
            throw new ServiceUnavailableException('The supported locales resource could not be read');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ServiceUnavailableException('The supported locales resource is not valid JSON: ' . $e->getMessage());
        }

        if (false === is_array($decoded) || false === isset($decoded[self::CALENDAR_KEY])) {
            throw new ServiceUnavailableException(sprintf(
                'The supported locales resource has no "%s" object to curate',
                self::CALENDAR_KEY
            ));
        }

        // Every top-level value is a per-calendar curated set. Checked rather than assumed,
        // because the annotation below is what lets the curation code index into one without
        // re-testing at every step.
        $resource = [];
        foreach ($decoded as $calendar => $set) {
            if (false === is_string($calendar) || false === is_array($set)) {
                throw new ServiceUnavailableException('The supported locales resource is not a map of calendars to curated sets');
            }
            $resource[$calendar] = $set;
        }

        return $resource;
    }

    /**
     * The DECLARED official list, which is not the same thing as
     * {@see SupportedLocales::official()}: that method substitutes its built-in fallback
     * when the resource cannot be read, and curating a list nobody wrote would write the
     * fallback into the file as though an operator had chosen it.
     *
     * @param array<string, array<array-key, mixed>> $resource
     * @return list<string>
     */
    private function officialFrom(array $resource): array
    {
        $declared = $resource[self::CALENDAR_KEY]['official'] ?? null;

        if (false === is_array($declared)) {
            throw new ServiceUnavailableException('The supported locales resource declares no official list to curate');
        }

        $official = [];
        foreach ($declared as $value) {
            if (is_string($value) && $value !== '') {
                $official[] = $value;
            }
        }

        if ($official === []) {
            throw new ServiceUnavailableException('The supported locales resource declares an empty official list');
        }

        return $official;
    }

    /**
     * Serialised the way the committed file is written, so a disk-mode edit produces a
     * one-line diff rather than reformatting the whole resource.
     *
     * @param array<string, array<array-key, mixed>> $resource
     */
    private static function encode(array $resource): string
    {
        return json_encode(
            $resource,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    private function checker(): LocaleReadinessChecker
    {
        return $this->checker ??= new LocaleReadinessChecker();
    }

    private function auditLogger(): LoggerInterface
    {
        return $this->auditLogger ??= LoggerFactory::create('audit', null, 90, false, true, false);
    }
}
