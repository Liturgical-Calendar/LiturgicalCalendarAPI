<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use GuzzleHttp\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * PSR-18 HTTP client for OpenFGA authorization checks.
 *
 * Communicates with the OpenFGA REST API to check, write, and delete
 * relationship tuples for fine-grained authorization.
 */
class OpenFgaClient
{
    private string $apiUrl;
    private string $storeId;
    private string $modelId;
    private string $apiToken;
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;

    public function __construct(
        string $apiUrl,
        string $storeId,
        string $modelId,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        string $apiToken = ''
    ) {
        $this->apiUrl         = rtrim($apiUrl, '/');
        $this->storeId        = $storeId;
        $this->modelId        = $modelId;
        $this->httpClient     = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory  = $streamFactory;
        $this->apiToken       = $apiToken;
    }

    /**
     * Create an OpenFgaClient from environment variables.
     *
     * Uses Guzzle (PSR-18) and Nyholm (PSR-17) as default implementations.
     *
     * @throws RuntimeException If required environment variables are missing
     */
    public static function fromEnv(): self
    {
        $apiUrl  = self::getEnvString('OPENFGA_API_URL');
        $storeId = self::getEnvString('OPENFGA_STORE_ID');
        $modelId = self::getEnvString('OPENFGA_MODEL_ID');

        if ($apiUrl === '' || $storeId === '' || $modelId === '') {
            throw new RuntimeException(
                'OpenFGA not configured. Set OPENFGA_API_URL, OPENFGA_STORE_ID, and OPENFGA_MODEL_ID.'
            );
        }

        $apiToken   = self::getEnvString('OPENFGA_API_TOKEN');
        $httpClient = new Client(['timeout' => 5, 'connect_timeout' => 2]);
        $psr17      = new Psr17Factory();

        return new self($apiUrl, $storeId, $modelId, $httpClient, $psr17, $psr17, $apiToken);
    }

    /**
     * Check if required environment variables are configured.
     */
    public static function isConfigured(): bool
    {
        $apiUrl  = self::getEnvString('OPENFGA_API_URL');
        $storeId = self::getEnvString('OPENFGA_STORE_ID');
        $modelId = self::getEnvString('OPENFGA_MODEL_ID');

        return $apiUrl !== '' && $storeId !== '' && $modelId !== '';
    }

    /**
     * Get an environment variable as a string.
     */
    private static function getEnvString(string $name): string
    {
        $value = $_ENV[$name] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        $envValue = getenv($name);
        if (is_string($envValue) && trim($envValue) !== '') {
            return trim($envValue);
        }

        return '';
    }

    /**
     * Check if a user has a specific relation on an object.
     *
     * @param string $user    User identifier (e.g., "user:zitadel-user-id")
     * @param string $relation Relation to check (e.g., "editor", "deleter", "viewer")
     * @param string $object   Object identifier (e.g., "national_calendar:IT")
     * @return bool True if the user has the relation
     * @throws RuntimeException If the API request fails
     */
    public function check(string $user, string $relation, string $object): bool
    {
        $payload = [
            'tuple_key'              => [
                'user'     => $user,
                'relation' => $relation,
                'object'   => $object,
            ],
            'authorization_model_id' => $this->modelId,
        ];

        $response = $this->post("/stores/{$this->storeId}/check", $payload);

        return isset($response['allowed']) && $response['allowed'] === true;
    }

    /**
     * Write a relationship tuple.
     *
     * @param string $user     User identifier
     * @param string $relation Relation name
     * @param string $object   Object identifier
     * @throws RuntimeException If the API request fails
     */
    public function writeTuple(string $user, string $relation, string $object): void
    {
        $payload = [
            'writes'                 => [
                'tuple_keys' => [
                    [
                        'user'     => $user,
                        'relation' => $relation,
                        'object'   => $object,
                    ],
                ],
            ],
            'authorization_model_id' => $this->modelId,
        ];

        $this->post("/stores/{$this->storeId}/write", $payload);
    }

    /**
     * Delete a relationship tuple.
     *
     * @param string $user     User identifier
     * @param string $relation Relation name
     * @param string $object   Object identifier
     * @throws RuntimeException If the API request fails
     */
    public function deleteTuple(string $user, string $relation, string $object): void
    {
        $payload = [
            'deletes'                => [
                'tuple_keys' => [
                    [
                        'user'     => $user,
                        'relation' => $relation,
                        'object'   => $object,
                    ],
                ],
            ],
            'authorization_model_id' => $this->modelId,
        ];

        $this->post("/stores/{$this->storeId}/write", $payload);
    }

    /**
     * Read relationship tuples for a user and object type.
     *
     * @param string      $user     User identifier (e.g., "user:zitadel-user-id")
     * @param string      $object   Object type or full object (e.g., "national_calendar:" or "national_calendar:IT")
     * @param string|null $relation Optional relation filter
     * @return array<int, array{user: string, relation: string, object: string}> List of tuples
     * @throws RuntimeException If the API request fails
     */
    public function readTuples(string $user, string $object, ?string $relation = null): array
    {
        $tupleKey = [];

        if ($user !== '') {
            $tupleKey['user'] = $user;
        }

        if ($object !== '') {
            $tupleKey['object'] = $object;
        }

        if ($relation !== null && $relation !== '') {
            $tupleKey['relation'] = $relation;
        }

        $payload = ['tuple_key' => $tupleKey];

        $tuples   = [];
        $maxPages = 100;
        $page     = 0;

        // Paginate through all results using continuation_token
        do {
            $response = $this->post("/stores/{$this->storeId}/read", $payload);

            $responseTuples = $response['tuples'] ?? [];
            if (is_array($responseTuples)) {
                foreach ($responseTuples as $tuple) {
                    if (!is_array($tuple)) {
                        continue;
                    }
                    $key  = is_array($tuple['key'] ?? null) ? $tuple['key'] : [];
                    $user = is_string($key['user'] ?? null) ? $key['user'] : '';
                    $rel  = is_string($key['relation'] ?? null) ? $key['relation'] : '';
                    $obj  = is_string($key['object'] ?? null) ? $key['object'] : '';

                    // Skip malformed tuples with missing fields
                    if ($user === '' || $rel === '' || $obj === '') {
                        continue;
                    }

                    $tuples[] = [
                        'user'     => $user,
                        'relation' => $rel,
                        'object'   => $obj,
                    ];
                }
            }

            $continuationToken = is_string($response['continuation_token'] ?? null)
                ? $response['continuation_token']
                : '';

            if ($continuationToken !== '') {
                $payload['continuation_token'] = $continuationToken;
            }

            $page++;
        } while ($continuationToken !== '' && $page < $maxPages);

        return $tuples;
    }

    /**
     * Send a POST request to the OpenFGA API.
     *
     * @param string               $path    API path (e.g., "/stores/{id}/check")
     * @param array<string, mixed> $payload Request body
     * @return array<string, mixed> Decoded JSON response
     * @throws RuntimeException If the request fails
     */
    private function post(string $path, array $payload): array
    {
        $url  = $this->apiUrl . $path;
        $json = json_encode($payload);

        if ($json === false) {
            throw new RuntimeException('Failed to encode OpenFGA request payload');
        }

        $body    = $this->streamFactory->createStream($json);
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($body);

        if ($this->apiToken !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->apiToken);
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new RuntimeException(
                sprintf('OpenFGA request failed: %s', $e->getMessage()),
                0,
                $e
            );
        }

        $responseBody = (string) $response->getBody();
        $httpCode     = $response->getStatusCode();

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($responseBody, true);

        if (!is_array($decoded)) {
            $decoded = [];
        }

        // OpenFGA returns 200 for successful checks and writes.
        // Non-200 responses indicate errors.
        if ($httpCode !== 200) {
            $message = is_string($decoded['message'] ?? null) ? $decoded['message'] : 'Unknown error';
            throw new RuntimeException(
                sprintf('OpenFGA API error (HTTP %d): %s', $httpCode, $message)
            );
        }

        return $decoded;
    }
}
