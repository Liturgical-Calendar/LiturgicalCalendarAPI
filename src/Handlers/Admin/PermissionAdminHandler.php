<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Admin;

use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Permission Admin Handler — OpenFGA tuple management.
 *
 * Provides admin endpoints for managing fine-grained permissions:
 *
 * - GET    /admin/permissions              — List tuples (optional filters)
 * - POST   /admin/permissions              — Grant permission (create tuple)
 * - DELETE /admin/permissions              — Revoke permission (delete tuple)
 * - GET    /admin/permissions/check        — Check a specific permission
 *
 * All endpoints require the admin role.
 */
final class PermissionAdminHandler extends AbstractHandler
{
    /**
     * Valid OpenFGA object types that can be managed.
     *
     * @var array<string>
     */
    private const VALID_OBJECT_TYPES = [
        'national_calendar',
        'diocesan_calendar',
        'wider_region',
        'test_definition',
    ];

    /**
     * Valid OpenFGA relations.
     *
     * @var array<string>
     */
    private const VALID_RELATIONS = ['viewer', 'editor', 'deleter'];

    private ?OpenFgaClient $fgaClient = null;

    public function __construct()
    {
        parent::__construct();

        $this->allowedRequestMethods      = [RequestMethod::GET, RequestMethod::POST, RequestMethod::DELETE];
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];
        $this->allowCredentials           = true;
    }

    private function getClient(): OpenFgaClient
    {
        if ($this->fgaClient === null) {
            $this->fgaClient = OpenFgaClient::fromEnv();
        }
        return $this->fgaClient;
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
        $response = $response->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'no-store');

        // Check authentication
        /** @var array{sub?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }

        if (!OidcAuthMiddleware::isAdmin($oidcUser)) {
            throw new ForbiddenException('Admin role required');
        }

        // Determine sub-route: /admin/permissions or /admin/permissions/check
        $path      = $request->getUri()->getPath();
        $pathParts = explode('/', trim($path, '/'));
        $lastPart  = end($pathParts);

        if ($method === RequestMethod::GET && $lastPart === 'check') {
            return $this->checkPermission($request, $response);
        }

        if ($method === RequestMethod::GET) {
            return $this->listPermissions($request, $response);
        }

        if ($method === RequestMethod::POST) {
            return $this->grantPermission($request, $response);
        }

        // DELETE
        return $this->revokePermission($request, $response);
    }

    /**
     * GET /admin/permissions — List relationship tuples.
     *
     * Query parameters:
     *   - user: Filter by user (e.g., "user:zitadel-id")
     *   - object_type: Filter by object type (e.g., "national_calendar")
     *   - object_id: Filter by specific object (e.g., "IT")
     *   - relation: Filter by relation (e.g., "editor")
     */
    private function listPermissions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params     = $request->getQueryParams();
        $user       = is_string($params['user'] ?? null) ? $params['user'] : '';
        $objectType = is_string($params['object_type'] ?? null) ? $params['object_type'] : '';
        $objectId   = is_string($params['object_id'] ?? null) ? $params['object_id'] : '';
        $relation   = is_string($params['relation'] ?? null) ? $params['relation'] : '';

        // Build the object filter for OpenFGA read
        // OpenFGA read requires at least an object type prefix (e.g., "national_calendar:")
        if ($objectType === '') {
            // No filter — list all tuples across all object types
            $allTuples = [];
            foreach (self::VALID_OBJECT_TYPES as $type) {
                $object = $type . ':' . $objectId;
                $tuples = $this->getClient()->readTuples(
                    $user !== '' ? $this->normalizeUser($user) : '',
                    $object,
                    $relation !== '' ? $relation : null
                );
                foreach ($tuples as $tuple) {
                    $allTuples[] = $tuple;
                }
            }

            return $this->encodeResponseBody($response, [
                'permissions' => $allTuples,
                'count'       => count($allTuples),
            ]);
        }

        if (!in_array($objectType, self::VALID_OBJECT_TYPES, true)) {
            throw new ValidationException(
                sprintf('Invalid object_type. Valid types: %s', implode(', ', self::VALID_OBJECT_TYPES))
            );
        }

        $object = $objectType . ':' . $objectId;
        $tuples = $this->getClient()->readTuples(
            $user !== '' ? $this->normalizeUser($user) : '',
            $object,
            $relation !== '' ? $relation : null
        );

        return $this->encodeResponseBody($response, [
            'permissions' => $tuples,
            'count'       => count($tuples),
        ]);
    }

    /**
     * POST /admin/permissions — Grant a permission (create tuple).
     *
     * Request body:
     *   - user: string (required) — Zitadel user ID (with or without "user:" prefix)
     *   - object_type: string (required) — e.g., "national_calendar"
     *   - object_id: string (required) — e.g., "IT"
     *   - relation: string (required) — "viewer", "editor", or "deleter"
     */
    private function grantPermission(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ValidationException('Request body must be JSON');
        }

        $user       = is_string($body['user'] ?? null) ? $body['user'] : '';
        $objectType = is_string($body['object_type'] ?? null) ? $body['object_type'] : '';
        $objectId   = is_string($body['object_id'] ?? null) ? $body['object_id'] : '';
        $relation   = is_string($body['relation'] ?? null) ? $body['relation'] : '';

        $this->validateTupleParams($user, $objectType, $objectId, $relation);

        $fgaUser   = $this->normalizeUser($user);
        $fgaObject = "{$objectType}:{$objectId}";

        $this->getClient()->writeTuple($fgaUser, $relation, $fgaObject);

        return $this->encodeResponseBody($response, [
            'success'  => true,
            'message'  => 'Permission granted',
            'user'     => $fgaUser,
            'relation' => $relation,
            'object'   => $fgaObject,
        ]);
    }

    /**
     * DELETE /admin/permissions — Revoke a permission (delete tuple).
     *
     * Request body:
     *   - user: string (required)
     *   - object_type: string (required)
     *   - object_id: string (required)
     *   - relation: string (required)
     */
    private function revokePermission(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ValidationException('Request body must be JSON');
        }

        $user       = is_string($body['user'] ?? null) ? $body['user'] : '';
        $objectType = is_string($body['object_type'] ?? null) ? $body['object_type'] : '';
        $objectId   = is_string($body['object_id'] ?? null) ? $body['object_id'] : '';
        $relation   = is_string($body['relation'] ?? null) ? $body['relation'] : '';

        $this->validateTupleParams($user, $objectType, $objectId, $relation);

        $fgaUser   = $this->normalizeUser($user);
        $fgaObject = "{$objectType}:{$objectId}";

        $this->getClient()->deleteTuple($fgaUser, $relation, $fgaObject);

        return $this->encodeResponseBody($response, [
            'success'  => true,
            'message'  => 'Permission revoked',
            'user'     => $fgaUser,
            'relation' => $relation,
            'object'   => $fgaObject,
        ]);
    }

    /**
     * GET /admin/permissions/check — Check if a user has a specific permission.
     *
     * Query parameters:
     *   - user: string (required)
     *   - object_type: string (required)
     *   - object_id: string (required)
     *   - relation: string (required)
     */
    private function checkPermission(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params     = $request->getQueryParams();
        $user       = is_string($params['user'] ?? null) ? $params['user'] : '';
        $objectType = is_string($params['object_type'] ?? null) ? $params['object_type'] : '';
        $objectId   = is_string($params['object_id'] ?? null) ? $params['object_id'] : '';
        $relation   = is_string($params['relation'] ?? null) ? $params['relation'] : '';

        $this->validateTupleParams($user, $objectType, $objectId, $relation);

        $fgaUser   = $this->normalizeUser($user);
        $fgaObject = "{$objectType}:{$objectId}";

        $allowed = $this->getClient()->check($fgaUser, $relation, $fgaObject);

        return $this->encodeResponseBody($response, [
            'allowed'  => $allowed,
            'user'     => $fgaUser,
            'relation' => $relation,
            'object'   => $fgaObject,
        ]);
    }

    /**
     * Validate tuple parameters.
     *
     * @throws ValidationException If any parameter is invalid
     */
    private function validateTupleParams(string $user, string $objectType, string $objectId, string $relation): void
    {
        if ($user === '') {
            throw new ValidationException('Missing required parameter: user');
        }

        if ($objectType === '') {
            throw new ValidationException('Missing required parameter: object_type');
        }

        if (!in_array($objectType, self::VALID_OBJECT_TYPES, true)) {
            throw new ValidationException(
                sprintf('Invalid object_type "%s". Valid types: %s', $objectType, implode(', ', self::VALID_OBJECT_TYPES))
            );
        }

        if ($objectId === '') {
            throw new ValidationException('Missing required parameter: object_id');
        }

        if ($relation === '') {
            throw new ValidationException('Missing required parameter: relation');
        }

        if (!in_array($relation, self::VALID_RELATIONS, true)) {
            throw new ValidationException(
                sprintf('Invalid relation "%s". Valid relations: %s', $relation, implode(', ', self::VALID_RELATIONS))
            );
        }
    }

    /**
     * Normalize user identifier to OpenFGA format.
     *
     * Accepts either a bare Zitadel user ID or a prefixed "user:id" format.
     */
    private function normalizeUser(string $user): string
    {
        if (!str_starts_with($user, 'user:')) {
            return "user:{$user}";
        }
        return $user;
    }
}
