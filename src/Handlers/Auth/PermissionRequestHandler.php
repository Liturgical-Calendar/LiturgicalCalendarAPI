<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Repositories\PermissionRequestRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Permission Request Handler — user-facing endpoints.
 *
 * Allows authenticated users to request access to specific resources
 * and view the status of their requests.
 *
 * - POST /auth/permission-requests — Submit a new permission request
 * - GET  /auth/permission-requests — View own requests
 */
final class PermissionRequestHandler extends AbstractHandler
{
    /**
     * @var array<string>
     */
    private const VALID_OBJECT_TYPES = [
        'national_calendar',
        'diocesan_calendar',
        'wider_region',
        'test_definition',
    ];

    /**
     * @var array<string>
     */
    private const VALID_RELATIONS = ['admin', 'viewer', 'editor', 'deleter'];

    private ?PermissionRequestRepository $repository = null;

    public function __construct()
    {
        parent::__construct();

        $this->allowedRequestMethods      = [RequestMethod::GET, RequestMethod::POST];
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];
        $this->allowCredentials           = true;
    }

    private function getRepository(): PermissionRequestRepository
    {
        if ($this->repository === null) {
            $this->repository = new PermissionRequestRepository();
        }
        return $this->repository;
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
        $response = $response->withHeader('Content-Type', $mime);

        /** @var array{sub?: string, email?: string, name?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }

        $userId = $oidcUser['sub'] ?? null;
        if ($userId === null || ( is_string($userId) && trim($userId) === '' )) {
            throw new UnauthorizedException('Invalid authentication token');
        }

        if (!Connection::isConfigured()) {
            throw new \RuntimeException('Database not configured');
        }

        if ($method === RequestMethod::POST) {
            return $this->createRequest($request, $response, $userId, $oidcUser);
        }

        // GET — list own requests
        return $this->listOwnRequests($response, $userId);
    }

    /**
     * POST /auth/permission-requests — Submit a new permission request.
     *
     * @param ServerRequestInterface $request The HTTP request
     * @param ResponseInterface $response The HTTP response
     * @param string $userId Zitadel user ID
     * @param array{sub?: string, email?: string, name?: string, roles?: array<string>} $oidcUser OIDC user info
     */
    private function createRequest(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $userId,
        array $oidcUser
    ): ResponseInterface {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ValidationException('Request body must be JSON');
        }

        $objectType    = is_string($body['object_type'] ?? null) ? $body['object_type'] : '';
        $objectId      = is_string($body['object_id'] ?? null) ? $body['object_id'] : '';
        $relation      = is_string($body['relation'] ?? null) ? $body['relation'] : '';
        $justification = is_string($body['justification'] ?? null) ? $body['justification'] : null;
        $credentials   = is_string($body['credentials'] ?? null) ? $body['credentials'] : null;

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

        $userEmail = is_string($oidcUser['email'] ?? null) ? $oidcUser['email'] : '';
        $userName  = is_string($oidcUser['name'] ?? null) ? $oidcUser['name'] : null;

        $requestId = $this->getRepository()->create(
            $userId,
            $userEmail,
            $userName,
            $objectType,
            $objectId,
            $relation,
            $justification,
            $credentials
        );

        return $this->encodeResponseBody($response, [
            'success' => true,
            'message' => 'Permission request submitted',
            'id'      => $requestId,
        ]);
    }

    /**
     * GET /auth/permission-requests — List the user's own requests.
     */
    private function listOwnRequests(ResponseInterface $response, string $userId): ResponseInterface
    {
        $requests = $this->getRepository()->getByUser($userId);

        return $this->encodeResponseBody($response, [
            'requests' => $requests,
            'count'    => count($requests),
        ]);
    }
}
