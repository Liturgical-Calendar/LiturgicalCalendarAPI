<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Admin;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Repositories\ApplicationRepository;
use LiturgicalCalendar\Api\Services\ZitadelService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Application Admin Handler
 *
 * Handles admin operations for application management:
 * - GET /admin/applications - List all applications (optional status filter)
 * - GET /admin/applications/pending - List pending applications
 * - GET /admin/applications/{uuid} - Get single application details
 * - POST /admin/applications/{uuid}/approve - Approve an application
 * - POST /admin/applications/{uuid}/reject - Reject an application
 * - POST /admin/applications/{uuid}/revoke - Revoke an approved application
 *
 * Requires admin role.
 */
final class ApplicationAdminHandler extends AbstractHandler
{
    private ?ApplicationRepository $repository = null;
    private LoggerInterface $logger;

    public function __construct()
    {
        parent::__construct();

        $this->allowedRequestMethods      = [RequestMethod::GET, RequestMethod::POST];
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];
        $this->allowCredentials           = true;

        $this->logger = LoggerFactory::create('admin', null, 30, false, true, false);
    }

    private function getRepository(): ApplicationRepository
    {
        if ($this->repository === null) {
            $this->repository = new ApplicationRepository();
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

        // Check authentication via OIDC token
        /** @var array{sub?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }

        // Verify admin role
        if (!OidcAuthMiddleware::isAdmin($oidcUser)) {
            throw new ForbiddenException('Admin role required');
        }

        $adminId = $oidcUser['sub'] ?? '';
        if (empty($adminId)) {
            throw new UnauthorizedException('Invalid authentication token');
        }

        if (!Connection::isConfigured()) {
            throw new \RuntimeException('Database not configured');
        }

        // Parse path to determine action
        $path      = $request->getUri()->getPath();
        $pathParts = explode('/', trim($path, '/'));

        // Find the index of 'applications' in the path (after 'admin')
        $appIndex = array_search('applications', $pathParts, true);
        if ($appIndex === false) {
            throw new ValidationException('Invalid request path');
        }

        // Get the parts after 'applications'
        $subPath = array_slice($pathParts, $appIndex + 1);

        // Route based on path structure
        if ($method === RequestMethod::GET) {
            if (count($subPath) === 0) {
                // GET /admin/applications - list all with optional status filter
                $queryParams = $request->getQueryParams();
                $status      = isset($queryParams['status']) && is_string($queryParams['status'])
                    ? $queryParams['status']
                    : null;
                return $this->listApplications($response, $status);
            }

            if (count($subPath) === 1 && $subPath[0] === 'pending') {
                // GET /admin/applications/pending
                return $this->listPendingApplications($response);
            }

            if (count($subPath) === 1) {
                // GET /admin/applications/{uuid}
                return $this->getApplication($response, $subPath[0]);
            }
        }

        if ($method === RequestMethod::POST) {
            if (count($subPath) < 2) {
                throw new ValidationException('Invalid request path');
            }

            $uuid   = $subPath[0];
            $action = $subPath[1];

            // Validate UUID format
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
                throw new ValidationException('Invalid application UUID format');
            }

            $body  = $request->getParsedBody();
            $notes = null;
            if (is_array($body) && isset($body['notes']) && is_string($body['notes'])) {
                $notes = $body['notes'];
            }

            return match ($action) {
                'approve' => $this->approveApplication($response, $uuid, $adminId, $notes),
                'reject'  => $this->rejectApplication($response, $uuid, $adminId, $notes),
                'revoke'  => $this->revokeApplication($response, $uuid, $adminId, $notes),
                default   => throw new ValidationException('Invalid action. Use "approve", "reject", or "revoke"')
            };
        }

        throw new ValidationException('Invalid request path');
    }

    /**
     * Enrich applications with user details from Zitadel.
     *
     * @param array<int, array<string, mixed>> $applications
     * @return array<int, array<string, mixed>>
     */
    private function enrichApplicationsWithUserDetails(array $applications): array
    {
        if (!ZitadelService::isConfigured()) {
            // If Zitadel is not configured, just return applications as-is
            return $applications;
        }

        // Collect unique user IDs
        $userIds = [];
        foreach ($applications as $app) {
            $userId = $app['zitadel_user_id'] ?? null;
            if (is_string($userId) && $userId !== '') {
                if (!isset($userIds[$userId])) {
                    $userIds[$userId] = true;
                }
            }
        }

        if (empty($userIds)) {
            return $applications;
        }

        // Fetch user details from Zitadel
        $userCache = [];
        try {
            $zitadel = ZitadelService::fromEnv();
            foreach (array_keys($userIds) as $userId) {
                // Cast to string: PHP converts numeric string keys to int
                $user = $zitadel->getUser((string) $userId);
                if ($user !== null) {
                    // Extract name and email from Zitadel user response
                    $human   = isset($user['human']) && is_array($user['human']) ? $user['human'] : [];
                    $profile = isset($human['profile']) && is_array($human['profile']) ? $human['profile'] : [];
                    $email   = isset($human['email']) && is_array($human['email']) ? $human['email'] : [];

                    $firstName   = isset($profile['firstName']) && is_string($profile['firstName']) ? $profile['firstName'] : '';
                    $lastName    = isset($profile['lastName']) && is_string($profile['lastName']) ? $profile['lastName'] : '';
                    $displayName = isset($profile['displayName']) && is_string($profile['displayName'])
                        ? $profile['displayName']
                        : ( trim($firstName . ' ' . $lastName) ?: null );

                    $userCache[$userId] = [
                        'user_name'  => $displayName,
                        'user_email' => isset($email['email']) && is_string($email['email']) ? $email['email'] : null,
                    ];
                }
            }
        } catch (\Exception $e) {
            // Log error but don't fail - just return applications without enrichment
            $this->logger->warning('Failed to fetch user details from Zitadel', [
                'error' => $e->getMessage(),
            ]);
        }

        // Enrich applications with user details
        foreach ($applications as &$app) {
            $userId = $app['zitadel_user_id'] ?? null;
            if (is_string($userId) && $userId !== '' && isset($userCache[$userId])) {
                $app['user_name']  = $userCache[$userId]['user_name'];
                $app['user_email'] = $userCache[$userId]['user_email'];
            } else {
                $app['user_name']  = null;
                $app['user_email'] = null;
            }
        }

        return $applications;
    }

    /**
     * List all applications with optional status filter.
     */
    private function listApplications(ResponseInterface $response, ?string $status): ResponseInterface
    {
        $repo = $this->getRepository();

        // Validate status if provided (use repository constant to avoid drift)
        if ($status !== null && !in_array($status, ApplicationRepository::VALID_STATUSES, true)) {
            throw new ValidationException(
                sprintf('Invalid status filter. Valid values are: %s', implode(', ', ApplicationRepository::VALID_STATUSES))
            );
        }

        $applications = $repo->getAllApplications($status);

        // Add uuid alias to each application
        foreach ($applications as &$app) {
            if (isset($app['id']) && is_string($app['id'])) {
                $app['uuid'] = $app['id'];
            }
        }

        // Enrich with user details from Zitadel
        $applications = $this->enrichApplicationsWithUserDetails($applications);

        return $this->encodeResponseBody($response, [
            'applications' => $applications,
            'total'        => count($applications),
            'filter'       => $status,
        ]);
    }

    /**
     * List all pending applications awaiting review.
     */
    private function listPendingApplications(ResponseInterface $response): ResponseInterface
    {
        $repo = $this->getRepository();

        $applications = $repo->getPendingApplications();
        $pendingCount = $repo->countPendingApplications();

        // Add uuid alias to each application
        foreach ($applications as &$app) {
            if (isset($app['id']) && is_string($app['id'])) {
                $app['uuid'] = $app['id'];
            }
        }

        // Enrich with user details from Zitadel
        $applications = $this->enrichApplicationsWithUserDetails($applications);

        return $this->encodeResponseBody($response, [
            'pending_applications' => $applications,
            'pending_count'        => $pendingCount,
        ]);
    }

    /**
     * Get a single application by UUID.
     */
    private function getApplication(ResponseInterface $response, string $uuid): ResponseInterface
    {
        $repo = $this->getRepository();

        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            throw new ValidationException('Invalid application UUID format');
        }

        $application = $repo->getByUuid($uuid);

        if ($application === null) {
            throw new NotFoundException('Application not found');
        }

        // Add uuid alias
        if (isset($application['id']) && is_string($application['id'])) {
            $application['uuid'] = $application['id'];
        }

        return $this->encodeResponseBody($response, $application);
    }

    /**
     * Approve an application.
     */
    private function approveApplication(
        ResponseInterface $response,
        string $uuid,
        string $adminId,
        ?string $notes
    ): ResponseInterface {
        $repo = $this->getRepository();

        $application = $repo->approveApplication($uuid, $adminId, $notes);

        if ($application === null) {
            throw new NotFoundException('Application not found or cannot be approved (must be pending or rejected)');
        }

        // Add uuid alias
        if (isset($application['id']) && is_string($application['id'])) {
            $application['uuid'] = $application['id'];
        }

        return $this->encodeResponseBody($response, [
            'success'     => true,
            'message'     => 'Application approved successfully',
            'application' => $application,
        ]);
    }

    /**
     * Reject an application.
     */
    private function rejectApplication(
        ResponseInterface $response,
        string $uuid,
        string $adminId,
        ?string $notes
    ): ResponseInterface {
        $repo = $this->getRepository();

        $application = $repo->rejectApplication($uuid, $adminId, $notes);

        if ($application === null) {
            throw new NotFoundException('Application not found or cannot be rejected (must be pending)');
        }

        // Add uuid alias
        if (isset($application['id']) && is_string($application['id'])) {
            $application['uuid'] = $application['id'];
        }

        return $this->encodeResponseBody($response, [
            'success'     => true,
            'message'     => 'Application rejected',
            'application' => $application,
        ]);
    }

    /**
     * Revoke a previously approved application.
     */
    private function revokeApplication(
        ResponseInterface $response,
        string $uuid,
        string $adminId,
        ?string $notes
    ): ResponseInterface {
        $repo = $this->getRepository();

        $application = $repo->revokeApplication($uuid, $adminId, $notes);

        if ($application === null) {
            throw new NotFoundException('Application not found or cannot be revoked (must be approved)');
        }

        // Add uuid alias
        if (isset($application['id']) && is_string($application['id'])) {
            $application['uuid'] = $application['id'];
        }

        return $this->encodeResponseBody($response, [
            'success'     => true,
            'message'     => 'Application revoked',
            'application' => $application,
        ]);
    }
}
