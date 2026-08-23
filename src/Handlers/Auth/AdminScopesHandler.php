<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Services\ResourceAdminService;

/**
 * Admin Scopes Handler
 *
 * GET /auth/admin-scopes — report the authenticated caller's admin status:
 *   - is_global_admin: the Zitadel `admin` role is present in the token.
 *   - admin_scopes: union of OpenFGA `admin` tuples across the admin-capable
 *     object types, as [{object_type, object_id}].
 *   - is_resource_admin: admin_scopes is non-empty.
 *
 * Fails closed: when OpenFGA is unavailable, admin_scopes is empty and
 * is_resource_admin is false, but is_global_admin is still honored from the token.
 */
final class AdminScopesHandler extends AbstractScopesHandler
{
    /**
     * @return array<string, mixed>
     */
    protected function buildScopePayload(string $sub, bool $isGlobalAdmin, ?ResourceAdminService $service): array
    {
        $scopes = $service?->resolveScopes($sub) ?? [];

        return [
            'is_global_admin'   => $isGlobalAdmin,
            'is_resource_admin' => $scopes !== [],
            'admin_scopes'      => $scopes,
        ];
    }
}
