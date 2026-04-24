<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
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
 * Mapping:
 *   PUT/PATCH  → "editor" relation
 *   DELETE     → "deleter" relation
 *
 * Object types:
 *   /data/nation/{id}      → national_calendar:{id}
 *   /data/diocese/{id}      → diocesan_calendar:{id}
 *   /data/widerregion/{id}  → wider_region:{id}
 *   /tests/{id}             → test_definition:{id}
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
     * Map of HTTP method to OpenFGA relation.
     *
     * @var array<string, string>
     */
    private const RELATION_MAP = [
        'PUT'    => 'editor',
        'PATCH'  => 'editor',
        'DELETE' => 'deleter',
    ];

    private OpenFgaClient $client;

    /**
     * The OpenFGA object type (e.g., "national_calendar", "test_definition").
     */
    private string $objectType;

    /**
     * Request attribute name containing the resource ID.
     */
    private string $resourceIdAttribute;

    public function __construct(
        OpenFgaClient $client,
        string $objectType,
        string $resourceIdAttribute = 'calendar_id'
    ) {
        $this->client              = $client;
        $this->objectType          = $objectType;
        $this->resourceIdAttribute = $resourceIdAttribute;
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
        if ($userId === null) {
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
        $relation = self::RELATION_MAP[$method] ?? null;

        if ($relation === null) {
            // Non-write methods should not reach this middleware,
            // but if they do, pass through.
            return $handler->handle($request);
        }

        // Extract resource ID — fail closed for write operations
        $resourceId = $this->extractResourceId($request);
        if ($resourceId === null) {
            throw new ForbiddenException(
                sprintf('Missing resource ID for %s authorization check', $this->objectType)
            );
        }

        // Check OpenFGA permission
        $fgaUser   = "user:{$userId}";
        $fgaObject = "{$this->objectType}:{$resourceId}";

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
     */
    private function extractResourceId(ServerRequestInterface $request): ?string
    {
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
     * Create middleware for test definition routes.
     *
     * @param OpenFgaClient $client The OpenFGA client
     * @return self Configured middleware
     */
    public static function forTestDefinition(OpenFgaClient $client): self
    {
        return new self($client, 'test_definition', 'test_id');
    }
}
