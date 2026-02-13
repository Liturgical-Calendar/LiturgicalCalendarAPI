<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Repositories\ApplicationRepository;
use LiturgicalCalendar\Api\Repositories\ApiKeyRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Applications Handler
 *
 * Handles developer application management:
 * - GET /applications - List user's applications
 * - POST /applications - Create new application (status: pending)
 * - GET /applications/{uuid} - Get single application
 * - PATCH /applications/{uuid} - Update application
 * - DELETE /applications/{uuid} - Delete application
 * - POST /applications/{uuid}/resubmit - Resubmit rejected application for review
 *
 * Also handles API key management (nested under applications):
 * - GET /applications/{uuid}/keys - List keys for application
 * - POST /applications/{uuid}/keys - Generate new API key (requires approved status)
 * - DELETE /applications/{uuid}/keys/{keyId} - Revoke/delete key
 * - POST /applications/{uuid}/keys/{keyId}/rotate - Rotate key
 *
 * Application Approval Workflow:
 * - New applications start with 'pending' status
 * - Only 'approved' applications can generate API keys
 * - 'rejected' applications can be edited and resubmitted
 * - 'revoked' applications cannot generate new API keys
 *
 * Requires developer role.
 */
final class ApplicationsHandler extends AbstractHandler
{
    private ApplicationRepository $appRepo;
    private ApiKeyRepository $keyRepo;

    public function __construct()
    {
        parent::__construct();

        $this->allowedRequestMethods      = [
            RequestMethod::GET,
            RequestMethod::POST,
            RequestMethod::PATCH,
            RequestMethod::DELETE
        ];
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];
        $this->allowCredentials           = true;

        $this->appRepo = new ApplicationRepository();
        $this->keyRepo = new ApiKeyRepository();
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

        // Check authentication via OIDC token
        /** @var array{sub?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }

        // Verify developer role
        if (!OidcAuthMiddleware::hasDeveloperRole($oidcUser)) {
            throw new ForbiddenException('Developer role required');
        }

        $userId = $oidcUser['sub'] ?? null;
        if ($userId === null) {
            throw new UnauthorizedException('Invalid user token');
        }

        // Parse path to determine action
        $path      = $request->getUri()->getPath();
        $pathParts = explode('/', trim($path, '/'));

        // Expected paths:
        // applications
        // applications/{uuid}
        // applications/{uuid}/resubmit
        // applications/{uuid}/keys
        // applications/{uuid}/keys/{keyId}
        // applications/{uuid}/keys/{keyId}/rotate

        // Find the index of 'applications' in the path
        $appIndex = array_search('applications', $pathParts, true);
        if ($appIndex === false) {
            throw new ValidationException('Invalid request path');
        }

        // Get the parts after 'applications'
        $subPath = array_slice($pathParts, $appIndex + 1);

        // Route based on path structure
        if (count($subPath) === 0) {
            // /applications
            return match ($method) {
                RequestMethod::GET  => $this->listApplications($response, $userId),
                RequestMethod::POST => $this->createApplication($request, $response, $userId),
                default             => throw new ValidationException('Method not allowed for this path')
            };
        }

        $uuid = $subPath[0];

        if (count($subPath) === 1) {
            // /applications/{uuid}
            return match ($method) {
                RequestMethod::GET    => $this->getApplication($response, $uuid, $userId),
                RequestMethod::PATCH  => $this->updateApplication($request, $response, $uuid, $userId),
                RequestMethod::DELETE => $this->deleteApplication($response, $uuid, $userId),
                default               => throw new ValidationException('Method not allowed for this path')
            };
        }

        if (count($subPath) === 2 && $subPath[1] === 'resubmit') {
            // /applications/{uuid}/resubmit
            return match ($method) {
                RequestMethod::POST => $this->resubmitApplication($response, $uuid, $userId),
                default             => throw new ValidationException('Method not allowed for this path')
            };
        }

        if (count($subPath) >= 2 && $subPath[1] === 'keys') {
            if (count($subPath) === 2) {
                // /applications/{uuid}/keys
                return match ($method) {
                    RequestMethod::GET  => $this->listApiKeys($response, $uuid, $userId),
                    RequestMethod::POST => $this->generateApiKey($request, $response, $uuid, $userId),
                    default             => throw new ValidationException('Method not allowed for this path')
                };
            }

            $keyId = $subPath[2];

            if (count($subPath) === 3) {
                // /applications/{uuid}/keys/{keyId}
                return match ($method) {
                    RequestMethod::DELETE => $this->revokeApiKey($response, $uuid, $keyId, $userId),
                    default               => throw new ValidationException('Method not allowed for this path')
                };
            }

            if (count($subPath) === 4 && $subPath[3] === 'rotate') {
                // /applications/{uuid}/keys/{keyId}/rotate
                return match ($method) {
                    RequestMethod::POST => $this->rotateApiKey($response, $uuid, $keyId, $userId),
                    default             => throw new ValidationException('Method not allowed for this path')
                };
            }
        }

        throw new ValidationException('Invalid request path');
    }

    /**
     * List all applications for the authenticated user.
     */
    private function listApplications(ResponseInterface $response, string $userId): ResponseInterface
    {
        $applications = $this->appRepo->getByUser($userId);

        // Collect all application IDs for bulk key count query (avoid N+1)
        $appIds = [];
        foreach ($applications as $app) {
            if (isset($app['id']) && is_string($app['id'])) {
                $appIds[] = $app['id'];
            }
        }

        // Get all key counts in a single query
        $keyCounts = $this->keyRepo->countActiveByApplications($appIds);

        // Add key counts and uuid alias to each application
        foreach ($applications as &$app) {
            if (isset($app['id']) && is_string($app['id'])) {
                $app['uuid']      = $app['id']; // Alias for frontend compatibility
                $app['key_count'] = $keyCounts[$app['id']] ?? 0;
            }
        }

        return $this->encodeResponseBody($response, [
            'applications' => $applications,
            'total'        => count($applications),
        ]);
    }

    /**
     * Create a new application.
     */
    private function createApplication(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $userId
    ): ResponseInterface {
        $body = $this->parseBodyParams($request, true);

        if ($body === null || !isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
            throw new ValidationException('Application name is required');
        }

        $name        = trim($body['name']);
        $description = isset($body['description']) && is_string($body['description'])
            ? trim($body['description'])
            : null;
        $website     = isset($body['website']) && is_string($body['website'])
            ? trim($body['website'])
            : null;

        // Validate website URL if provided
        if ($website !== null && $website !== '' && filter_var($website, FILTER_VALIDATE_URL) === false) {
            throw new ValidationException('Invalid website URL');
        }

        // Validate requested_scope parameter
        $requestedScope = 'read'; // Default to read-only for safety
        if (isset($body['requested_scope']) && is_string($body['requested_scope'])) {
            if (!in_array($body['requested_scope'], ApplicationRepository::VALID_SCOPES, true)) {
                throw new ValidationException(
                    sprintf('Invalid requested_scope. Valid values are: %s', implode(', ', ApplicationRepository::VALID_SCOPES))
                );
            }
            $requestedScope = $body['requested_scope'];
        }

        $application = $this->appRepo->create($userId, $name, $description, $website, $requestedScope);

        // Add uuid alias for frontend compatibility
        if (isset($application['id']) && is_string($application['id'])) {
            $application['uuid'] = $application['id'];
        }

        $response = $response->withStatus(StatusCode::CREATED->value);
        return $this->encodeResponseBody($response, [
            'success'     => true,
            'message'     => 'Application request submitted successfully. An admin will review your application.',
            'application' => $application,
        ], StatusCode::CREATED);
    }

    /**
     * Get a single application by UUID.
     */
    private function getApplication(
        ResponseInterface $response,
        string $uuid,
        string $userId
    ): ResponseInterface {
        $application = $this->appRepo->getByUuid($uuid);

        if ($application === null) {
            throw new NotFoundException('Application not found');
        }

        // Verify ownership
        if (!isset($application['zitadel_user_id']) || $application['zitadel_user_id'] !== $userId) {
            throw new ForbiddenException('Access denied');
        }

        // Add uuid alias and API keys to the response
        if (isset($application['id']) && is_string($application['id'])) {
            $application['uuid'] = $application['id']; // Alias for frontend compatibility
            $application['keys'] = $this->keyRepo->getByApplication($application['id']);
        }

        return $this->encodeResponseBody($response, $application);
    }

    /**
     * Update an application.
     */
    private function updateApplication(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $uuid,
        string $userId
    ): ResponseInterface {
        $body = $this->parseBodyParams($request, true);

        if ($body === null) {
            throw new ValidationException('Request body is required');
        }

        $data = [];
        if (isset($body['name']) && is_string($body['name'])) {
            $data['name'] = trim($body['name']);
        }
        if (isset($body['description']) && is_string($body['description'])) {
            $data['description'] = trim($body['description']);
        }
        if (isset($body['website']) && is_string($body['website'])) {
            $website = trim($body['website']);
            if ($website !== '' && filter_var($website, FILTER_VALIDATE_URL) === false) {
                throw new ValidationException('Invalid website URL');
            }
            $data['website'] = $website;
        }

        if (empty($data)) {
            throw new ValidationException('No valid fields to update');
        }

        $application = $this->appRepo->update($uuid, $userId, $data);

        if ($application === null) {
            throw new NotFoundException('Application not found or access denied');
        }

        // Add uuid alias for frontend compatibility
        if (isset($application['id']) && is_string($application['id'])) {
            $application['uuid'] = $application['id'];
        }

        return $this->encodeResponseBody($response, [
            'success'     => true,
            'message'     => 'Application updated successfully',
            'application' => $application,
        ]);
    }

    /**
     * Delete an application.
     */
    private function deleteApplication(
        ResponseInterface $response,
        string $uuid,
        string $userId
    ): ResponseInterface {
        $deleted = $this->appRepo->delete($uuid, $userId);

        if (!$deleted) {
            throw new NotFoundException('Application not found or access denied');
        }

        return $this->encodeResponseBody($response, [
            'success' => true,
            'message' => 'Application deleted successfully',
        ]);
    }

    /**
     * List API keys for an application.
     */
    private function listApiKeys(
        ResponseInterface $response,
        string $uuid,
        string $userId
    ): ResponseInterface {
        // Verify ownership (also confirms application exists)
        if (!$this->appRepo->isOwner($uuid, $userId)) {
            throw new ForbiddenException('Access denied');
        }

        $keys = $this->keyRepo->getByApplication($uuid);

        return $this->encodeResponseBody($response, [
            'keys'  => $keys,
            'total' => count($keys),
        ]);
    }

    /**
     * Generate a new API key for an application.
     *
     * Only approved applications can generate API keys.
     * The key scope is restricted by the application's requested_scope:
     * - If requested_scope is 'read', only 'read' keys can be generated
     * - If requested_scope is 'write', both 'read' and 'write' keys can be generated
     */
    private function generateApiKey(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $uuid,
        string $userId
    ): ResponseInterface {
        // Verify ownership
        if (!$this->appRepo->isOwner($uuid, $userId)) {
            throw new ForbiddenException('Access denied');
        }

        $application = $this->appRepo->getByUuid($uuid);
        if ($application === null || !isset($application['id']) || !is_string($application['id'])) {
            throw new NotFoundException('Application not found');
        }

        // Check if application is approved
        if (!$this->appRepo->isApproved($uuid)) {
            $status = isset($application['status']) && is_string($application['status'])
                ? $application['status']
                : 'unknown';
            throw new ForbiddenException("Cannot generate API keys for {$status} applications. Only approved applications can generate API keys.");
        }

        $body = $this->parseBodyParams($request, false) ?? [];

        $name  = isset($body['name']) && is_string($body['name'])
            ? trim($body['name'])
            : null;
        $scope = isset($body['scope']) && is_string($body['scope']) && in_array($body['scope'], ['read', 'write'], true)
            ? $body['scope']
            : 'read';

        // Enforce scope restrictions based on application's requested_scope
        $requestedScope = isset($application['requested_scope']) && is_string($application['requested_scope'])
            ? $application['requested_scope']
            : 'read';

        if ($requestedScope === 'read' && $scope === 'write') {
            throw new ForbiddenException('This application only has read-only access. Cannot generate write-scope API keys.');
        }

        $envRateLimit     = $_ENV['API_KEY_DEFAULT_RATE_LIMIT'] ?? null;
        $defaultRateLimit = is_numeric($envRateLimit) ? max(1, (int) $envRateLimit) : 1000;
        $rateLimit        = isset($body['rate_limit']) && is_numeric($body['rate_limit'])
            ? max(1, (int) $body['rate_limit'])
            : $defaultRateLimit;
        $expiresAt        = null;
        if (isset($body['expires_at']) && is_string($body['expires_at'])) {
            try {
                $tz        = new \DateTimeZone('Europe/Vatican');
                $expiresAt = new \DateTimeImmutable($body['expires_at'], $tz);
            } catch (\Exception) {
                throw new ValidationException('Invalid expiration date format');
            }
        }

        $result = $this->keyRepo->generate(
            $application['id'],
            $name,
            $scope,
            $rateLimit,
            $expiresAt
        );

        $response = $response->withStatus(StatusCode::CREATED->value);
        return $this->encodeResponseBody($response, [
            'success' => true,
            'message' => 'API key generated successfully. Save this key - it will not be shown again.',
            'key'     => $result['key'],  // Plain text key - only shown once
            'record'  => $result['record'],
        ], StatusCode::CREATED);
    }

    /**
     * Revoke (delete) an API key.
     */
    private function revokeApiKey(
        ResponseInterface $response,
        string $uuid,
        string $keyId,
        string $userId
    ): ResponseInterface {
        // Verify ownership of the application
        if (!$this->appRepo->isOwner($uuid, $userId)) {
            throw new ForbiddenException('Access denied');
        }

        $deleted = $this->keyRepo->delete($keyId, $userId, $uuid);

        if (!$deleted) {
            throw new NotFoundException('API key not found or access denied');
        }

        return $this->encodeResponseBody($response, [
            'success' => true,
            'message' => 'API key revoked successfully',
        ]);
    }

    /**
     * Rotate an API key (revoke old, create new with same settings).
     */
    private function rotateApiKey(
        ResponseInterface $response,
        string $uuid,
        string $keyId,
        string $userId
    ): ResponseInterface {
        // Verify ownership of the application
        if (!$this->appRepo->isOwner($uuid, $userId)) {
            throw new ForbiddenException('Access denied');
        }

        $result = $this->keyRepo->rotate($keyId, $userId);

        if ($result === null) {
            throw new NotFoundException('API key not found or access denied');
        }

        return $this->encodeResponseBody($response, [
            'success' => true,
            'message' => 'API key rotated successfully. Save this new key - it will not be shown again.',
            'key'     => $result['key'],  // Plain text key - only shown once
            'record'  => $result['record'],
        ]);
    }

    /**
     * Resubmit a rejected application for review.
     *
     * Only rejected applications can be resubmitted.
     */
    private function resubmitApplication(
        ResponseInterface $response,
        string $uuid,
        string $userId
    ): ResponseInterface {
        $application = $this->appRepo->resubmitApplication($uuid, $userId);

        if ($application === null) {
            throw new NotFoundException('Application not found, access denied, or application is not in rejected status');
        }

        // Add uuid alias for frontend compatibility
        if (isset($application['id']) && is_string($application['id'])) {
            $application['uuid'] = $application['id'];
        }

        return $this->encodeResponseBody($response, [
            'success'     => true,
            'message'     => 'Application resubmitted for review. An admin will review your updated application.',
            'application' => $application,
        ]);
    }
}
