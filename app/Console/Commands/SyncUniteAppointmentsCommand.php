<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Appointment;
use App\Services\Unite\UniteClient;
use App\Services\Sync\AppointmentSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncUniteAppointmentsCommand extends Command
{
    protected $signature = 'unite:sync-appointments {--tenant= : Specific tenant UUID}';
    protected $description = 'Poll Unite EMR for appointment status changes and synchronize back to Bitrix24 CRM';

    public function handle(UniteClient $uniteClient, AppointmentSyncService $syncService): int
    {
        $tenantId = $this->option('tenant');
        $query = Tenant::where('status', 'active');
        if ($tenantId) {
            $query->where('id', $tenantId);
        }

        $tenants = $query->get();
        $this->info("Starting appointment sync for {$tenants->count()} tenants...");

        $today = now()->format('d-m-Y');

        foreach ($tenants as $tenant) {
            $this->line("Processing tenant: {$tenant->name}");
            $clinics = $tenant->clinics_cache ?: $uniteClient->getClinics($tenant);

            foreach ($clinics as $clinic) {
                $clinicId = $clinic['clinic_id'] ?? null;
                if (!$clinicId) continue;

                $appointments = $uniteClient->getAllAppointments($tenant, $clinicId, $today, $today);

                foreach ($appointments as $remote) {
                    $remoteId = (string) ($remote['appointmentid'] ?? '');
                    $remoteStatus = $remote['status'] ?? null;

                    if (!$remoteId || !$remoteStatus) continue;

                    $local = Appointment::where('tenant_id', $tenant->id)
                        ->where('unite_appointment_id', $remoteId)
                        ->first();

                    if ($local && $local->status !== $remoteStatus) {
                        $this->info("Status changed for #{$remoteId}: {$local->status} -> {$remoteStatus}");
                        $syncService->updateStatus($tenant, $local, $remoteStatus, 'unite');
                    }
                }
            }
        }

        $this->info('Appointment sync completed.');
        return Command::SUCCESS;
    }
}
