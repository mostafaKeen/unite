<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Unite\UniteClient;
use App\Services\Bitrix\BitrixService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

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
     * Real-time connectivity test with Unite EMR
     */
    public function testUniteConnection(Tenant $tenant): JsonResponse
    {
        try {
            // 1. Authorize
            $token = $this->uniteClient->ensureValidToken($tenant);
            
            // 2. Fetch clinics
            $clinics = $this->uniteClient->getClinics($tenant, true);
            
            // 3. Fetch doctors
            $doctors = $this->uniteClient->getDoctors($tenant, true);

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
        try {
            $clinics = $this->uniteClient->getClinics($tenant, true);
            $doctors = $this->uniteClient->getDoctors($tenant, true);
            $items = $this->uniteClient->getItemDetails($tenant, true);

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
     * Get items list for a tenant
     */
    public function getItems(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'success' => true,
            'items' => $tenant->items_cache ?? [],
        ]);
    }

    /**
     * Add a new item to tenant's items_cache
     */
    public function storeItem(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'item_description' => 'required|string|max:255',
            'item_code' => 'required|integer',
            'price' => 'required|numeric|min:0',
            'average_time_in_minutes' => 'nullable|integer|min:0',
            'clinic_id' => 'nullable|string',
            'PackageItemDetails' => 'nullable|array',
        ]);

        $items = $tenant->items_cache ?? [];

        // Check if item_code already exists
        foreach ($items as $item) {
            if ((int)($item['item_code'] ?? 0) === (int)$validated['item_code']) {
                return response()->json([
                    'success' => false,
                    'message' => "Item with code #{$validated['item_code']} already exists. Please choose a unique code or edit the existing item."
                ], 422);
            }
        }

        $newItem = [
            'item_code' => (int) $validated['item_code'],
            'item_description' => trim($validated['item_description']),
            'price' => (float) $validated['price'],
            'average_time_in_minutes' => (int) ($validated['average_time_in_minutes'] ?? 20),
            'clinic_id' => $validated['clinic_id'] ?: ($tenant->default_clinic_id ?: ($tenant->clinics_cache[0]['clinic_id'] ?? '')),
            'PackageItemDetails' => $validated['PackageItemDetails'] ?? [],
        ];

        $items[] = $newItem;
        $tenant->update(['items_cache' => $items]);

        return response()->json([
            'success' => true,
            'message' => 'Medical service/item added successfully.',
            'items' => $items,
            'item' => $newItem,
        ]);
    }

    /**
     * Update an existing item in tenant's items_cache
     */
    public function updateItem(Request $request, Tenant $tenant, $itemCode): JsonResponse
    {
        $validated = $request->validate([
            'item_description' => 'required|string|max:255',
            'new_item_code' => 'nullable|integer',
            'price' => 'required|numeric|min:0',
            'average_time_in_minutes' => 'nullable|integer|min:0',
            'clinic_id' => 'nullable|string',
            'PackageItemDetails' => 'nullable|array',
        ]);

        $items = $tenant->items_cache ?? [];
        $foundIndex = null;
        $targetCode = (int) $itemCode;

        foreach ($items as $index => $item) {
            if ((int)($item['item_code'] ?? 0) === $targetCode) {
                $foundIndex = $index;
                break;
            }
        }

        if ($foundIndex === null) {
            return response()->json([
                'success' => false,
                'message' => "Item with code #{$itemCode} not found."
            ], 404);
        }

        $newCode = isset($validated['new_item_code']) && $validated['new_item_code'] ? (int) $validated['new_item_code'] : $targetCode;

        if ($newCode !== $targetCode) {
            foreach ($items as $index => $item) {
                if ($index !== $foundIndex && (int)($item['item_code'] ?? 0) === $newCode) {
                    return response()->json([
                        'success' => false,
                        'message' => "Another item already uses code #{$newCode}."
                    ], 422);
                }
            }
        }

        $items[$foundIndex] = [
            'item_code' => $newCode,
            'item_description' => trim($validated['item_description']),
            'price' => (float) $validated['price'],
            'average_time_in_minutes' => (int) ($validated['average_time_in_minutes'] ?? 20),
            'clinic_id' => $validated['clinic_id'] ?: ($items[$foundIndex]['clinic_id'] ?? ''),
            'PackageItemDetails' => $validated['PackageItemDetails'] ?? ($items[$foundIndex]['PackageItemDetails'] ?? []),
        ];

        $tenant->update(['items_cache' => $items]);

        return response()->json([
            'success' => true,
            'message' => 'Medical service/item updated successfully.',
            'items' => $items,
            'item' => $items[$foundIndex],
        ]);
    }

    /**
     * Delete an item from tenant's items_cache
     */
    public function destroyItem(Tenant $tenant, $itemCode): JsonResponse
    {
        $items = $tenant->items_cache ?? [];
        $targetCode = (int) $itemCode;
        $filtered = array_values(array_filter($items, function ($item) use ($targetCode) {
            return (int)($item['item_code'] ?? 0) !== $targetCode;
        }));

        if (count($filtered) === count($items)) {
            return response()->json([
                'success' => false,
                'message' => "Item with code #{$itemCode} not found."
            ], 404);
        }

        $tenant->update(['items_cache' => $filtered]);

        return response()->json([
            'success' => true,
            'message' => 'Medical service/item removed successfully.',
            'items' => $filtered,
        ]);
    }
}
