<?php

namespace App\Jobs;

use App\Exceptions\UniteDatabaseUninitializedException;
use App\Models\Appointment;
use App\Services\Bitrix\BitrixService;
use App\Services\Unite\UniteClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncAppointmentToEmrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 300; // 5 minutes

    public function __construct(
        public Appointment $appointment
    ) {}

    /**
     * Execute the job.
     */
    public function handle(UniteClient $uniteClient, BitrixService $bitrixService): void
    {
        $appointment = $this->appointment->fresh();
        if (!$appointment) {
            return;
        }

        $tenant = $appointment->tenant;
        if (!$tenant) {
            Log::warning("[SyncAppointmentToEmrJob] Tenant not found for appointment #{$appointment->id}");
            return;
        }

        // Only retry if pending EMR sync
        if ($appointment->status !== 'pending_emr_sync' && !str_starts_with((string)$appointment->unite_appointment_id, 'PENDING-')) {
            Log::info("[SyncAppointmentToEmrJob] Appointment #{$appointment->id} is already synced to EMR (ID: {$appointment->unite_appointment_id})");
            return;
        }

        Log::info("[SyncAppointmentToEmrJob] Attempting background sync for Appointment #{$appointment->id} to Unite EMR...");

        $payload = [
            'firstname' => $appointment->patient_firstname,
            'middlename' => $appointment->patient_middlename ?? '',
            'lastname' => $appointment->patient_lastname,
            'gender' => $appointment->patient_gender ?? 'U',
            'mobileno' => $appointment->patient_mobileno,
            'emailid' => $appointment->patient_email ?? '',
            'dob' => $appointment->patient_dob ?? '15-01-1990',
            'phototype' => $appointment->patient_phototype ?? 'EMIRATES_ID',
            'photoid' => $appointment->patient_photoid ?? '',
            'clinicid' => $appointment->clinic_id,
            'clinicname' => $appointment->clinic_name,
            'doctorid' => $appointment->doctor_id,
            'doctorname' => $appointment->doctor_name,
            'startdatetime' => $appointment->start_datetime ? $appointment->start_datetime->format('d-m-Y H:i') : now()->addDay()->format('d-m-Y 10:00'),
            'duration' => (string) ($appointment->duration_minutes ?: 30),
            'remarks' => $appointment->remarks ?? 'Booked via Bitrix24 CRM',
            'requestedby' => $appointment->requested_by ?? 'Bitrix24 CRM Agent',
            'itemcode' => $appointment->item_codes ?: [101],
            'b24_deal_id' => $appointment->b24_deal_id,
        ];

        try {
            $uniteResponse = $uniteClient->createAppointment($tenant, $payload);
            $uniteData = $uniteResponse['Data'] ?? [];
            $uniteApptId = (string) ($uniteData['appointmentid'] ?? '');

            if ($uniteApptId) {
                $appointment->update([
                    'unite_appointment_id' => $uniteApptId,
                    'status' => $uniteData['appointmentstatus'] ?? 'AAC',
                    'status_description' => 'Confirmed in Unite EMR',
                    'last_synced_source' => 'unite',
                    'synced_at' => now(),
                ]);

                Log::info("[SyncAppointmentToEmrJob] Background sync succeeded for Appointment #{$appointment->id}. New Unite EMR ID: #{$uniteApptId}");

                // Post update to Bitrix timeline without custom fields
                $comment = "✅ **Unite EMR Database Synchronized**\n" .
                    "• EMR Appointment ID: #{$uniteApptId}\n" .
                    "• Status: Confirmed (" . ($uniteData['appointmentstatus'] ?? 'AAC') . ")\n" .
                    "• Synced At: " . now()->format('d-m-Y H:i');

                if ($appointment->b24_deal_id) {
                    $bitrixService->addTimelineComment($tenant, $appointment->b24_deal_id, $comment, 'deal');
                }
            }
        } catch (UniteDatabaseUninitializedException $e) {
            Log::warning("[SyncAppointmentToEmrJob] Unite EMR database connection still uninitialized on vendor server. Releasing job back to queue for retry...");
            $this->release($this->backoff);
        } catch (\Exception $e) {
            Log::error("[SyncAppointmentToEmrJob] Error during background sync: " . $e->getMessage());
            throw $e;
        }
    }
}
