<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Enum\MissalCatalog;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\RiteCalendarObjectIds;
use LiturgicalCalendar\Api\Services\RiteScopedObjectId;
use LiturgicalCalendar\Api\Services\TestScopeResolver;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * OpenFGA Authorization Middleware.
 *
 * Checks fine-grained, per-resource permissions via OpenFGA before allowing
 * write operations on calendar data. Maps HTTP methods and route parameters
 * to OpenFGA relationship checks.
 *
 * Mapping (default; per-instance override via constructor):
 *   PUT (create) → "admin"   PATCH (edit) → "editor"   DELETE → "admin"
 *   Calendar tests override PUT → "editor" (creating a test is an editing act).
 *
 * Object types:
 *   /data/nation/{id}       → national_calendar:{rite}/{id}
 *   /data/diocese/{id}      → diocesan_calendar:{rite}/{id}
 *   /data/widerregion/{id}  → wider_region:{rite}/{id}
 *   /tests/{rite}/{id}      → {national,diocesan}_calendar_test:{rite}/{id} | rite_calendar_test:{rite} (via TestScopeResolver)
 *   /temporale, /decrees    → rite_calendar:{rite}/{fixedId}
 *   /missals/{editio_typica}→ rite_calendar:{rite}/{missalId}
 *   /missals/{national}     → national_calendar:{rite}/{nation}
 *
 * Object ids that name a calendar are rite-qualified: a bare calendar id does not
 * identify a calendar, since the source tree is partitioned by rite and the same
 * diocese could be defined under both (issue #786). See {@see RiteScopedObjectId}.
 *
 * `PATCH /tests/{rite}/{id}` is authorized by two piped instances: {@see forTestScopes()}
 * checks the stored scope, {@see forTestScopePayloadTarget()} checks the payload-derived
 * scope. Both must pass, so re-scoping a test via PATCH requires `editor` on both the old
 * and the new scope (issue #790).
 */
final class OpenFgaAuthorizationMiddleware implements MiddlewareInterface
{
    /**
     * Map of path category to OpenFGA object type.
     *
     * @var array<string, string>
     */
    private const OBJECT_TYPE_MAP = [
        'nation'      => 'national_calendar',
        'diocese'     => 'diocesan_calendar',
        'widerregion' => 'wider_region',
    ];

    /**
     * Default method→relation map. Create (`PUT`) is a governance act → admin.
     * Edit (`PATCH`) → editor. Delete → admin (admin is a superset; #668).
     *
     * @var array<string, string>
     */
    private const DEFAULT_RELATION_MAP = [
        'PUT'    => 'admin',
        'PATCH'  => 'editor',
        'DELETE' => 'admin',
    ];

    /** @var array<string, string> */
    private array $relationMap;

    private OpenFgaClient $client;

    /**
     * The OpenFGA object type (e.g., "national_calendar", "test_definition").
     */
    private string $objectType;

    /**
     * Request attribute name containing the resource ID.
     */
    private string $resourceIdAttribute;

    /**
     * When non-null, this fixed object id is used instead of a request attribute.
     */
    private ?string $fixedObjectId;

    /**
     * Optional dynamic object resolver.
     *
     * When set, `process()` calls this closure with the current request to
     * derive the FGA [objectType, objectId] pair instead of using
     * `$this->objectType` + `extractResourceId()`. A `null` return means the
     * scope cannot be determined and the request is denied (fail-closed).
     *
     * @var (\Closure(\Psr\Http\Message\ServerRequestInterface): (array{0: string, 1: string}|null))|null
     */
    private ?\Closure $objectResolver;

    /**
     * @phpstan-param (\Closure(\Psr\Http\Message\ServerRequestInterface): (array{0: string, 1: string}|null))|null $objectResolver
     * @param array<string, string>|null $relationMap
     */
    public function __construct(
        OpenFgaClient $client,
        string $objectType,
        string $resourceIdAttribute = 'calendar_id',
        ?string $fixedObjectId = null,
        ?\Closure $objectResolver = null,
        ?array $relationMap = null
    ) {
        $this->client              = $client;
        $this->objectType          = $objectType;
        $this->resourceIdAttribute = $resourceIdAttribute;
        $this->fixedObjectId       = $fixedObjectId;
        $this->objectResolver      = $objectResolver;
        $this->relationMap         = $relationMap ?? self::DEFAULT_RELATION_MAP;
    }

    /**
     * Process the request and check OpenFGA authorization.
     *
     * Admin users (identified by the 'admin' role in oidc_user) bypass
     * OpenFGA checks, consistent with the existing AuthorizationMiddleware.
     *
     * @throws UnauthorizedException If user is not authenticated
     * @throws ForbiddenException If user lacks the required permission
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var array{sub?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Not authenticated');
        }

        $userId = $oidcUser['sub'] ?? null;
        if (!is_string($userId) || trim($userId) === '') {
            throw new UnauthorizedException('Invalid user token');
        }

        /** @var array<string> $roles */
        $roles = $oidcUser['roles'] ?? [];

        // Admin users bypass all OpenFGA checks
        if (in_array('admin', $roles, true)) {
            return $handler->handle($request);
        }

        // Determine the relation to check based on HTTP method
        $method   = strtoupper($request->getMethod());
        $relation = $this->relationMap[$method] ?? null;

        if ($relation === null) {
            // Non-write methods should not reach this middleware,
            // but if they do, pass through.
            return $handler->handle($request);
        }

        // Derive FGA object — object-resolver mode takes priority
        $fgaUser = "user:{$userId}";

        if ($this->objectResolver !== null) {
            $resolved = ( $this->objectResolver )($request);
            if ($resolved === null) {
                throw new ForbiddenException('Cannot resolve authorization scope for this request');
            }
            [$resolvedType, $resolvedId] = $resolved;
            $fgaObject                   = "{$resolvedType}:{$resolvedId}";
        } else {
            // Extract resource ID — fail closed for write operations
            $resourceId = $this->extractResourceId($request);
            if ($resourceId === null) {
                throw new ForbiddenException(
                    sprintf('Missing resource ID for %s authorization check', $this->objectType)
                );
            }
            $fgaObject = "{$this->objectType}:{$resourceId}";
        }

        $allowed = $this->client->check($fgaUser, $relation, $fgaObject);

        if (!$allowed) {
            throw new ForbiddenException(
                sprintf(
                    'No %s permission for %s',
                    $relation,
                    $fgaObject
                )
            );
        }

        return $handler->handle($request);
    }

    /**
     * Extract the resource ID from the request.
     *
     * When a fixed object id was supplied at construction time it is returned
     * immediately; otherwise the value is read from the named request attribute.
     */
    private function extractResourceId(ServerRequestInterface $request): ?string
    {
        if ($this->fixedObjectId !== null) {
            return $this->fixedObjectId;
        }

        $value = $request->getAttribute($this->resourceIdAttribute);
        if ($value !== null && ( is_string($value) || is_int($value) )) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Create middleware for a calendar data route.
     *
     * Maps the path category (nation/diocese/widerregion) to the OpenFGA
     * object type and creates the appropriate middleware instance.
     *
     * The object id is rite-qualified from the route's rite segment, so a grant on an
     * Ambrosian diocese cannot be satisfied by a Roman one of the same id, or vice
     * versa (issue #786). Resolved per-request rather than fixed at construction so an
     * absent or blank `calendar_id` attribute fails closed.
     *
     * @param OpenFgaClient $client       The OpenFGA client
     * @param string        $pathCategory Path category from the URL (e.g., "nation")
     * @param Rite          $rite         The rite selected by the route's rite segment
     * @return self|null Configured middleware, or null if the path category is unknown
     */
    public static function forCalendarData(OpenFgaClient $client, string $pathCategory, Rite $rite = Rite::ROMAN): ?self
    {
        $objectType = self::OBJECT_TYPE_MAP[$pathCategory] ?? null;
        if ($objectType === null) {
            return null;
        }

        $objectResolver = static function (ServerRequestInterface $request) use ($objectType, $rite): ?array {
            $calendarId = $request->getAttribute('calendar_id');
            if (!is_string($calendarId) || trim($calendarId) === '') {
                return null;
            }

            return [$objectType, RiteScopedObjectId::qualify($rite, $calendarId)];
        };

        return new self($client, $objectType, 'calendar_id', null, $objectResolver);
    }

    /**
     * Create middleware for test routes with dynamic scope resolution.
     *
     * The `test_id` request attribute is passed to `$resolver->resolve()` to
     * derive the FGA [objectType, objectId] pair at request time. If the
     * attribute is absent or the scope cannot be resolved the request is denied
     * (fail-closed). For PUT (create), when no file exists yet, the scope is
     * resolved from the request payload's `applies_to`.
     *
     * @param OpenFgaClient     $client   The OpenFGA client
     * @param TestScopeResolver $resolver Scope resolver for the test
     * @return self Configured middleware
     */
    public static function forTestScopes(OpenFgaClient $client, TestScopeResolver $resolver): self
    {
        $objectResolver = static function (ServerRequestInterface $request) use ($resolver): ?array {
            $testId = $request->getAttribute('test_id');
            if (!is_string($testId) || trim($testId) === '') {
                return null;
            }

            // The rite is part of a test's identity (#787). Without it there is no single
            // test to authorize against, so fail closed rather than guessing a partition.
            $riteValue = $request->getAttribute('test_rite');
            $rite      = is_string($riteValue) ? Rite::tryFrom($riteValue) : null;
            if ($rite === null) {
                return null;
            }

            $resolved = $resolver->resolve($rite, $testId);
            if (
                $resolved === null
                && strtoupper($request->getMethod()) === 'PUT'
                && TestScopeResolver::isSafeName($testId)
            ) {
                // Create flow: the test file does not exist yet, so derive the scope
                // from the payload's `applies_to` — the same value the handler will
                // persist, so the scope that authorizes the create is the scope the
                // created resource will carry. The payload comes from getParsedBody()
                // (populated by JsonBodyParserMiddleware earlier in the pipeline)
                // rather than the raw stream, so the body is never consumed here;
                // a missing/unparseable body yields null and fails closed.
                $resolved = $resolver->resolveFromPayload($request->getParsedBody());
            }
            return $resolved;
        };

        // objectType and resourceIdAttribute are unused when objectResolver is set:
        // process() delegates entirely to the resolver before extractResourceId() is called.
        return new self(
            $client,
            '',
            '',
            null,
            $objectResolver,
            ['PUT' => 'editor', 'PATCH' => 'editor', 'DELETE' => 'admin']
        );
    }

    /**
     * Create middleware that authorizes a PATCH's payload-derived target scope, alongside
     * {@see forTestScopes()}'s stored-scope check.
     *
     * `PATCH /tests/{rite}/{name}` authorizes against the scope resolved from the *stored*
     * file (via `forTestScopes()`), but the handler writes the *payload's* `applies_to` —
     * which may name a different scope. Piped right after `forTestScopes()`, this instance
     * closes that gap by requiring `editor` on the payload-derived scope too, so a PATCH that
     * re-scopes a test needs `editor` on both the old and the new scope (issue #790). This
     * mirrors the PUT rule: you may write only where you may write.
     *
     * The relation map only defines `PATCH`; every other method resolves to a null relation in
     * `process()`, which passes the request through without invoking the resolver — so this
     * instance is inert for PUT/DELETE and the resolver never needs its own method guard.
     *
     * When the payload does not change the scope, this resolves to the same object
     * `forTestScopes()` already checked — a redundant but harmless extra `check()` call.
     *
     * @param OpenFgaClient     $client   The OpenFGA client
     * @param TestScopeResolver $resolver Scope resolver for the test
     * @return self Configured middleware
     */
    public static function forTestScopePayloadTarget(OpenFgaClient $client, TestScopeResolver $resolver): self
    {
        $objectResolver = static function (ServerRequestInterface $request) use ($resolver): ?array {
            // Same payload source as forTestScopes()'s PUT-create fallback: getParsedBody()
            // (populated by JsonBodyParserMiddleware earlier in the pipeline), never the raw
            // stream, so the body is never consumed here. A missing/unparseable body yields
            // null and fails closed.
            return $resolver->resolveFromPayload($request->getParsedBody());
        };

        return new self(
            $client,
            '',
            '',
            null,
            $objectResolver,
            ['PATCH' => 'editor']
        );
    }

    /**
     * Create middleware for a rite-level calendar sub-resource (e.g. "temporale", "decrees").
     *
     * The object is `rite_calendar:{rite}/{subResource}`.
     *
     * A rite that does not have the sub-resource at all (Ambrosian `decrees`) is refused because
     * the object appears in no valid id set
     * ({@see \LiturgicalCalendar\Api\Services\RiteCalendarObjectIds}) and so can hold no tuple.
     * That refusal needs no special case for the rite.
     *
     * The pre-#955 `general_roman_calendar` fallback this check once carried was removed at the
     * prune milestone, along with the legacy tuples it existed to find.
     *
     * @param OpenFgaClient             $client      The OpenFGA client
     * @param Rite                      $rite        The rite whose calendar is being edited
     * @param string                    $subResource Fixed sub-resource id (e.g. "temporale")
     * @param array<string,string>|null $relationMap Optional method→relation override
     *                                               (default: PUT/DELETE→admin, PATCH→editor)
     * @return self Configured middleware
     */
    public static function forRiteCalendar(OpenFgaClient $client, Rite $rite, string $subResource, ?array $relationMap = null): self
    {
        $objectId = RiteScopedObjectId::qualify($rite, $subResource);

        return new self(
            $client,
            RiteCalendarObjectIds::TYPE,
            'calendar_id',
            $objectId,
            null,
            $relationMap
        );
    }

    /**
     * Create middleware for a missal write.
     *
     * Editio Typica missals are their rite's calendar Sanctorale sub-resources on `rite_calendar`;
     * national/regional missals follow the owning national calendar's grants (id prefix).
     *
     * @param OpenFgaClient $client   The OpenFGA client
     * @param string        $missalId The missal identifier (e.g. "EDITIO_TYPICA_2002", "IT_1983" or "EDITIO_TYPICA_2024")
     * @param Rite          $rite     The rite the missal belongs to
     * @return self Configured middleware
     */
    public static function forMissals(OpenFgaClient $client, string $missalId, Rite $rite = Rite::ROMAN): self
    {
        $source = MissalCatalog::for($rite);

        // A typical edition is a rite-qualified sub-resource on rite_calendar, alongside
        // `{rite}/temporale` and `{rite}/decrees` (RiteCalendarObjectIds). Missal ids are unique
        // across rites (MissalCatalogTest::testTheRitesDoNotShareIds), so the qualifier adds no
        // disambiguation for THIS id specifically — it is carried for one uniform rule across the
        // whole tier, whose other sub-resources are per-rite kinds and genuinely do need it (#955).
        if ($source->isEditioTypica($missalId)) {
            $objectId = RiteScopedObjectId::qualify($rite, $missalId);

            return new self(
                $client,
                RiteCalendarObjectIds::TYPE,
                'calendar_id',
                $objectId
            );
        }

        // A national edition is governed by the national calendar it was approved for, which DOES
        // need a rite qualifier: nation codes are not unique across rites.
        return new self($client, 'national_calendar', 'calendar_id', RiteScopedObjectId::qualify($rite, $source->regionFor($missalId)));
    }
}
