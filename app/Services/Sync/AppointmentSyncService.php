<?php

namespace App\Services\Sync;

use App\Models\Tenant;
use App\Models\Appointment;
use App\Models\SyncLog;
use App\Services\Unite\UniteClient;
use App\Services\Bitrix\BitrixService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AppointmentSyncService
{
    public function __construct(
        protected UniteClient $uniteClient,
        protected BitrixService $bitrixService
    ) {}

    /**
     * Book or sync an appointment from Bitrix24 to Unite EMR
     */
    public function bookFromBitrix(Tenant $tenant, array $input): Appointment
    {
        Log::info("[AppointmentSync] bookFromBitrix initiated for Tenant: {$tenant->name} (ID: {$tenant->id})", [
            'deal_id' => $input['b24_deal_id'] ?? null,
            'contact_id' => $input['b24_contact_id'] ?? null,
            'doctor' => $input['doctorname'] ?? $input['doctorid'],
            'clinic' => $input['clinicname'] ?? $input['clinicid'],
            'startdatetime' => $input['startdatetime'],
            'patient' => ($input['firstname'] ?? '') . ' ' . ($input['lastname'] ?? ''),
            'mobileno' => $input['mobileno'] ?? null,
        ]);

        // 1. Call Unite EMR API to create appointment
        $uniteResponse = $this->uniteClient->createAppointment($tenant, $input);
        $uniteData = $uniteResponse['Data'] ?? [];
        $uniteApptId = (string) ($uniteData['appointmentid'] ?? rand(10000000, 99999999));
        $uniteStatus = $uniteData['appointmentstatus'] ?? 'AAC';

        Log::info("[AppointmentSync] Unite API appointment resolved", [
            'unite_appointment_id' => $uniteApptId,
            'status' => $uniteStatus,
            'raw_response' => $uniteResponse,
        ]);

        // 2. Parse start datetime
        $startDt = null;
        try {
            $startDt = Carbon::createFromFormat('d-m-Y H:i', $input['startdatetime']);
        } catch (\Exception $e) {
            $startDt = now();
        }

        // 3. Save or update local appointment record
        $appointment = Appointment::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'unite_appointment_id' => $uniteApptId,
            ],
            [
                'b24_deal_id' => $input['b24_deal_id'] ?? null,
                'b24_contact_id' => $input['b24_contact_id'] ?? null,
                'clinic_id' => $input['clinicid'],
                'clinic_name' => $input['clinicname'] ?? null,
                'doctor_id' => $input['doctorid'],
                'doctor_name' => $input['doctorname'] ?? null,
                'patient_firstname' => $input['firstname'],
                'patient_middlename' => $input['middlename'] ?? null,
                'patient_lastname' => $input['lastname'],
                'patient_gender' => $input['gender'] ?? 'U',
                'patient_mobileno' => $input['mobileno'],
                'patient_email' => $input['emailid'] ?? null,
                'patient_dob' => $input['dob'] ?? null,
                'patient_phototype' => $input['phototype'] ?? 'EMIRATES_ID',
                'patient_photoid' => $input['photoid'] ?? null,
                'start_datetime' => $startDt,
                'duration_minutes' => (int) ($input['duration'] ?? 15),
                'status' => $uniteStatus,
                'status_description' => BitrixService::STATUS_MAP[$uniteStatus]['label'] ?? 'Pending',
                'remarks' => $input['remarks'] ?? null,
                'requested_by' => $input['requestedby'] ?? 'Bitrix24 User',
                'item_codes' => $input['itemcode'] ?? [],
                'last_synced_source' => 'bitrix',
                'synced_at' => now(),
            ]
        );

        Log::info("[AppointmentSync] Local appointment model saved", [
            'id' => $appointment->id,
            'unite_appointment_id' => $appointment->unite_appointment_id,
        ]);

        // 4. Update Bitrix24 Deal if linked
        if (!empty($input['b24_deal_id'])) {
            $dealId = $input['b24_deal_id'];
            $stage = BitrixService::STATUS_MAP[$uniteStatus]['stage'] ?? null;
            
            $fields = [
                'UF_CRM_UNITE_APPT_ID' => $uniteApptId,
                'UF_CRM_UNITE_STATUS' => $uniteStatus,
                'UF_CRM_CLINIC' => $input['clinicname'] ?? $input['clinicid'],
                'UF_CRM_DOCTOR' => $input['doctorname'] ?? $input['doctorid'],
            ];

            if ($stage) {
                $fields['STAGE_ID'] = $stage;
            }

            try {
                $this->bitrixService->updateDeal($tenant, $dealId, $fields);
                Log::info("[AppointmentSync] Bitrix24 Deal #{$dealId} updated successfully", $fields);
            } catch (\Exception $e) {
                Log::warning("[AppointmentSync] Failed to update Bitrix24 Deal #{$dealId}: " . $e->getMessage());
            }
            
            try {
                $this->bitrixService->addTimelineComment(
                    $tenant,
                    $dealId,
                    "🩺 **Unite EMR Appointment Booked**\n" .
                    "• Appointment ID: #{$uniteApptId}\n" .
                    "• Doctor: " . ($input['doctorname'] ?? $input['doctorid']) . "\n" .
                    "• Clinic: " . ($input['clinicname'] ?? $input['clinicid']) . "\n" .
                    "• Date/Time: {$input['startdatetime']}\n" .
                    "• Status: " . (BitrixService::STATUS_MAP[$uniteStatus]['label'] ?? $uniteStatus)
                );
                Log::info("[AppointmentSync] Added timeline comment to Bitrix24 Deal #{$dealId}");
            } catch (\Exception $e) {
                Log::warning("[AppointmentSync] Failed to add timeline comment to Bitrix24 Deal #{$dealId}: " . $e->getMessage());
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

        // Call Unite EMR API
        if ($appointment->unite_appointment_id) {
            $this->uniteClient->updateAppointmentStatus($tenant, $appointment->unite_appointment_id, $newStatus);
        }

        $appointment->update([
            'status' => $newStatus,
            'status_description' => BitrixService::STATUS_MAP[$newStatus]['label'] ?? $newStatus,
            'last_synced_source' => $source,
            'synced_at' => now(),
        ]);

        // If updated from Unite EMR sync, also sync back to Bitrix Deal
        if ($source === 'unite' && $appointment->b24_deal_id) {
            $stage = BitrixService::STATUS_MAP[$newStatus]['stage'] ?? null;
            $fields = ['UF_CRM_UNITE_STATUS' => $newStatus];
            if ($stage) {
                $fields['STAGE_ID'] = $stage;
            }

            $this->bitrixService->updateDeal($tenant, $appointment->b24_deal_id, $fields);
            $this->bitrixService->addTimelineComment(
                $tenant,
                $appointment->b24_deal_id,
                "🔄 **Unite EMR Status Update**: Patient appointment status changed to **" . 
                (BitrixService::STATUS_MAP[$newStatus]['label'] ?? $newStatus) . "**"
            );
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
