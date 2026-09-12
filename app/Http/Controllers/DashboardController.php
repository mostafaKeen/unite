<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\Appointment;
use App\Models\SyncLog;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // 1. Scope tenants query
        $tenantsQuery = Tenant::withCount(['appointments', 'syncLogs']);

        if (!$user->isSuperAdmin()) {
            if ($user->tenant_id) {
                $tenantsQuery->where('id', $user->tenant_id);
            } else {
                $tenantsQuery->whereRaw('1 = 0'); // No tenant assigned
            }
        }

        $tenants = $tenantsQuery->get();
        $tenantIds = $tenants->pluck('id')->toArray();

        // 2. Scope stats query
        $apptQuery = Appointment::query();
        if (!$user->isSuperAdmin()) {
            $apptQuery->whereIn('tenant_id', $tenantIds);
        }

        $stats = [
            'total_tenants' => $tenants->count(),
            'total_appointments' => (clone $apptQuery)->count(),
            'confirmed_appointments' => (clone $apptQuery)->where('status', 'ACF')->count(),
            'pending_appointments' => (clone $apptQuery)->whereIn('status', ['AAC', 'YTC'])->count(),
            'completed_appointments' => (clone $apptQuery)->where('status', 'APH')->count(),
            'total_invoiced' => (clone $apptQuery)->sum('invoice_total') ?? 0,
        ];

        // 3. Scope recent appointments
        $recentAppointments = Appointment::with('tenant')
            ->when(!$user->isSuperAdmin(), fn ($q) => $q->whereIn('tenant_id', $tenantIds))
            ->latest('start_datetime')
            ->take(15)
            ->get();

        // 4. Scope recent logs
        $recentLogs = SyncLog::with('tenant')
            ->when(!$user->isSuperAdmin(), fn ($q) => $q->whereIn('tenant_id', $tenantIds))
            ->latest()
            ->take(15)
            ->get();

        // 5. Users query for User Management tab
        $usersQuery = User::with('tenant');
        if (!$user->isSuperAdmin()) {
            $usersQuery->where('tenant_id', $user->tenant_id);
        }
        $tenantUsers = $usersQuery->latest()->get();

        return Inertia::render('dashboard', [
            'currentUser' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'tenant_id' => $user->tenant_id,
                'can_manage_tenants' => $user->isSuperAdmin(),
                'can_manage_users' => $user->isSuperAdmin() || $user->isTenantAdmin(),
            ],
            'tenants' => $tenants,
            'stats' => $stats,
            'recentAppointments' => $recentAppointments,
            'recentLogs' => $recentLogs,
            'tenantUsers' => $tenantUsers,
        ]);
    }
}
