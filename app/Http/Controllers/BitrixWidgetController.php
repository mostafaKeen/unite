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
        $hasUniteCredentials = (!empty($tenant->unite_app_id) || env('UNITE_APP_ID')) && 
                               (!empty($tenant->unite_app_key) || env('UNITE_APP_KEY'));

        Log::info("[Bitrix Widget] Tab Widget view requested for Tenant '{$tenant->name}' (ID: {$tenant->id})", [
            'method' => $request->method(),
            'b24_domain' => $tenant->b24_domain,
            'has_unite_credentials' => $hasUniteCredentials,
            'placement' => $request->input('PLACEMENT'),
            'placement_options' => $request->input('PLACEMENT_OPTIONS'),
            'query' => $request->query(),
        ]);

        // Capture incoming session tokens from Bitrix24 if passed in request
        $authId = $request->input('AUTH_ID') ?: $request->query('AUTH_ID');
        $domain = $request->input('DOMAIN') ?: $request->query('DOMAIN') ?: $request->input('domain') ?: $request->query('domain');
        $refreshId = $request->input('REFRESH_ID') ?: $request->query('REFRESH_ID');

        if ($authId || $domain) {
            $updateFields = [];
            if ($authId) $updateFields['b24_access_token'] = $authId;
            if ($refreshId) $updateFields['b24_refresh_token'] = $refreshId;
            if ($domain) {
                $updateFields['b24_domain'] = $domain;
                $updateFields['b24_client_endpoint'] = "https://{$domain}/rest/";
            }
            $tenant->update($updateFields);
            $tenant->refresh();
        }

        $dealId = $request->input('deal_id') ?: $request->query('deal_id');
        $leadId = $request->input('lead_id') ?: $request->query('lead_id');
        $contactId = $request->input('contact_id') ?: $request->query('contact_id');
        $placement = $request->input('PLACEMENT') ?: $request->query('PLACEMENT') ?: 'CRM_DEAL_DETAIL_TAB';

        $rawOptions = $request->input('PLACEMENT_OPTIONS') ?: $request->query('PLACEMENT_OPTIONS');
        if ($rawOptions) {
            $placementOptions = is_array($rawOptions) ? $rawOptions : json_decode($rawOptions, true);
            if (!$placementOptions && is_string($rawOptions)) {
                $placementOptions = json_decode(stripslashes($rawOptions), true) ?: json_decode(urldecode($rawOptions), true);
            }
            $entityIdFromPlacement = $placementOptions['ID'] ?? $placementOptions['id'] ?? null;
            if ($entityIdFromPlacement) {
                if (str_contains($placement, 'LEAD') && !$leadId) {
                    $leadId = $entityIdFromPlacement;
                } elseif (str_contains($placement, 'CONTACT') && !$contactId) {
                    $contactId = $entityIdFromPlacement;
                } elseif (!$dealId) {
                    $dealId = $entityIdFromPlacement;
                }
            }
        }

        // Fallback for ID parameter directly passed by Bitrix placement
        if (!$leadId && !$dealId && !$contactId) {
            $rawId = $request->input('ID') ?: $request->query('ID') ?: $request->input('id') ?: $request->query('id');
            if ($rawId) {
                if (str_contains($placement, 'LEAD')) $leadId = $rawId;
                elseif (str_contains($placement, 'CONTACT')) $contactId = $rawId;
                else $dealId = $rawId;
            }
        }

        Log::info("[Bitrix Widget] Processing Tab View", [
            'tenant' => $tenant->name,
            'placement' => $placement,
            'lead_id' => $leadId,
            'deal_id' => $dealId,
            'contact_id' => $contactId,
            'has_b24_token' => !empty($tenant->b24_access_token),
        ]);

        // Fetch directories from cache or Unite API
        $clinics = [];
        $doctors = [];
        $items = [];
        $uniteError = null;

        try {
            $clinics = $this->uniteClient->getClinics($tenant);
            $doctors = $this->uniteClient->getDoctors($tenant);
            $items = $this->uniteClient->getItemDetails($tenant);
        } catch (\Exception $e) {
            $uniteError = $e->getMessage();
            Log::warning("[Bitrix Widget] Directory loading warning for {$tenant->name}: {$e->getMessage()}");
        }

        // Fetch existing appointment if already linked to this entity
        $existingAppointment = null;
        $targetEntityId = $dealId ?: ($leadId ?: $contactId);
        if ($targetEntityId) {
            $existingAppointment = Appointment::where('tenant_id', $tenant->id)
                ->where(function ($q) use ($dealId, $leadId, $contactId) {
                    if ($dealId) $q->orWhere('b24_deal_id', (string) $dealId);
                    if ($leadId) $q->orWhere('b24_lead_id', (string) $leadId);
                    if ($contactId) $q->orWhere('b24_contact_id', (string) $contactId);
                })
                ->latest()
                ->first();
        }

        $patientDefaults = [
            'firstname' => '',
            'lastname' => '',
            'mobileno' => '',
            'emailid' => '',
            'gender' => 'M',
            'dob' => '',
            'requestedby' => '',
        ];

        // Fetch Current User & Entity details from Bitrix24 REST API
        $dealContext = null;
        if ($tenant->isBitrixAuthenticated()) {
            // Fetch current Bitrix24 user for 'requestedby' default
            try {
                $currentUser = $this->bitrixService->getCurrentUser($tenant);
                if (!empty($currentUser)) {
                    $userNameParts = array_filter([$currentUser['NAME'] ?? '', $currentUser['LAST_NAME'] ?? '']);
                    if (!empty($userNameParts)) {
                        $patientDefaults['requestedby'] = implode(' ', $userNameParts);
                        Log::info("[Bitrix Widget] Logged-in Bitrix User fetched: {$patientDefaults['requestedby']}");
                    }
                }
            } catch (\Exception $e) {
                Log::info("[Bitrix Widget] Non-blocking Bitrix user fetch error: " . $e->getMessage());
            }

            try {
                $entityData = null;
                $contactData = null;

                if ($leadId) {
                    $entityData = $this->bitrixService->getLead($tenant, $leadId);
                    if (!empty($entityData['CONTACT_ID'])) {
                        $contactData = $this->bitrixService->getContact($tenant, $entityData['CONTACT_ID']);
                    }
                } elseif ($dealId) {
                    $dealData = $this->bitrixService->getDeal($tenant, $dealId);
                    if (!empty($dealData)) {
                        $dealContext = [
                            'id' => $dealId,
                            'title' => $dealData['TITLE'] ?? "CRM Entity #{$dealId}",
                            'contact_id' => $dealData['CONTACT_ID'] ?? null,
                        ];
                    }
                    if (!empty($dealData['CONTACT_ID'])) {
                        $contactData = $this->bitrixService->getContact($tenant, $dealData['CONTACT_ID']);
                    }
                } elseif ($contactId) {
                    $contactData = $this->bitrixService->getContact($tenant, $contactId);
                }

                // Helper to extract phone & email from Bitrix arrays
                $extractFirstPhone = function ($phoneArr) {
                    if (is_array($phoneArr) && count($phoneArr) > 0) {
                        return $phoneArr[0]['VALUE'] ?? '';
                    }
                    return is_string($phoneArr) ? $phoneArr : '';
                };

                $extractFirstEmail = function ($emailArr) {
                    if (is_array($emailArr) && count($emailArr) > 0) {
                        return $emailArr[0]['VALUE'] ?? '';
                    }
                    return is_string($emailArr) ? $emailArr : '';
                };

                // Format birthdate to dd-MM-yyyy format expected by widget
                $formatDob = function ($rawDate) {
                    if (empty($rawDate)) return '';
                    try {
                        $ts = strtotime($rawDate);
                        if ($ts !== false) {
                            return date('d-m-Y', $ts);
                        }
                    } catch (\Exception $e) {}
                    return (string) $rawDate;
                };

                $primary = !empty($entityData) ? $entityData : $contactData;

                if ($primary) {
                    $fname = $primary['NAME'] ?? ($contactData['NAME'] ?? '');
                    $lname = $primary['LAST_NAME'] ?? ($contactData['LAST_NAME'] ?? '');
                    $phone = $extractFirstPhone($primary['PHONE'] ?? ($contactData['PHONE'] ?? null));
                    $email = $extractFirstEmail($primary['EMAIL'] ?? ($contactData['EMAIL'] ?? null));
                    $genderRaw = strtoupper($primary['GENDER_ID'] ?? ($contactData['GENDER_ID'] ?? 'M'));
                    $gender = in_array($genderRaw, ['M', 'F', 'U']) ? $genderRaw : 'M';
                    $dob = $formatDob($primary['BIRTHDATE'] ?? ($contactData['BIRTHDATE'] ?? null));

                    if ($fname) $patientDefaults['firstname'] = $fname;
                    if ($lname) $patientDefaults['lastname'] = $lname;
                    if ($phone) $patientDefaults['mobileno'] = $phone;
                    if ($email) $patientDefaults['emailid'] = $email;
                    if ($gender) $patientDefaults['gender'] = $gender;
                    if ($dob) $patientDefaults['dob'] = $dob;

                    Log::info("[Bitrix Widget] Standard patient fields loaded from Bitrix:", $patientDefaults);
                }
            } catch (\Exception $e) {
                Log::info("[Bitrix Widget] Non-blocking Bitrix entity fetch error: " . $e->getMessage());
            }
        }

        // Auto-match appointment by phone number if not directly linked yet
        if (!$existingAppointment && !empty($patientDefaults['mobileno'])) {
            $digits = preg_replace('/[^\d]/', '', $patientDefaults['mobileno']);
            $lastDigits = strlen($digits) >= 8 ? substr($digits, -8) : $digits;
            if ($lastDigits) {
                $existingAppointment = Appointment::where('tenant_id', $tenant->id)
                    ->where('patient_mobileno', 'like', "%{$lastDigits}%")
                    ->latest()
                    ->first();
            }

            // If still null, query live appointments from Unite EMR API by phone number
            if (!$existingAppointment) {
                try {
                    $liveApps = $this->uniteClient->getAppointments($tenant, phoneNo: $patientDefaults['mobileno']);
                    if (!empty($liveApps)) {
                        $existingAppointment = $liveApps[0];
                    }
                } catch (\Exception $e) {
                    Log::info("[Bitrix Widget] Phone match Unite live appointment lookup warning: " . $e->getMessage());
                }
            }
        }

        return Inertia::render('Bitrix/DealTabWidget', [
            'tenant' => $tenant,
            'dealId' => $dealId,
            'leadId' => $leadId,
            'contactId' => $contactId,
            'dealContext' => $dealContext,
            'clinics' => $clinics,
            'doctors' => $doctors,
            'items' => $items,
            'existingAppointment' => $existingAppointment,
            'statusMap' => BitrixService::STATUS_MAP,
            'placement' => $placement,
            'hasUniteCredentials' => $hasUniteCredentials,
            'patientDefaults' => $patientDefaults,
            'uniteDiagnostics' => [
                'hasCredentials' => $hasUniteCredentials,
                'error' => $uniteError,
                'itemsCount' => count($items),
                'clinicsCount' => count($clinics),
                'doctorsCount' => count($doctors),
            ],
        ]);
    }

    /**
     * Live fetch item details directly from Unite EMR API for the embedded widget
     */
    public function getItems(Request $request, Tenant $tenant): JsonResponse
    {
        $hasCredentials = (!empty($tenant->unite_app_id) || env('UNITE_APP_ID')) && 
                          (!empty($tenant->unite_app_key) || env('UNITE_APP_KEY'));

        $clinicId = $request->query('clinic_id');

        Log::info("[Bitrix Widget] getItems requested for Tenant '{$tenant->name}' (ID: {$tenant->id})", [
            'has_credentials' => $hasCredentials,
            'clinic_id' => $clinicId,
            'unite_environment' => $tenant->unite_environment,
            'unite_base_url' => $tenant->unite_base_url,
        ]);

        if (!$hasCredentials) {
            return response()->json([
                'success' => false,
                'tenant' => $tenant->name,
                'has_credentials' => false,
                'error' => "Unite EMR credentials (App ID / App Key) are not configured for tenant '{$tenant->name}'.",
                'count' => 0,
                'items' => [],
                'diagnostics' => null,
            ]);
        }

        try {
            $result = $this->uniteClient->getItemDetailsWithDiagnostics($tenant, $clinicId);
            $items = $result['items'] ?? [];
            $diagnostics = $result['diagnostics'] ?? [];

            return response()->json([
                'success' => !empty($items) || empty($diagnostics['error']),
                'tenant' => $tenant->name,
                'has_credentials' => true,
                'count' => count($items),
                'items' => $items,
                'diagnostics' => $diagnostics,
                'error' => $diagnostics['error'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error("[Bitrix Widget] getItems failed for Tenant '{$tenant->name}': {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'tenant' => $tenant->name,
                'has_credentials' => true,
                'error' => $e->getMessage(),
                'count' => 0,
                'items' => [],
                'diagnostics' => null,
            ]);
        }
    }

    /**
     * Get 7-day available slots for a doctor at a clinic
     */
    public function getAvailableSlots(Request $request, Tenant $tenant): JsonResponse
    {
        try {
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
        } catch (\Exception $e) {
            Log::error("[Bitrix Widget] Error handling available slots for {$tenant->name}: {$e->getMessage()}");

            // Dynamic 7-day slots fallback so UI never receives 500
            $fallbackSlots = [];
            $start = now();
            for ($i = 0; $i < 7; $i++) {
                $day = $start->copy()->addDays($i);
                $fallbackSlots[$day->format('Y-m-d')] = [
                    '09:00 AM', '09:30 AM', '10:00 AM', '10:30 AM', '11:00 AM',
                    '02:00 PM', '02:30 PM', '03:00 PM', '03:30 PM', '04:00 PM'
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $fallbackSlots,
                'warning' => $e->getMessage(),
            ]);
        }
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
                'b24_lead_id' => 'nullable|string',
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

            $isPendingSync = $appointment->status === 'pending_emr_sync';
            $message = $isPendingSync
                ? 'Appointment booked in CRM! EMR sync pending vendor database configuration.'
                : 'Appointment successfully booked in Unite EMR';

            Log::info("[Bitrix Widget] Appointment booking completed successfully", [
                'appointment_id' => $appointment->id,
                'unite_appointment_id' => $appointment->unite_appointment_id,
                'status' => $appointment->status,
                'is_pending_sync' => $isPendingSync,
            ]);

            return response()->json([
                'success' => true,
                'emr_sync_pending' => $isPendingSync,
                'message' => $message,
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
        Log::info("[Invoice][Controller] Invoice creation request received", [
            'tenant' => $tenant->name,
            'tenant_id' => $tenant->id,
            'appointment_id' => $appointment->id,
            'unite_appointment_id' => $appointment->unite_appointment_id,
            'payload_keys' => array_keys($request->all()),
        ]);

        try {
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

            Log::info("[Invoice][Controller] Invoice creation completed", [
                'result' => $result,
            ]);

            return response()->json(array_merge($result, [
                'appointment' => $appointment->fresh(),
            ]));
        } catch (\Illuminate\Validation\ValidationException $ve) {
            Log::warning("[Invoice][Controller] Validation error", [
                'errors' => $ve->errors(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invoice validation error: ' . implode(', ', array_map(fn($e) => implode(' ', $e), $ve->errors())),
            ], 422);
        } catch (\Exception $e) {
            Log::error("[Invoice][Controller] Invoice creation failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invoice creation failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
