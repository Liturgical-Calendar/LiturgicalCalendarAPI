<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\GitHub;

/**
 * Raised whenever the GitHub API responds with a non-2xx status.
 *
 * Carries the HTTP status alongside GitHub's `message` field so callers can distinguish, for
 * example, an expired installation (401/403) from a transient failure (5xx) without re-parsing
 * the response body.
 */
final class GitHubApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }
}
