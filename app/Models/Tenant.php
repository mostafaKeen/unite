<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'status',
        // Bitrix24
        'b24_domain',
        'b24_member_id',
        'b24_client_id',
        'b24_client_secret',
        'b24_access_token',
        'b24_refresh_token',
        'b24_token_expires_at',
        'b24_client_endpoint',
        'b24_deal_category_id',
        // Unite EMR
        'unite_environment',
        'unite_base_url',
        'unite_app_id',
        'unite_app_key',
        'unite_initial_token',
        'unite_access_token',
        'unite_refresh_token',
        'unite_token_expires_at',
        // Multiple clinics & caches
        'default_clinic_id',
        'clinics_cache',
        'doctors_cache',
        'items_cache',
        'settings',
    ];

    protected $appends = [
        'has_b24_client_secret',
        'has_b24_oauth',
    ];

    public function getHasB24ClientSecretAttribute(): bool
    {
        return !empty($this->attributes['b24_client_secret'] ?? null);
    }

    public function getHasB24OauthAttribute(): bool
    {
        return !empty($this->attributes['b24_access_token'] ?? null);
    }

    protected $casts = [
        'b24_client_secret' => 'encrypted',
        'b24_access_token' => 'encrypted',
        'b24_refresh_token' => 'encrypted',
        'unite_app_key' => 'encrypted',
        'unite_initial_token' => 'encrypted',
        'unite_access_token' => 'encrypted',
        'unite_refresh_token' => 'encrypted',
        'b24_token_expires_at' => 'datetime',
        'unite_token_expires_at' => 'datetime',
        'clinics_cache' => 'array',
        'doctors_cache' => 'array',
        'items_cache' => 'array',
        'settings' => 'array',
    ];

    protected $hidden = [
        'b24_client_secret',
        'b24_access_token',
        'b24_refresh_token',
        'unite_app_key',
        'unite_initial_token',
        'unite_access_token',
        'unite_refresh_token',
    ];

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class)->latest();
    }

    public function isUniteAuthenticated(): bool
    {
        return !empty($this->unite_access_token) && 
            ($this->unite_token_expires_at === null || $this->unite_token_expires_at->isFuture());
    }

    public function isBitrixAuthenticated(): bool
    {
        return !empty($this->b24_access_token) || !empty($this->b24_domain);
    }
}
