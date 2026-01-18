<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Service for interacting with Zitadel Management API.
 *
 * Used for administrative operations such as:
 * - Fetching user details
 * - Managing roles
 * - Creating service accounts
 */
class ZitadelService
{
    private Client $httpClient;
    private string $issuer;
    private string $projectId;
    private ?string $machineToken;
    private ?LoggerInterface $logger;

    /** @var array<string, mixed>|null */
    private ?array $discoveryDocument = null;

    /**
     * Timestamp when discovery document was fetched.
     */
    private ?int $discoveryFetchedAt = null;

    /**
     * TTL for cached discovery document (1 hour).
     */
    private const DISCOVERY_TTL_SECONDS = 3600;

    /**
     * Create Zitadel service.
     *
     * @param string $issuer Zitadel issuer URL
     * @param string $projectId Zitadel project ID
     * @param string|null $machineToken Service account token for management API
     * @param LoggerInterface|null $logger Optional logger for error logging
     */
    public function __construct(
        string $issuer,
        string $projectId,
        ?string $machineToken = null,
        ?LoggerInterface $logger = null
    ) {
        $this->issuer       = rtrim($issuer, '/');
        $this->projectId    = $projectId;
        $this->machineToken = $machineToken;
        $this->logger       = $logger;
        $this->httpClient   = new Client([
            'base_uri' => $this->issuer,
            'timeout'  => 30,
        ]);
    }

    /**
     * Check if Zitadel service is configured.
     *
     * @return bool True if required environment variables are set
     */
    public static function isConfigured(): bool
    {
        // Check both getenv() and $_ENV since Dotenv may not always populate putenv()
        $issuer    = getenv('ZITADEL_ISSUER') ?: ( $_ENV['ZITADEL_ISSUER'] ?? '' );
        $projectId = getenv('ZITADEL_PROJECT_ID') ?: ( $_ENV['ZITADEL_PROJECT_ID'] ?? '' );
        $token     = getenv('ZITADEL_MACHINE_TOKEN') ?: ( $_ENV['ZITADEL_MACHINE_TOKEN'] ?? '' );

        return !empty($issuer) && !empty($projectId) && !empty($token);
    }

    /**
     * Create service from environment variables.
     *
     * @param LoggerInterface|null $logger Optional logger for error logging
     * @return self
     * @throws \RuntimeException If required environment variables are missing
     */
    public static function fromEnv(?LoggerInterface $logger = null): self
    {
        // Check both getenv() and $_ENV since Dotenv may not always populate putenv()
        $issuerEnv       = getenv('ZITADEL_ISSUER') ?: ( $_ENV['ZITADEL_ISSUER'] ?? '' );
        $projectIdEnv    = getenv('ZITADEL_PROJECT_ID') ?: ( $_ENV['ZITADEL_PROJECT_ID'] ?? '' );
        $machineTokenEnv = getenv('ZITADEL_MACHINE_TOKEN') ?: ( $_ENV['ZITADEL_MACHINE_TOKEN'] ?? null );

        $issuer       = is_string($issuerEnv) ? $issuerEnv : '';
        $projectId    = is_string($projectIdEnv) ? $projectIdEnv : '';
        $machineToken = is_string($machineTokenEnv) ? $machineTokenEnv : null;

        if (empty($issuer) || empty($projectId)) {
            throw new \RuntimeException(
                'Missing required environment variables: ZITADEL_ISSUER, ZITADEL_PROJECT_ID'
            );
        }

        return new self($issuer, $projectId, $machineToken, $logger);
    }

    /**
     * Get user information by ID.
     *
     * @param string $userId Zitadel user ID
     * @return array<string, mixed>|null User data or null if not found
     */
    public function getUser(string $userId): ?array
    {
        try {
            $response = $this->httpClient->get("/management/v1/users/{$userId}", [
                'headers' => $this->getAuthHeaders(),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data)) {
                return null;
            }
            $user = $data['user'] ?? null;
            /** @var array<string, mixed>|null */
            return is_array($user) ? $user : null;
        } catch (GuzzleException $e) {
            $this->logger?->warning('Failed to fetch user from Zitadel', [
                'userId' => $userId,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get user roles for the project.
     *
     * @param string $userId Zitadel user ID
     * @return array<string> List of role names
     */
    public function getUserRoles(string $userId): array
    {
        try {
            $response = $this->httpClient->post('/management/v1/users/grants/_search', [
                'headers' => $this->getAuthHeaders(),
                'json'    => [
                    'queries' => [
                        [
                            'userIdQuery' => ['userId' => $userId],
                        ],
                        [
                            'projectIdQuery' => [
                                'projectId' => $this->projectId,
                            ],
                        ],
                    ],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data)) {
                return [];
            }
            $grants = $data['result'] ?? [];

            if (!is_array($grants)) {
                return [];
            }

            /** @var array<string> $roles */
            $roles = [];
            foreach ($grants as $grant) {
                if (is_array($grant) && isset($grant['roleKeys']) && is_array($grant['roleKeys'])) {
                    foreach ($grant['roleKeys'] as $roleKey) {
                        if (is_string($roleKey)) {
                            $roles[] = $roleKey;
                        }
                    }
                }
            }

            return array_values(array_unique($roles));
        } catch (GuzzleException $e) {
            $this->logger?->warning('Failed to fetch user roles from Zitadel', [
                'userId' => $userId,
                'error'  => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Assign a role to a user (alias for grantRole).
     *
     * @param string $userId Zitadel user ID
     * @param string $role Role name to assign
     * @return bool True if successful
     */
    public function assignUserRole(string $userId, string $role): bool
    {
        return $this->grantRole($userId, $role);
    }

    /**
     * Grant a role to a user.
     *
     * @param string $userId Zitadel user ID
     * @param string $role Role name to grant
     * @return bool True if successful
     */
    public function grantRole(string $userId, string $role): bool
    {
        try {
            $this->httpClient->post("/management/v1/users/{$userId}/grants", [
                'headers' => $this->getAuthHeaders(),
                'json'    => [
                    'projectId' => $this->projectId,
                    'roleKeys'  => [$role],
                ],
            ]);

            return true;
        } catch (GuzzleException $e) {
            $this->logger?->warning('Failed to grant role in Zitadel', [
                'userId' => $userId,
                'role'   => $role,
                'error'  => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Revoke a role from a user.
     *
     * @param string $userId Zitadel user ID
     * @param string $grantId User grant ID
     * @return bool True if successful
     */
    public function revokeGrant(string $userId, string $grantId): bool
    {
        try {
            $this->httpClient->delete("/management/v1/users/{$userId}/grants/{$grantId}", [
                'headers' => $this->getAuthHeaders(),
            ]);

            return true;
        } catch (GuzzleException $e) {
            $this->logger?->warning('Failed to revoke grant in Zitadel', [
                'userId'  => $userId,
                'grantId' => $grantId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * List all user grants for the project.
     *
     * Returns users who have been granted roles in this project.
     *
     * @param int $limit Maximum number of results (default 100)
     * @param int $offset Offset for pagination (default 0)
     * @return array{users: array<int, array<string, mixed>>, total: int}
     */
    public function listProjectUsers(int $limit = 100, int $offset = 0): array
    {
        try {
            $response = $this->httpClient->post('/management/v1/users/grants/_search', [
                'headers' => $this->getAuthHeaders(),
                'json'    => [
                    'queries' => [
                        [
                            'projectIdQuery' => [
                                'projectId' => $this->projectId,
                            ],
                        ],
                    ],
                    'limit'   => $limit,
                    'offset'  => $offset,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data)) {
                return ['users' => [], 'total' => 0];
            }

            $grants  = $data['result'] ?? [];
            $details = $data['details'] ?? [];
            $total   = 0;
            if (is_array($details) && isset($details['totalResult'])) {
                $totalResult = $details['totalResult'];
                $total       = is_int($totalResult) || is_string($totalResult) ? (int) $totalResult : 0;
            }

            if (!is_array($grants)) {
                return ['users' => [], 'total' => 0];
            }

            // Group grants by user and include grant details
            $usersMap = [];
            foreach ($grants as $grant) {
                if (!is_array($grant)) {
                    continue;
                }

                $userId = $grant['userId'] ?? null;
                if (!is_string($userId)) {
                    continue;
                }

                if (!isset($usersMap[$userId])) {
                    $usersMap[$userId] = [
                        'userId'      => $userId,
                        'username'    => $grant['userName'] ?? '',
                        'email'       => $grant['email'] ?? '',
                        'displayName' => $grant['displayName'] ?? '',
                        'firstName'   => $grant['firstName'] ?? '',
                        'lastName'    => $grant['lastName'] ?? '',
                        'grants'      => [],
                    ];
                }

                $grantId  = $grant['id'] ?? null;
                $roleKeys = $grant['roleKeys'] ?? [];

                if (is_string($grantId) && is_array($roleKeys)) {
                    $usersMap[$userId]['grants'][] = [
                        'grantId' => $grantId,
                        'roles'   => array_values(array_filter($roleKeys, 'is_string')),
                        'state'   => $grant['state'] ?? null,
                    ];
                }
            }

            /** @var array<int, array<string, mixed>> $users */
            $users = array_values($usersMap);

            return ['users' => $users, 'total' => $total];
        } catch (GuzzleException $e) {
            $this->logger?->warning('Failed to list project users from Zitadel', [
                'projectId' => $this->projectId,
                'error'     => $e->getMessage(),
            ]);
            return ['users' => [], 'total' => 0];
        }
    }

    /**
     * Get all grants for a specific user in the project.
     *
     * @param string $userId Zitadel user ID
     * @return array<int, array{grantId: string, roles: array<string>, state: mixed}>
     */
    public function getUserGrantsWithIds(string $userId): array
    {
        try {
            $response = $this->httpClient->post('/management/v1/users/grants/_search', [
                'headers' => $this->getAuthHeaders(),
                'json'    => [
                    'queries' => [
                        [
                            'userIdQuery' => ['userId' => $userId],
                        ],
                        [
                            'projectIdQuery' => [
                                'projectId' => $this->projectId,
                            ],
                        ],
                    ],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data)) {
                return [];
            }

            $grants = $data['result'] ?? [];
            if (!is_array($grants)) {
                return [];
            }

            $result = [];
            foreach ($grants as $grant) {
                if (!is_array($grant)) {
                    continue;
                }

                $grantId  = $grant['id'] ?? null;
                $roleKeys = $grant['roleKeys'] ?? [];

                if (is_string($grantId) && is_array($roleKeys)) {
                    $result[] = [
                        'grantId' => $grantId,
                        'roles'   => array_values(array_filter($roleKeys, 'is_string')),
                        'state'   => $grant['state'] ?? null,
                    ];
                }
            }

            return $result;
        } catch (GuzzleException $e) {
            $this->logger?->warning('Failed to get user grants from Zitadel', [
                'userId' => $userId,
                'error'  => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Update roles in an existing grant.
     *
     * Uses PUT to replace the roles array in a grant.
     *
     * @param string $userId Zitadel user ID
     * @param string $grantId Grant ID
     * @param array<string> $roles New roles array
     * @return bool True if successful
     */
    public function updateGrant(string $userId, string $grantId, array $roles): bool
    {
        try {
            $this->httpClient->put("/management/v1/users/{$userId}/grants/{$grantId}", [
                'headers' => $this->getAuthHeaders(),
                'json'    => ['roleKeys' => $roles],
            ]);

            return true;
        } catch (GuzzleException $e) {
            $this->logger?->warning('Failed to update grant in Zitadel', [
                'userId'  => $userId,
                'grantId' => $grantId,
                'roles'   => $roles,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Revoke a specific role from a user.
     *
     * Finds the grant containing the role and either:
     * - Updates the grant with remaining roles (if multiple roles exist)
     * - Revokes the entire grant (if this is the only role)
     *
     * @param string $userId Zitadel user ID
     * @param string $role Role to revoke
     * @return bool True if successful
     */
    public function revokeUserRole(string $userId, string $role): bool
    {
        // Find the grant containing this role
        $grants = $this->getUserGrantsWithIds($userId);

        foreach ($grants as $grant) {
            if (in_array($role, $grant['roles'], true)) {
                $remainingRoles = array_values(array_filter(
                    $grant['roles'],
                    fn(string $r): bool => $r !== $role
                ));

                // If this was the only role, revoke the entire grant
                if (empty($remainingRoles)) {
                    return $this->revokeGrant($userId, $grant['grantId']);
                }

                // Otherwise, update the grant with remaining roles
                return $this->updateGrant($userId, $grant['grantId'], $remainingRoles);
            }
        }

        $this->logger?->warning('Role not found for user in Zitadel', [
            'userId' => $userId,
            'role'   => $role,
        ]);

        return false;
    }

    /**
     * Search for users by email.
     *
     * @param string $email Email to search for
     * @return array<int, array<string, mixed>> List of matching users
     */
    public function searchUsersByEmail(string $email): array
    {
        try {
            $response = $this->httpClient->post('/management/v1/users/_search', [
                'headers' => $this->getAuthHeaders(),
                'json'    => [
                    'queries' => [
                        [
                            'emailQuery' => [
                                'emailAddress' => $email,
                                'method'       => 'TEXT_QUERY_METHOD_EQUALS',
                            ],
                        ],
                    ],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data)) {
                return [];
            }
            $result = $data['result'] ?? [];
            /** @var array<int, array<string, mixed>> */
            return is_array($result) ? $result : [];
        } catch (GuzzleException $e) {
            $this->logger?->warning('Failed to search users by email in Zitadel', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get OIDC discovery document.
     *
     * The document is cached after the first successful fetch to avoid
     * repeated HTTP requests.
     *
     * @return array<string, mixed>|null Discovery document or null on error
     */
    public function getDiscoveryDocument(): ?array
    {
        // Return cached document if still valid
        if ($this->discoveryDocument !== null && $this->discoveryFetchedAt !== null) {
            if (time() - $this->discoveryFetchedAt < self::DISCOVERY_TTL_SECONDS) {
                return $this->discoveryDocument;
            }
        }

        try {
            $response = $this->httpClient->get('/.well-known/openid-configuration');
            $data     = json_decode($response->getBody()->getContents(), true);
            if (is_array($data)) {
                /** @var array<string, mixed> $data */
                $this->discoveryDocument  = $data;
                $this->discoveryFetchedAt = time();
            }
            return $this->discoveryDocument;
        } catch (GuzzleException $e) {
            $this->logger?->warning('Failed to fetch OIDC discovery document from Zitadel', [
                'issuer' => $this->issuer,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get authorization endpoint URL.
     *
     * @return string|null Authorization endpoint or null
     */
    public function getAuthorizationEndpoint(): ?string
    {
        $doc = $this->getDiscoveryDocument();
        if ($doc === null) {
            return null;
        }
        $endpoint = $doc['authorization_endpoint'] ?? null;
        return is_string($endpoint) ? $endpoint : null;
    }

    /**
     * Get token endpoint URL.
     *
     * @return string|null Token endpoint or null
     */
    public function getTokenEndpoint(): ?string
    {
        $doc = $this->getDiscoveryDocument();
        if ($doc === null) {
            return null;
        }
        $endpoint = $doc['token_endpoint'] ?? null;
        return is_string($endpoint) ? $endpoint : null;
    }

    /**
     * Get userinfo endpoint URL.
     *
     * @return string|null Userinfo endpoint or null
     */
    public function getUserinfoEndpoint(): ?string
    {
        $doc = $this->getDiscoveryDocument();
        if ($doc === null) {
            return null;
        }
        $endpoint = $doc['userinfo_endpoint'] ?? null;
        return is_string($endpoint) ? $endpoint : null;
    }

    /**
     * Get end session endpoint URL.
     *
     * @return string|null End session endpoint or null
     */
    public function getEndSessionEndpoint(): ?string
    {
        $doc = $this->getDiscoveryDocument();
        if ($doc === null) {
            return null;
        }
        $endpoint = $doc['end_session_endpoint'] ?? null;
        return is_string($endpoint) ? $endpoint : null;
    }

    /**
     * Get JWKS URI.
     *
     * @return string|null JWKS URI or null
     */
    public function getJwksUri(): ?string
    {
        $doc = $this->getDiscoveryDocument();
        if ($doc === null) {
            return null;
        }
        $uri = $doc['jwks_uri'] ?? null;
        return is_string($uri) ? $uri : null;
    }

    /**
     * Check if user has a specific role.
     *
     * @param string $userId Zitadel user ID
     * @param string $role Role to check
     * @return bool True if user has the role
     */
    public function userHasRole(string $userId, string $role): bool
    {
        $roles = $this->getUserRoles($userId);
        return in_array($role, $roles, true);
    }

    /**
     * Get the issuer URL.
     *
     * @return string Issuer URL
     */
    public function getIssuer(): string
    {
        return $this->issuer;
    }

    /**
     * Get the project ID.
     *
     * @return string Project ID
     */
    public function getProjectId(): string
    {
        return $this->projectId;
    }

    /**
     * Get authorization headers for Management API.
     *
     * @return array<string, string> Headers
     * @throws \RuntimeException If machine token is not configured
     */
    private function getAuthHeaders(): array
    {
        if ($this->machineToken === null) {
            throw new \RuntimeException(
                'Zitadel Management API requires a machine token. ' .
                'Set ZITADEL_MACHINE_TOKEN environment variable or pass machineToken to constructor.'
            );
        }

        return [
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->machineToken,
        ];
    }
}
