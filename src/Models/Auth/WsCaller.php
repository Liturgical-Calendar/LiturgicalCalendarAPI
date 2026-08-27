<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Auth;

/**
 * The identity of one WebSocket connection, settled once at handshake time.
 *
 * Settled *once* rather than per message because the handshake is the only moment the credential is
 * on the wire: a WebSocket frame carries no headers, so a connection that was not identified as it
 * opened can never be identified afterwards.
 *
 * Anonymity is a state here, not a failure. {@see \LiturgicalCalendar\Api\Services\WsCallerResolver}
 * answers with an anonymous caller for a missing cookie, an expired token and a forged one alike,
 * because the connection is accepted either way and the refusal happens per action — see
 * {@see \LiturgicalCalendar\Api\Services\TestRunPolicy}.
 */
final readonly class WsCaller
{
    /**
     * @param array<int, string> $roles
     */
    private function __construct(
        public bool $authenticated,
        public ?string $sub,
        public array $roles
    ) {
    }

    public static function anonymous(): self
    {
        return new self(false, null, []);
    }

    /**
     * @param array<int, string> $roles
     */
    public static function authenticated(string $sub, array $roles): self
    {
        /** @var array<int, string> $clean */
        $clean = array_values(array_unique(array_filter($roles, 'is_string')));

        return new self(true, $sub, $clean);
    }

    public function hasAnyRole(string ...$roles): bool
    {
        return [] !== array_intersect($roles, $this->roles);
    }
}
