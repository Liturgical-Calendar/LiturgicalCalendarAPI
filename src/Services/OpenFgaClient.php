<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use RuntimeException;

/**
 * HTTP client for OpenFGA authorization checks.
 *
 * Communicates with the OpenFGA REST API to check, write, and delete
 * relationship tuples for fine-grained authorization.
 */
class OpenFgaClient
{
    private string $apiUrl;
    private string $storeId;
    private string $modelId;

    public function __construct(string $apiUrl, string $storeId, string $modelId)
    {
        $this->apiUrl  = rtrim($apiUrl, '/');
        $this->storeId = $storeId;
        $this->modelId = $modelId;
    }

    /**
     * Create an OpenFgaClient from environment variables.
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

        return new self($apiUrl, $storeId, $modelId);
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
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $envValue = getenv($name);
        if (is_string($envValue) && $envValue !== '') {
            return $envValue;
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
        $tupleKey = [
            'user'   => $user,
            'object' => $object,
        ];

        if ($relation !== null) {
            $tupleKey['relation'] = $relation;
        }

        $payload = [
            'tuple_key'              => $tupleKey,
            'authorization_model_id' => $this->modelId,
        ];

        $response = $this->post("/stores/{$this->storeId}/read", $payload);

        $tuples         = [];
        $responseTuples = $response['tuples'] ?? [];
        if (!is_array($responseTuples)) {
            return $tuples;
        }

        foreach ($responseTuples as $tuple) {
            if (!is_array($tuple)) {
                continue;
            }
            $key      = is_array($tuple['key'] ?? null) ? $tuple['key'] : [];
            $tuples[] = [
                'user'     => is_string($key['user'] ?? null) ? $key['user'] : '',
                'relation' => is_string($key['relation'] ?? null) ? $key['relation'] : '',
                'object'   => is_string($key['object'] ?? null) ? $key['object'] : '',
            ];
        }

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

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL for OpenFGA request');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        unset($ch);

        if ($responseBody === false || $curlError !== '') {
            throw new RuntimeException(
                sprintf('OpenFGA request failed: %s', $curlError)
            );
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $responseBody, true);

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
