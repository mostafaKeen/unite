<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'unite_appointment_id',
        'b24_deal_id',
        'b24_contact_id',
        'clinic_id',
        'clinic_name',
        'doctor_id',
        'doctor_name',
        'patient_firstname',
        'patient_middlename',
        'patient_lastname',
        'patient_gender',
        'patient_mobileno',
        'patient_email',
        'patient_dob',
        'patient_phototype',
        'patient_photoid',
        'patient_pin',
        'patient_nationality',
        'start_datetime',
        'duration_minutes',
        'status',
        'status_description',
        'remarks',
        'requested_by',
        'item_codes',
        'invoice_reference',
        'invoice_details',
        'invoice_payments',
        'invoice_total',
        'last_synced_source',
        'sync_hash',
        'synced_at',
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'synced_at' => 'datetime',
        'item_codes' => 'array',
        'invoice_details' => 'array',
        'invoice_payments' => 'array',
        'invoice_total' => 'decimal:2',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function getPatientFullNameAttribute(): string
    {
        return trim("{$this->patient_firstname} {$this->patient_middlename} {$this->patient_lastname}");
    }
}
