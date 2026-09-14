<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\Appointment;
use App\Services\Unite\UniteClient;
use App\Services\Bitrix\BitrixService;
use App\Services\Sync\AppointmentSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class BitrixWidgetController extends Controller
{
    public function __construct(
        protected UniteClient $uniteClient,
        protected BitrixService $bitrixService,
        protected AppointmentSyncService $syncService
    ) {}

    /**
     * Render the Embedded CRM Tab Widget (CRM_LEAD_DETAIL_TAB / CRM_DEAL_DETAIL_TAB / CRM_CONTACT_DETAIL_TAB)
     */
    public function show(Request $request, Tenant $tenant): Response
    {
        Log::info("Bitrix24 Widget view requested for Tenant {$tenant->name}", [
            'method' => $request->method(),
            'placement' => $request->input('PLACEMENT'),
            'placement_options' => $request->input('PLACEMENT_OPTIONS'),
            'query' => $request->query(),
        ]);

        $dealId = $request->input('deal_id') ?: $request->query('deal_id');
        $placement = $request->input('PLACEMENT', 'CRM_DEAL_DETAIL_TAB');

        if (!$dealId && $request->filled('PLACEMENT_OPTIONS')) {
            $rawOptions = $request->input('PLACEMENT_OPTIONS');
            $placementOptions = is_array($rawOptions) ? $rawOptions : json_decode($rawOptions, true);
            $dealId = $placementOptions['ID'] ?? $placementOptions['id'] ?? null;
        }

        // Fetch directories from cache or Unite API
        $clinics = $this->uniteClient->getClinics($tenant);
        $doctors = $this->uniteClient->getDoctors($tenant);
        $items = $this->uniteClient->getItemDetails($tenant);

        // Fetch existing appointment if already linked to this entity
        $existingAppointment = null;
        if ($dealId) {
            $existingAppointment = Appointment::where('tenant_id', $tenant->id)
                ->where('b24_deal_id', (string) $dealId)
                ->latest()
                ->first();
        }

        // Deal / Lead info simulation or fetch from Bitrix
        $dealContext = null;
        if ($dealId && $tenant->isBitrixAuthenticated()) {
            try {
                $dealData = $this->bitrixService->getDeal($tenant, $dealId);
                if (!empty($dealData)) {
                    $dealContext = [
                        'id' => $dealId,
                        'title' => $dealData['TITLE'] ?? "CRM Entity #{$dealId}",
                        'contact_id' => $dealData['CONTACT_ID'] ?? null,
                    ];
                }
            } catch (\Exception $e) {
                // Non-blocking
            }
        }

        return Inertia::render('Bitrix/DealTabWidget', [
            'tenant' => $tenant,
            'dealId' => $dealId,
            'dealContext' => $dealContext,
            'clinics' => $clinics,
            'doctors' => $doctors,
            'items' => $items,
            'existingAppointment' => $existingAppointment,
            'statusMap' => BitrixService::STATUS_MAP,
            'placement' => $placement,
        ]);
    }

    /**
     * Get 7-day available slots for a doctor at a clinic
     */
    public function getAvailableSlots(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'clinic_id' => 'required|string',
            'doctor_id' => 'required|string',
            'date' => 'required|string', // dd-MM-yyyy
        ]);

        Log::info("[Bitrix Widget] Available slots requested for tenant {$tenant->name}", $validated);

        $slots = $this->uniteClient->getAvailableSlots(
            $tenant,
            $validated['clinic_id'],
            $validated['doctor_id'],
            $validated['date']
        );

        return response()->json([
            'success' => true,
            'data' => $slots,
        ]);
    }

    /**
     * Book an appointment from the Bitrix24 widget
     */
    public function bookAppointment(Request $request, Tenant $tenant): JsonResponse
    {
        Log::info("[Bitrix Widget] Booking appointment request received for tenant {$tenant->name} (ID: {$tenant->id})", [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'payload' => $request->all(),
        ]);

        try {
            $validated = $request->validate([
                'b24_deal_id' => 'nullable|string',
                'b24_contact_id' => 'nullable|string',
                'clinicid' => 'required|string',
                'clinicname' => 'nullable|string',
                'doctorid' => 'required|string',
                'doctorname' => 'nullable|string',
                'firstname' => 'required|string|max:100',
                'middlename' => 'nullable|string|max:100',
                'lastname' => 'required|string|max:100',
                'gender' => 'required|in:M,F,U',
                'mobileno' => 'required|string', // Flexible phone formatting without forced normalization
                'emailid' => 'nullable|email',
                'dob' => 'nullable|string',
                'phototype' => 'nullable|string',
                'photoid' => 'nullable|string',
                'startdatetime' => 'required|string', // dd-MM-yyyy HH:mm
                'duration' => 'nullable|string',
                'remarks' => 'nullable|string|max:2000',
                'requestedby' => 'nullable|string|max:100',
                'itemcode' => 'nullable|array',
                'itemcode.*' => 'integer',
            ]);

            Log::info("[Bitrix Widget] Booking validation passed", ['validated' => $validated]);

            $appointment = $this->syncService->bookFromBitrix($tenant, $validated);

            Log::info("[Bitrix Widget] Appointment booking completed successfully", [
                'appointment_id' => $appointment->id,
                'unite_appointment_id' => $appointment->unite_appointment_id,
                'status' => $appointment->status,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Appointment successfully booked in Unite EMR',
                'appointment' => $appointment,
            ]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            Log::warning("[Bitrix Widget] Booking validation error for tenant {$tenant->name}", [
                'errors' => $ve->errors(),
                'input' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation error: ' . implode(', ', array_map(fn($e) => implode(' ', $e), $ve->errors())),
                'errors' => $ve->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error("[Bitrix Widget] Booking failed for tenant {$tenant->name}: {$e->getMessage()}", [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Booking failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update appointment status
     */
    public function updateStatus(Request $request, Tenant $tenant, Appointment $appointment): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:AAC,ACF,APH,CNR,CVI,YTC,NSW',
        ]);

        $updated = $this->syncService->updateStatus($tenant, $appointment, $validated['status'], 'bitrix');

        return response()->json([
            'success' => true,
            'message' => "Appointment status updated to {$validated['status']}",
            'appointment' => $updated,
        ]);
    }

    /**
     * Create Invoice for appointment
     */
    public function createInvoice(Request $request, Tenant $tenant, Appointment $appointment): JsonResponse
    {
        $validated = $request->validate([
            'invoiceDetails' => 'required|array|min:1',
            'invoiceDetails.*.item_code' => 'required|integer',
            'invoiceDetails.*.item_price' => 'required|numeric',
            'invoiceDetails.*.line_qty' => 'required|integer',
            'invoiceDetails.*.line_gross_amt' => 'required|numeric',
            'invoiceDetails.*.line_disc_amt' => 'nullable|numeric',
            'invoiceDetails.*.line_net_amt' => 'required|numeric',
            'invoiceDetails.*.vat_per' => 'nullable|integer',
            'invoiceDetails.*.vat_amt' => 'nullable|numeric',
            'invoicePayments' => 'required|array|min:1',
            'invoicePayments.*.payment_mode' => 'required|string',
            'invoicePayments.*.paid_amt' => 'required|numeric',
            'invoicePayments.*.paid_date' => 'required|string',
            'invoicePayments.*.payment_reference_number' => 'nullable|string',
            'invoicePayments.*.bank_name' => 'nullable|string',
            'invoicePayments.*.transaction_card_type' => 'nullable|string',
        ]);

        $result = $this->syncService->generateInvoice(
            $tenant,
            $appointment,
            $validated['invoiceDetails'],
            $validated['invoicePayments']
        );

        return response()->json(array_merge($result, [
            'appointment' => $appointment->fresh(),
        ]));
    }
}
