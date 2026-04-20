<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * Utility for deriving the Host header value from a Zitadel issuer URL.
 *
 * When running in Docker, server-side requests to Zitadel are routed
 * via the Docker service name (e.g., http://zitadel:8080) but Zitadel
 * requires the Host header to match its configured external domain
 * (e.g., localhost:8080). This helper extracts the host[:port] from the
 * public issuer URL for use as a Host header value.
 */
class ZitadelHostHeader
{
    /**
     * Derive the Host header value from a Zitadel issuer URL.
     *
     * Returns the host and optional port (e.g., "localhost:8080") extracted
     * from the given URL.
     *
     * @param string $issuer The public Zitadel issuer URL
     * @return string Host header value (host[:port])
     * @throws \RuntimeException If the URL is malformed or missing a host component
     */
    public static function deriveFromIssuer(string $issuer): string
    {
        $parsed = parse_url($issuer);
        if (!is_array($parsed) || empty($parsed['host'])) {
            throw new \RuntimeException('Invalid ZITADEL_ISSUER URL (missing host): ' . $issuer);
        }
        return $parsed['host']
            . ( isset($parsed['port']) ? ':' . $parsed['port'] : '' );
    }
}
