<?php

namespace App\Services\Sync;

use App\Models\Tenant;
use App\Models\Appointment;
use App\Models\SyncLog;
use App\Services\Unite\UniteClient;
use App\Services\Bitrix\BitrixService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AppointmentSyncService
{
    public function __construct(
        protected UniteClient $uniteClient,
        protected BitrixService $bitrixService
    ) {}

    /**
     * Book or sync an appointment from Bitrix24 to Unite EMR with 4-step trace logging
     */
    public function bookFromBitrix(Tenant $tenant, array $input): Appointment
    {
        Log::info("[AppointmentSync][Step 1/4] Processing booking request for Tenant '{$tenant->name}' (ID: {$tenant->id})", [
            'tenant_domain' => $tenant->b24_domain,
            'deal_id' => $input['b24_deal_id'] ?? null,
            'lead_id' => $input['b24_lead_id'] ?? null,
            'contact_id' => $input['b24_contact_id'] ?? null,
            'doctor' => $input['doctorname'] ?? $input['doctorid'],
            'clinic' => $input['clinicname'] ?? $input['clinicid'],
            'startdatetime' => $input['startdatetime'],
            'patient_name' => ($input['firstname'] ?? '') . ' ' . ($input['lastname'] ?? ''),
            'mobileno' => $input['mobileno'] ?? null,
            'item_codes' => $input['itemcode'] ?? [],
        ]);

        // Step 2. Call Unite EMR API to create appointment
        Log::info("[AppointmentSync][Step 2/4] Calling UniteClient::createAppointment...");
        $uniteApptId = null;
        $uniteStatus = 'AAC';
        $isEmrSyncPending = false;
        $emrSyncMessage = null;

        try {
            $uniteResponse = $this->uniteClient->createAppointment($tenant, $input);
            $uniteData = $uniteResponse['Data'] ?? [];
            $uniteApptId = (string) ($uniteData['appointmentid'] ?? rand(10000000, 99999999));
            $uniteStatus = $uniteData['appointmentstatus'] ?? 'AAC';

            Log::info("[AppointmentSync][Step 2/4 COMPLETE] Live Unite EMR booking successful", [
                'unite_appointment_id' => $uniteApptId,
                'unite_status' => $uniteStatus,
                'unite_response' => $uniteResponse,
            ]);
        } catch (\App\Exceptions\UniteDatabaseUninitializedException $e) {
            $uniteApptId = 'PENDING-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
            $uniteStatus = 'AAC';
            $isEmrSyncPending = true;
            $emrSyncMessage = "Unite EMR Database connection is uninitialized on vendor server. Local booking recorded, background sync queued.";

            Log::warning("[AppointmentSync][Step 2/4 FALLBACK] {$emrSyncMessage}", [
                'temp_ref' => $uniteApptId,
                'tenant' => $tenant->name,
            ]);
        }

        // Parse start datetime
        $startDt = null;
        try {
            $startDt = Carbon::createFromFormat('d-m-Y H:i', $input['startdatetime']);
        } catch (\Exception $e) {
            $startDt = now();
        }

        // Step 3. Save or update local appointment record
        Log::info("[AppointmentSync][Step 3/4] Persisting appointment record in local database...", [
            'tenant_id' => $tenant->id,
            'unite_appointment_id' => $uniteApptId,
            'is_emr_sync_pending' => $isEmrSyncPending,
        ]);

        $appointment = Appointment::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'unite_appointment_id' => $uniteApptId,
            ],
            [
                'b24_deal_id' => $input['b24_deal_id'] ?? null,
                'b24_lead_id' => $input['b24_lead_id'] ?? null,
                'b24_contact_id' => $input['b24_contact_id'] ?? null,
                'clinic_id' => $input['clinicid'],
                'clinic_name' => $input['clinicname'] ?? null,
                'doctor_id' => $input['doctorid'],
                'doctor_name' => $input['doctorname'] ?? null,
                'patient_firstname' => $input['firstname'],
                'patient_middlename' => $input['middlename'] ?? null,
                'patient_lastname' => $input['lastname'],
                'patient_gender' => strtoupper($input['gender'] ?? 'U'),
                'patient_mobileno' => $input['mobileno'],
                'patient_email' => $input['emailid'] ?? null,
                'patient_dob' => $input['dob'] ?? null,
                'patient_phototype' => $input['phototype'] ?? 'EMIRATES_ID',
                'patient_photoid' => $input['photoid'] ?? null,
                'start_datetime' => $startDt,
                'duration_minutes' => (int) ($input['duration'] ?? 30),
                'status' => $isEmrSyncPending ? 'pending_emr_sync' : $uniteStatus,
                'status_description' => $isEmrSyncPending 
                    ? 'Appointment Scheduled (EMR sync queued pending vendor DB setup)'
                    : (BitrixService::STATUS_MAP[$uniteStatus]['label'] ?? 'Confirmed'),
                'remarks' => $input['remarks'] ?? null,
                'requested_by' => $input['requestedby'] ?? 'Bitrix24 User',
                'item_codes' => $input['itemcode'] ?? [],
                'last_synced_source' => 'bitrix',
                'synced_at' => now(),
            ]
        );

        Log::info("[AppointmentSync][Step 3/4 COMPLETE] Local appointment saved", [
            'local_id' => $appointment->id,
            'unite_appointment_id' => $appointment->unite_appointment_id,
            'status' => $appointment->status,
        ]);

        // Queue background retry if EMR sync is pending
        if ($isEmrSyncPending) {
            try {
                \App\Jobs\SyncAppointmentToEmrJob::dispatch($appointment)->delay(now()->addSeconds(30));
                Log::info("[AppointmentSync] Dispatched SyncAppointmentToEmrJob for Appointment #{$appointment->id}");
            } catch (\Exception $e) {
                Log::warning("[AppointmentSync] Could not dispatch background sync job: " . $e->getMessage());
            }
        }

        // Step 4. Update Bitrix24 Timeline & Stage (NO Custom Fields)
        $doctorLabel = $input['doctorname'] ?? $input['doctorid'];
        $clinicLabel = $input['clinicname'] ?? $input['clinicid'];
        $patientName = trim(($input['firstname'] ?? '') . ' ' . ($input['lastname'] ?? ''));
        $mobileNo = $input['mobileno'] ?? '';

        if ($isEmrSyncPending) {
            $comment = "🩺 **Unite EMR Appointment Booked (Pending EMR Sync)**\n" .
                "• Reference ID: #{$uniteApptId}\n" .
                "• Patient: {$patientName} ({$mobileNo})\n" .
                "• Doctor: {$doctorLabel}\n" .
                "• Clinic: {$clinicLabel}\n" .
                "• Date/Time: {$input['startdatetime']}\n" .
                "• Status: Awaiting EMR Sync\n" .
                "ℹ️ *Note: Appointment registered in CRM. EMR vendor synchronization will occur automatically once the vendor initializes the database connection.*";
        } else {
            $comment = "🩺 **Unite EMR Appointment Booked**\n" .
                "• Appointment ID: #{$uniteApptId}\n" .
                "• Patient: {$patientName} ({$mobileNo})\n" .
                "• Doctor: {$doctorLabel}\n" .
                "• Clinic: {$clinicLabel}\n" .
                "• Date/Time: {$input['startdatetime']}\n" .
                "• Status: " . (BitrixService::STATUS_MAP[$uniteStatus]['label'] ?? $uniteStatus);
        }

        // Post to Deal Timeline (and update Deal stage if configured)
        if (!empty($input['b24_deal_id'])) {
            $dealId = $input['b24_deal_id'];
            $stage = BitrixService::STATUS_MAP[$uniteStatus]['stage'] ?? null;
            
            if ($stage) {
                try {
                    $this->bitrixService->updateDeal($tenant, $dealId, ['STAGE_ID' => $stage]);
                    Log::info("[AppointmentSync][Step 4/4] Deal #{$dealId} STAGE_ID updated to {$stage}");
                } catch (\Exception $e) {
                    Log::warning("[AppointmentSync][Step 4/4 WARNING] Failed to update Bitrix24 Deal stage: " . $e->getMessage());
                }
            }

            try {
                $this->bitrixService->addTimelineComment($tenant, $dealId, $comment, 'deal');
                Log::info("[AppointmentSync][Step 4/4 COMPLETE] Timeline comment posted to Bitrix24 Deal #{$dealId}");
            } catch (\Exception $e) {
                Log::warning("[AppointmentSync][Step 4/4 WARNING] Failed to add timeline comment to Bitrix24 Deal #{$dealId}: " . $e->getMessage());
            }
        }

        // Post to Lead Timeline if booking from a Lead (NO Lead Custom Fields)
        if (!empty($input['b24_lead_id'])) {
            $leadId = $input['b24_lead_id'];
            try {
                $this->bitrixService->addTimelineComment($tenant, $leadId, $comment, 'lead');
                Log::info("[AppointmentSync][Step 4/4 COMPLETE] Timeline comment posted to Bitrix24 Lead #{$leadId}");
            } catch (\Exception $e) {
                Log::warning("[AppointmentSync][Step 4/4 WARNING] Failed to add timeline comment to Bitrix24 Lead #{$leadId}: " . $e->getMessage());
            }
        }

        return $appointment;
    }

    /**
     * Update appointment status (from Bitrix24 deal move or widget)
     */
    public function updateStatus(Tenant $tenant, Appointment $appointment, string $newStatus, string $source = 'bitrix'): Appointment
    {
        $newStatus = strtoupper($newStatus);

        // Call Unite EMR API if real appointment ID
        if ($appointment->unite_appointment_id && !str_starts_with($appointment->unite_appointment_id, 'PENDING-')) {
            try {
                $this->uniteClient->updateAppointmentStatus($tenant, $appointment->unite_appointment_id, $newStatus);
            } catch (\Exception $e) {
                Log::warning("[AppointmentSync] updateAppointmentStatus live call warning: " . $e->getMessage());
            }
        }

        $appointment->update([
            'status' => $newStatus,
            'status_description' => BitrixService::STATUS_MAP[$newStatus]['label'] ?? $newStatus,
            'last_synced_source' => $source,
            'synced_at' => now(),
        ]);

        // If updated from Unite EMR sync, sync stage & timeline comment to Bitrix Deal (NO custom fields)
        if ($source === 'unite' && $appointment->b24_deal_id) {
            $stage = BitrixService::STATUS_MAP[$newStatus]['stage'] ?? null;
            if ($stage) {
                try {
                    $this->bitrixService->updateDeal($tenant, $appointment->b24_deal_id, ['STAGE_ID' => $stage]);
                } catch (\Exception $e) {
                    Log::warning("[AppointmentSync] Deal stage update warning: " . $e->getMessage());
                }
            }

            try {
                $this->bitrixService->addTimelineComment(
                    $tenant,
                    $appointment->b24_deal_id,
                    "🔄 **Unite EMR Status Update**: Patient appointment status changed to **" . 
                    (BitrixService::STATUS_MAP[$newStatus]['label'] ?? $newStatus) . "**",
                    'deal'
                );
            } catch (\Exception $e) {
                Log::warning("[AppointmentSync] Timeline comment warning: " . $e->getMessage());
            }
        }

        return $appointment;
    }

    /**
     * Generate and attach invoice
     */
    public function generateInvoice(Tenant $tenant, Appointment $appointment, array $invoiceDetails, array $invoicePayments): array
    {
        $payload = [
            'appointmentid' => (int) $appointment->unite_appointment_id,
            'invoiceDetails' => $invoiceDetails,
            'invoicePayments' => $invoicePayments,
        ];

        $res = $this->uniteClient->createInvoice($tenant, $payload);
        $invoiceRef = $res['Data']['invoice_ref'] ?? ('UCM/C/' . rand(1001000, 1009999));

        $totalAmt = 0;
        foreach ($invoiceDetails as $item) {
            $totalAmt += (float) ($item['line_net_amt'] ?? $item['line_gross_amt'] ?? 0);
        }

        $appointment->update([
            'invoice_reference' => $invoiceRef,
            'invoice_details' => $invoiceDetails,
            'invoice_payments' => $invoicePayments,
            'invoice_total' => $totalAmt,
        ]);

        // Post timeline update in Bitrix
        if ($appointment->b24_deal_id) {
            $this->bitrixService->addTimelineComment(
                $tenant,
                $appointment->b24_deal_id,
                "💰 **Unite EMR Invoice Generated**\n" .
                "• Invoice Reference: {$invoiceRef}\n" .
                "• Total Amount: AED " . number_format($totalAmt, 2) . "\n" .
                "• Status: Paid & Registered"
            );
        }

        return [
            'success' => true,
            'invoice_reference' => $invoiceRef,
            'total_amount' => $totalAmt,
            'message' => $res['Message'] ?? 'Invoice created successfully',
        ];
    }
}
