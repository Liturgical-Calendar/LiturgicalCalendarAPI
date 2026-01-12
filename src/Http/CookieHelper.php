<?php

namespace LiturgicalCalendar\Api\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Cookie Helper for setting secure authentication cookies
 *
 * Provides methods for setting and clearing HttpOnly, Secure cookies
 * for JWT token storage. This approach is more secure than localStorage
 * as HttpOnly cookies are not accessible to JavaScript.
 *
 * @package LiturgicalCalendar\Api\Http
 */
class CookieHelper
{
    public const ACCESS_TOKEN_COOKIE  = 'litcal_access_token';
    public const REFRESH_TOKEN_COOKIE = 'litcal_refresh_token';

    /**
     * Detect if the current request is over HTTPS
     *
     * @return bool True if HTTPS, false otherwise
     */
    public static function isSecure(): bool
    {
        return (
            ( isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https' ) ||
            ( isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ) ||
            ( isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === '443' ) ||
            ( isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' )
        );
    }

    /**
     * Get the domain for cookies (empty string for localhost)
     *
     * For cross-subdomain cookie sharing, set the COOKIE_DOMAIN environment variable
     * to the parent domain with a leading dot (e.g., ".example.com").
     *
     * Example: If your frontend is at frontend.example.com and API is at api.example.com,
     * set COOKIE_DOMAIN=.example.com to share cookies across both subdomains.
     *
     * @return string Domain for Set-Cookie header
     */
    public static function getCookieDomain(): string
    {
        // Check for explicit COOKIE_DOMAIN environment variable first
        // This enables cross-subdomain cookie sharing (e.g., ".example.com")
        $cookieDomain = $_ENV['COOKIE_DOMAIN'] ?? $_SERVER['COOKIE_DOMAIN'] ?? null;
        if (is_string($cookieDomain) && $cookieDomain !== '') {
            return $cookieDomain;
        }

        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

        // Ensure $host is a string before using preg_replace
        if (!is_string($host)) {
            return '';
        }

        // Strip port if present
        $host = preg_replace('/:\d+$/', '', $host);

        // Don't set domain for localhost (browsers reject it)
        if ($host === null || $host === 'localhost' || $host === '127.0.0.1') {
            return '';
        }

        return $host;
    }

    /**
     * Build a Set-Cookie header value
     *
     * @param string $name Cookie name
     * @param string $value Cookie value
     * @param int $maxAge Max age in seconds (0 for session cookie, negative to delete)
     * @param string $path Cookie path
     * @param string $sameSite SameSite attribute (Strict, Lax, or None)
     * @return string Set-Cookie header value
     */
    public static function buildCookieHeader(
        string $name,
        string $value,
        int $maxAge = 0,
        string $path = '/',
        string $sameSite = 'Lax'
    ): string {
        $isSecure = self::isSecure();
        $domain   = self::getCookieDomain();

        $parts = [
            urlencode($name) . '=' . urlencode($value),
            'Path=' . $path,
            'HttpOnly',
        ];

        if ($maxAge > 0) {
            $parts[] = 'Max-Age=' . $maxAge;
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', time() + $maxAge);
        } elseif ($maxAge < 0) {
            // Delete cookie
            $parts[] = 'Max-Age=0';
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', 0);
        }

        if ($isSecure) {
            $parts[] = 'Secure';
        }

        // SameSite=None requires Secure
        if ($sameSite === 'None' && !$isSecure) {
            $sameSite = 'Lax';
        }
        $parts[] = 'SameSite=' . $sameSite;

        if ($domain !== '') {
            $parts[] = 'Domain=' . $domain;
        }

        return implode('; ', $parts);
    }

    /**
     * Set access token cookie on response
     *
     * @param ResponseInterface $response The response to add cookie to
     * @param string $token The access token
     * @param int $expiry Token expiry in seconds
     * @return ResponseInterface Response with Set-Cookie header
     */
    public static function setAccessTokenCookie(
        ResponseInterface $response,
        string $token,
        int $expiry
    ): ResponseInterface {
        $cookie = self::buildCookieHeader(
            self::ACCESS_TOKEN_COOKIE,
            $token,
            $expiry,
            '/',
            'Lax' // Lax allows the cookie to be sent with top-level navigations
        );

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }

    /**
     * Set refresh token cookie on response
     *
     * When rememberMe is false, the cookie is set as a session cookie (no Max-Age/Expires),
     * which means the browser will delete it when closed. When rememberMe is true,
     * the cookie persists for the specified expiry duration.
     *
     * @param ResponseInterface $response The response to add cookie to
     * @param string $token The refresh token
     * @param int $expiry Token expiry in seconds (used only when rememberMe is true)
     * @param bool $rememberMe Whether to persist the cookie beyond the browser session
     * @return ResponseInterface Response with Set-Cookie header
     */
    public static function setRefreshTokenCookie(
        ResponseInterface $response,
        string $token,
        int $expiry,
        bool $rememberMe = false
    ): ResponseInterface {
        // When rememberMe is false, pass 0 for maxAge to create a session cookie
        // When rememberMe is true, use the provided expiry for a persistent cookie
        $maxAge = $rememberMe ? $expiry : 0;

        $cookie = self::buildCookieHeader(
            self::REFRESH_TOKEN_COOKIE,
            $token,
            $maxAge,
            '/auth', // Refresh token only needs to be sent to /auth endpoints
            'Strict' // Strict for refresh token - extra security
        );

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }

    /**
     * Clear all auth cookies (for logout)
     *
     * @param ResponseInterface $response The response to add cookies to
     * @return ResponseInterface Response with Set-Cookie headers to clear cookies
     */
    public static function clearAuthCookies(ResponseInterface $response): ResponseInterface
    {
        // Clear access token
        $response = $response->withAddedHeader(
            'Set-Cookie',
            self::buildCookieHeader(self::ACCESS_TOKEN_COOKIE, '', -1, '/', 'Lax')
        );

        // Clear refresh token
        $response = $response->withAddedHeader(
            'Set-Cookie',
            self::buildCookieHeader(self::REFRESH_TOKEN_COOKIE, '', -1, '/auth', 'Strict')
        );

        return $response;
    }

    /**
     * Get access token from cookies
     *
     * @param array<string, string> $cookies Cookie array from request
     * @return string|null Access token or null if not found
     */
    public static function getAccessToken(array $cookies): ?string
    {
        return $cookies[self::ACCESS_TOKEN_COOKIE] ?? null;
    }

    /**
     * Get refresh token from cookies
     *
     * @param array<string, string> $cookies Cookie array from request
     * @return string|null Refresh token or null if not found
     */
    public static function getRefreshToken(array $cookies): ?string
    {
        return $cookies[self::REFRESH_TOKEN_COOKIE] ?? null;
    }
}
