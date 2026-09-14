<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $role
 * @property string|null $tenant_id
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role', 'tenant_id', 'b24_user_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_TENANT_ADMIN = 'tenant_admin';
    public const ROLE_TENANT_USER = 'tenant_user';

    /**
     * Find existing or create new user authenticated via Bitrix24 OAuth under specific tenant
     */
    public static function findOrCreateFromBitrix(Tenant $tenant, array $b24User): self
    {
        $b24UserId = (string) ($b24User['ID'] ?? '');
        $email = $b24User['EMAIL'] ?? null;
        $firstName = $b24User['NAME'] ?? '';
        $lastName = $b24User['LAST_NAME'] ?? '';
        $fullName = trim("{$firstName} {$lastName}") ?: ($email ?: "Bitrix User {$b24UserId}");
        $isAdmin = !empty($b24User['ADMIN']) || !empty($b24User['is_admin']);

        // 1. Try finding by tenant_id + b24_user_id
        if (!empty($b24UserId)) {
            $existing = self::where('tenant_id', $tenant->id)
                ->where('b24_user_id', $b24UserId)
                ->first();

            if ($existing) {
                $existing->update([
                    'name' => $fullName,
                    'email' => $email ?: $existing->email,
                ]);
                return $existing;
            }
        }

        // 2. Try finding by tenant_id + email
        if (!empty($email)) {
            $existing = self::where('tenant_id', $tenant->id)
                ->where('email', $email)
                ->first();

            if ($existing) {
                $existing->update([
                    'b24_user_id' => $b24UserId ?: $existing->b24_user_id,
                    'name' => $fullName,
                ]);
                return $existing;
            }
        }

        // 3. Create new user under this tenant
        return self::create([
            'tenant_id' => $tenant->id,
            'b24_user_id' => $b24UserId,
            'name' => $fullName,
            'email' => $email ?: "b24_{$b24UserId}@{$tenant->slug}.local",
            'role' => $isAdmin ? self::ROLE_TENANT_ADMIN : self::ROLE_TENANT_USER,
            'password' => bcrypt(\Illuminate\Support\Str::random(32)),
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isTenantAdmin(): bool
    {
        return $this->role === self::ROLE_TENANT_ADMIN;
    }

    public function canManageTenants(): bool
    {
        return $this->isSuperAdmin();
    }

    public function canManageTenantUsers(?Tenant $targetTenant = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->isTenantAdmin()) {
            return $targetTenant ? $this->tenant_id === $targetTenant->id : true;
        }

        return false;
    }
}
