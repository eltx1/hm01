<?php

namespace App\Http\Middleware;

use App\Enums\OrganizationType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureDashboardAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $permission = match ($request->user()?->organization?->type) {
            OrganizationType::HorusMedia => 'dashboard.admin.view',
            OrganizationType::Publisher => 'dashboard.publisher.view',
            OrganizationType::Advertiser => 'dashboard.advertiser.view',
            OrganizationType::Partner => 'dashboard.partner.view',
            default => null,
        };

        abort_unless($permission && $request->user()?->hasPermission($permission), 403);

        return $next($request);
    }
}
