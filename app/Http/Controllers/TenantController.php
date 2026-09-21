<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Unite\UniteClient;
use App\Services\Bitrix\BitrixService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class TenantController extends Controller
{
    public function __construct(
        protected UniteClient $uniteClient,
        protected BitrixService $bitrixService
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'b24_domain' => 'nullable|string|max:255',
            'b24_client_id' => 'nullable|string',
            'b24_client_secret' => 'nullable|string',
            'unite_environment' => 'required|in:sandbox,production',
            'unite_base_url' => 'required|url',
            'unite_app_id' => 'nullable|string',
            'unite_app_key' => 'nullable|string',
            'unite_initial_token' => 'nullable|string',
            'default_clinic_id' => 'nullable|string',
        ]);

        if (empty($validated['b24_client_secret'])) {
            $validated['b24_client_secret'] = null;
        }
        if (empty($validated['unite_app_key'])) {
            $validated['unite_app_key'] = null;
        }
        if (empty($validated['unite_initial_token'])) {
            $validated['unite_initial_token'] = null;
        }

        $slug = Str::slug($validated['name']);
        if (Tenant::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::random(4);
        }

        $tenant = Tenant::create(array_merge($validated, [
            'slug' => $slug,
            'status' => 'active',
        ]));

        // Attempt initial sync of clinics and doctors
        try {
            $this->uniteClient->getClinics($tenant, true);
            $this->uniteClient->getDoctors($tenant, true);
            $this->uniteClient->getItemDetails($tenant, true);
        } catch (\Exception $e) {
            // Non-blocking
        }

        return response()->json([
            'success' => true,
            'message' => 'Company tenant registered successfully',
            'tenant' => $tenant->fresh(),
        ]);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'status' => 'sometimes|in:active,inactive,suspended',
            'b24_domain' => 'nullable|string|max:255',
            'b24_client_id' => 'nullable|string',
            'b24_client_secret' => 'nullable|string',
            'unite_environment' => 'sometimes|in:sandbox,production',
            'unite_base_url' => 'sometimes|url',
            'unite_app_id' => 'nullable|string',
            'unite_app_key' => 'nullable|string',
            'unite_initial_token' => 'nullable|string',
            'default_clinic_id' => 'nullable|string',
        ]);

        // Retain existing secrets if left blank on edit
        if (array_key_exists('b24_client_secret', $validated) && empty($validated['b24_client_secret'])) {
            unset($validated['b24_client_secret']);
        }
        if (array_key_exists('unite_app_key', $validated) && empty($validated['unite_app_key'])) {
            unset($validated['unite_app_key']);
        }
        if (array_key_exists('unite_initial_token', $validated) && empty($validated['unite_initial_token'])) {
            unset($validated['unite_initial_token']);
        }

        $tenant->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tenant updated successfully',
            'tenant' => $tenant->fresh(),
        ]);
    }

    /**
     * Remove the specified tenant from storage.
     */
    public function destroy(Tenant $tenant): JsonResponse
    {
        Log::info("[Tenant] Deleting tenant: {$tenant->name} (ID: {$tenant->id})");

        try {
            $tenantName = $tenant->name;
            $tenant->delete();

            return response()->json([
                'success' => true,
                'message' => "Tenant company '{$tenantName}' deleted successfully.",
            ]);
        } catch (\Exception $e) {
            Log::error("[Tenant] Failed to delete tenant {$tenant->id}: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete tenant: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Real-time connectivity test with Unite EMR
     */
    public function testUniteConnection(Tenant $tenant): JsonResponse
    {
        Log::info("[Tenant] Testing Unite connection for tenant: {$tenant->name} (ID: {$tenant->id})");

        try {
            // 1. Authorize
            $token = $this->uniteClient->ensureValidToken($tenant);
            
            // 2. Fetch clinics
            $clinics = $this->uniteClient->getClinics($tenant, true);
            
            // 3. Fetch doctors
            $doctors = $this->uniteClient->getDoctors($tenant, true);

            Log::info("[Tenant] Unite connection test successful for {$tenant->name}", [
                'clinics_count' => count($clinics),
                'doctors_count' => count($doctors),
            ]);

            return response()->json([
                'success' => true,
                'status' => 'connected',
                'message' => 'Successfully connected to Unite EMR API Gateway',
                'diagnostics' => [
                    'environment' => $tenant->unite_environment,
                    'base_url' => $tenant->unite_base_url,
                    'app_id_masked' => Str::mask($tenant->unite_app_id ?? '', '*', 4, 20),
                    'token_preview' => Str::limit($token, 18),
                    'token_expires_at' => $tenant->unite_token_expires_at?->toIso8601String(),
                    'clinics_count' => count($clinics),
                    'doctors_count' => count($doctors),
                    'clinics' => $clinics,
                    'doctors' => $doctors,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error("[Tenant] Unite connection test failed for {$tenant->name}: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Connection test failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Refresh directories (clinics, doctors, items)
     */
    public function syncDirectories(Tenant $tenant): JsonResponse
    {
        Log::info("[Tenant] Syncing directories from Unite for tenant: {$tenant->name}");

        try {
            $clinics = $this->uniteClient->getClinics($tenant, true);
            $doctors = $this->uniteClient->getDoctors($tenant, true);
            $items = $this->uniteClient->getItemDetails($tenant, true);

            Log::info("[Tenant] Directories synced for {$tenant->name}", [
                'clinics_count' => count($clinics),
                'doctors_count' => count($doctors),
                'items_count' => count($items),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Directories updated from Unite EMR',
                'data' => [
                    'clinics' => $clinics,
                    'doctors' => $doctors,
                    'items' => $items,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error("[Tenant] Sync directories failed for {$tenant->name}: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Register Bitrix24 event listeners and CRM tab widget
     */
    public function registerBitrixPlacements(Request $request, Tenant $tenant): JsonResponse
    {
        $baseUrl = $request->getSchemeAndHttpHost();
        $result = $this->bitrixService->registerIntegrationPlacements($tenant, $baseUrl);

        return response()->json([
            'success' => true,
            'message' => 'Bitrix24 CRM Deal Tab Widget and Webhooks bound successfully',
            'details' => $result,
        ]);
    }

    /**
     * Get items list for a tenant directly from Unite EMR API (v4.2)
     */
    public function getItems(Tenant $tenant): JsonResponse
    {
        try {
            $items = $this->uniteClient->getItemDetails($tenant);

            return response()->json([
                'success' => true,
                'source' => 'unite_emr_api',
                'items' => $items,
                'count' => count($items),
            ]);
        } catch (\Exception $e) {
            Log::error("[Tenant] getItems failed from Unite EMR for {$tenant->name}: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'source' => 'unite_emr_api',
                'message' => $e->getMessage(),
                'items' => [],
            ], 200);
        }
    }
}
