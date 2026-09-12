<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    /**
     * Handle an incoming request ensuring users only access their assigned tenant.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $tenantParam = $request->route('tenant');
        $tenantId = $tenantParam instanceof Tenant ? $tenantParam->id : $tenantParam;

        if ($tenantId && $user->tenant_id !== $tenantId) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: You do not have access to this tenant company.'
                ], 403);
            }

            abort(403, 'Unauthorized: You do not have access to this tenant company.');
        }

        return $next($request);
    }
}
