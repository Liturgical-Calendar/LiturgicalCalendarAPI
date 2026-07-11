<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
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
 *   /data/nation/{id}       → national_calendar:{id}
 *   /data/diocese/{id}      → diocesan_calendar:{id}
 *   /data/widerregion/{id}  → wider_region:{id}
 *   /tests/{id}             → {national,diocesan,general_roman}_calendar_test:{scopeId} (via TestScopeResolver)
 *   /temporale, /decrees    → general_roman_calendar:{fixedId}
 *   /missals/{editio_typica}→ general_roman_calendar:{missalId}
 *   /missals/{national}     → national_calendar:{nation}
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
     * @param OpenFgaClient $client       The OpenFGA client
     * @param string        $pathCategory Path category from the URL (e.g., "nation")
     * @return self|null Configured middleware, or null if the path category is unknown
     */
    public static function forCalendarData(OpenFgaClient $client, string $pathCategory): ?self
    {
        $objectType = self::OBJECT_TYPE_MAP[$pathCategory] ?? null;
        if ($objectType === null) {
            return null;
        }

        return new self($client, $objectType, 'calendar_id');
    }

    /**
     * Create middleware for test routes with dynamic scope resolution.
     *
     * The `test_id` request attribute is passed to `$resolver->resolve()` to
     * derive the FGA [objectType, objectId] pair at request time. If the
     * attribute is absent or the resolver returns null the request is denied
     * (fail-closed).
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
            return $resolver->resolve($testId);
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
     * Create middleware for a General Roman Calendar sub-resource with a fixed object id
     * (e.g. "temporale" or "decrees").
     *
     * @param OpenFgaClient              $client      The OpenFGA client
     * @param string                     $objectId    Fixed object id (e.g. "temporale")
     * @param array<string,string>|null  $relationMap Optional method→relation override
     *                                                (default: PUT/DELETE→admin, PATCH→editor)
     * @return self Configured middleware
     */
    public static function forGeneralRomanCalendar(OpenFgaClient $client, string $objectId, ?array $relationMap = null): self
    {
        return new self($client, 'general_roman_calendar', 'calendar_id', $objectId, null, $relationMap);
    }

    /**
     * Create middleware for a missal write.
     *
     * Editio Typica (Latin) missals are General Roman Calendar Sanctorale sub-resources;
     * national/regional missals follow the owning national calendar's grants (id prefix).
     *
     * @param OpenFgaClient $client   The OpenFGA client
     * @param string        $missalId The missal identifier (e.g. "EDITIO_TYPICA_2002" or "IT_1983")
     * @return self Configured middleware
     */
    public static function forMissals(OpenFgaClient $client, string $missalId): self
    {
        if (RomanMissal::isLatinMissal($missalId)) {
            return new self($client, 'general_roman_calendar', 'calendar_id', $missalId);
        }

        $nation = explode('_', $missalId)[0];
        return new self($client, 'national_calendar', 'calendar_id', $nation);
    }
}
