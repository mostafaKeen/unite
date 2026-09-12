<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * List users (scoped by role)
     */
    public function index(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        $query = User::with('tenant');

        if ($currentUser->isTenantAdmin()) {
            $query->where('tenant_id', $currentUser->tenant_id);
        }

        $users = $query->latest()->get();

        return response()->json([
            'success' => true,
            'users' => $users,
        ]);
    }

    /**
     * Create a new user (super admin can create any; tenant admin can create for their tenant)
     */
    public function store(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        $allowedRoles = $currentUser->isSuperAdmin() 
            ? [User::ROLE_SUPER_ADMIN, User::ROLE_TENANT_ADMIN, User::ROLE_TENANT_USER]
            : [User::ROLE_TENANT_ADMIN, User::ROLE_TENANT_USER];

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::in($allowedRoles)],
            'tenant_id' => $currentUser->isSuperAdmin() ? 'nullable|exists:tenants,id' : 'nullable',
        ]);

        $tenantId = $currentUser->isSuperAdmin() 
            ? ($validated['tenant_id'] ?? null)
            : $currentUser->tenant_id;

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'tenant_id' => $tenantId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'user' => $user->load('tenant'),
        ]);
    }

    /**
     * Update user
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user();

        // Check permission
        if (!$currentUser->canManageTenantUsers($user->tenant)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $allowedRoles = $currentUser->isSuperAdmin() 
            ? [User::ROLE_SUPER_ADMIN, User::ROLE_TENANT_ADMIN, User::ROLE_TENANT_USER]
            : [User::ROLE_TENANT_ADMIN, User::ROLE_TENANT_USER];

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role' => ['sometimes', 'required', Rule::in($allowedRoles)],
            'password' => 'nullable|string|min:8',
            'tenant_id' => $currentUser->isSuperAdmin() ? 'nullable|exists:tenants,id' : 'nullable',
        ]);

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        if (!$currentUser->isSuperAdmin()) {
            unset($validated['tenant_id']);
        }

        $user->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'user' => $user->fresh()->load('tenant'),
        ]);
    }

    /**
     * Delete user
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user();

        if ($user->id === $currentUser->id) {
            return response()->json(['success' => false, 'message' => 'Cannot delete your own account.'], 422);
        }

        if (!$currentUser->canManageTenantUsers($user->tenant)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }
}
