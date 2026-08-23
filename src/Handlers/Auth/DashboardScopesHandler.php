<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Services\ResourceAdminService;

/**
 * Dashboard Scopes Handler
 *
 * GET /auth/dashboard-scopes — batched capability report for the frontend admin
 * dashboard (LiturgicalCalendarFrontend#399). One round-trip returns everything
 * the dashboard needs to gate its cards server-side:
 *   - is_global_admin: the Zitadel `admin` role is present in the token.
 *   - is_resource_admin / admin_scopes: same semantics as GET /auth/admin-scopes.
 *   - viewer_scopes: object IDs the caller can view (viewer-or-above; the FGA
 *     model unions `viewer` with `editor` and `admin`), keyed by object type,
 *     across ResourceAdminService::VIEWER_OBJECT_TYPES.
 *
 * Fails closed: when OpenFGA is unavailable, all scope lists are empty, but
 * is_global_admin is still honored from the token. Every viewer_scopes key is
 * present either way — to the dashboard, a missing key and an empty one mean
 * different things.
 */
final class DashboardScopesHandler extends AbstractScopesHandler
{
    /**
     * @return array<string, mixed>
     */
    protected function buildScopePayload(string $sub, bool $isGlobalAdmin, ?ResourceAdminService $service): array
    {
        $adminScopes  = $service?->resolveScopes($sub) ?? [];
        $viewerScopes = $service?->resolveViewerScopes($sub)
            ?? array_fill_keys(ResourceAdminService::VIEWER_OBJECT_TYPES, []);

        return [
            'is_global_admin'   => $isGlobalAdmin,
            'is_resource_admin' => $adminScopes !== [],
            'admin_scopes'      => $adminScopes,
            'viewer_scopes'     => $viewerScopes,
        ];
    }
}
