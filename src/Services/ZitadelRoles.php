<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * The Zitadel project-roles claim, read the one way.
 *
 * Zitadel returns roles as an object keyed by role name — `{"admin": {"org_id": "1"}}` — so the role
 * names are the *keys*, not the values. Reading it as a list is the mistake this class exists to
 * stop being made twice: {@see \LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware} and
 * {@see WsCallerResolver} both need the answer, and a caller whose roles are read differently by the
 * HTTP path and the WebSocket path is authorized differently by each.
 */
final class ZitadelRoles
{
    public const CLAIM = 'urn:zitadel:iam:org:project:roles';

    /**
     * @return array<int, string>
     */
    public static function fromPayload(object $payload): array
    {
        if (false === isset($payload->{self::CLAIM})) {
            return [];
        }

        $claim = $payload->{self::CLAIM};
        if (false === is_object($claim) && false === is_array($claim)) {
            return [];
        }

        /** @var array<int, string> $roles */
        $roles = array_values(array_filter(array_keys((array) $claim), 'is_string'));

        return $roles;
    }
}
