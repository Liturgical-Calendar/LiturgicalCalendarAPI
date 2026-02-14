<?php

namespace LiturgicalCalendar\Api\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;
use UnexpectedValueException;
use DomainException;

/**
 * JWT Service for generating and verifying JSON Web Tokens
 *
 * This service handles:
 * - JWT token generation for authenticated users
 * - JWT token verification
 * - Refresh token generation
 * - Token payload extraction
 *
 * @package LiturgicalCalendar\Api\Services
 */
class JwtService
{
    private string $secret;
    private string $algorithm;
    private int $expiry;
    private int $refreshExpiry;

    /**
     * Create a JwtService configured with a signing secret, algorithm, and token lifetimes.
     *
     * @param string $secret        Signing secret (must be at least 32 characters).
     * @param string $algorithm     Signing algorithm to use (e.g., 'HS256').
     * @param int    $expiry        Access token lifetime in seconds.
     * @param int    $refreshExpiry Refresh token lifetime in seconds.
     *
     * @throws DomainException If the provided secret is shorter than 32 characters.
     */
    public function __construct(
        string $secret,
        string $algorithm = 'HS256',
        int $expiry = 3600,
        int $refreshExpiry = 604800
    ) {
        if (strlen($secret) < 32) {
            throw new DomainException('JWT secret must be at least 32 characters long');
        }

        $this->secret        = $secret;
        $this->algorithm     = $algorithm;
        $this->expiry        = $expiry;
        $this->refreshExpiry = $refreshExpiry;
    }

    /**
     * Create an access JSON Web Token for the given user.
     *
     * @param string $username Username to set as the token subject (`sub` claim).
     * @param array<string,mixed> $claims Additional claims to merge into the token payload.
     * @return string The encoded JWT access token.
     */
    public function generate(string $username, array $claims = []): string
    {
        $issuedAt  = time();
        $expiresAt = $issuedAt + $this->expiry;

        // Merge custom claims first, then standard claims, so standard claims always take precedence
        // This prevents callers from overwriting iss, aud, iat, exp, sub, or type
        $payload = array_merge($claims, [
            'iss'  => $_SERVER['HTTP_HOST'] ?? 'liturgicalcalendar.org',  // Issuer
            'aud'  => $_SERVER['HTTP_HOST'] ?? 'liturgicalcalendar.org',  // Audience
            'iat'  => $issuedAt,                                           // Issued at
            'exp'  => $expiresAt,                                          // Expires at
            'sub'  => $username,                                           // Subject (username)
            'type' => 'access'                                             // Token type
        ]);

        return JWT::encode($payload, $this->secret, $this->algorithm);
    }

    /**
     * Generate a refresh token
     *
     * @param string $username  Username/identifier for the token subject
     * @return string           JWT refresh token
     */
    public function generateRefreshToken(string $username): string
    {
        $issuedAt  = time();
        $expiresAt = $issuedAt + $this->refreshExpiry;

        $payload = [
            'iss'  => $_SERVER['HTTP_HOST'] ?? 'liturgicalcalendar.org',
            'aud'  => $_SERVER['HTTP_HOST'] ?? 'liturgicalcalendar.org',
            'iat'  => $issuedAt,
            'exp'  => $expiresAt,
            'sub'  => $username,
            'type' => 'refresh'
        ];

        return JWT::encode($payload, $this->secret, $this->algorithm);
    }

    /**
     * Verify an access JWT and return its decoded payload.
     *
     * Returns the decoded payload when the token is a valid access token; returns null for expired,
     * malformed, signature-invalid, not-yet-valid, or non-access tokens.
     *
     * @param string $token JWT to verify.
     * @return object|null Decoded token payload if the token is a valid access token, null otherwise.
     */
    public function verify(string $token): ?object
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));

            // Verify token type is 'access'
            if (!isset($decoded->type) || $decoded->type !== 'access') {
                return null;
            }

            return $decoded;
        } catch (ExpiredException $e) {
            // Token has expired
            return null;
        } catch (SignatureInvalidException $e) {
            // Token signature is invalid
            return null;
        } catch (BeforeValidException $e) {
            // Token not yet valid
            return null;
        } catch (UnexpectedValueException $e) {
            // Token malformed or other error
            return null;
        }
    }

    /**
     * Validate a JWT refresh token and return its decoded payload.
     *
     * @param string $token The JWT refresh token string to validate.
     * @return object|null Decoded token payload when the token is valid and has type `refresh`, `null` otherwise.
     */
    public function verifyRefreshToken(string $token): ?object
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));

            // Verify token type is 'refresh'
            if (!isset($decoded->type) || $decoded->type !== 'refresh') {
                return null;
            }

            return $decoded;
        } catch (ExpiredException $e) {
            return null;
        } catch (SignatureInvalidException $e) {
            return null;
        } catch (BeforeValidException $e) {
            return null;
        } catch (UnexpectedValueException $e) {
            return null;
        }
    }

    /**
     * Issue a new access token from a valid refresh token.
     *
     * Validates the provided refresh token; if valid and containing a subject (`sub`),
     * generates a new access token for that subject and preserves any non-standard
     * claims from the refresh token. Returns `null` if the refresh token is invalid,
     * expired, or missing a subject.
     *
     * @param string $refreshToken JWT refresh token.
     * @return string|null New access token on success, `null` otherwise.
     */
    public function refresh(string $refreshToken): ?string
    {
        $payload = $this->verifyRefreshToken($refreshToken);

        if ($payload === null || !isset($payload->sub) || !is_string($payload->sub)) {
            return null;
        }

        $username = $payload->sub;

        // Extract custom claims from the refresh token payload
        // Exclude standard JWT claims that will be regenerated
        $standardClaims = ['iss', 'aud', 'iat', 'exp', 'nbf', 'jti', 'type', 'sub'];
        /** @var array<string, mixed> $customClaims */
        $customClaims = array_diff_key(
            get_object_vars($payload),
            array_flip($standardClaims)
        );

        // Generate new access token with the same subject and custom claims
        return $this->generate($username, $customClaims);
    }

    /**
     * Extracts the JWT subject (`sub` claim) from a token without verifying its signature.
     *
     * Note: this parses the token payload only and does not validate the token; use a verification method before trusting the result.
     *
     * @param string $token The JWT string.
     * @return string|null The `sub` claim (username) if present and a string, or `null` if the token is malformed or `sub` is missing/invalid.
     */
    public function extractUsername(string $token): ?string
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            // Convert base64url to standard base64
            // JWT uses base64url encoding: replace '-' with '+', '_' with '/'
            $base64 = strtr($parts[1], '-_', '+/');
            // Add padding to make length a multiple of 4
            $remainder = strlen($base64) % 4;
            if ($remainder > 0) {
                $base64 .= str_repeat('=', 4 - $remainder);
            }

            $payload = json_decode(base64_decode($base64));
            if (!is_object($payload) || !isset($payload->sub) || !is_string($payload->sub)) {
                return null;
            }
            return $payload->sub;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Access token lifetime in seconds.
     *
     * @return int Access token lifetime in seconds.
     */
    public function getExpiry(): int
    {
        return $this->expiry;
    }

    /**
     * Get the refresh token lifetime in seconds.
     *
     * @return int The refresh token lifetime in seconds.
     */
    public function getRefreshExpiry(): int
    {
        return $this->refreshExpiry;
    }
}
