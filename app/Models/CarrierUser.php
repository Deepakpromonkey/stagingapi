<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * A person inside a carrier account — the owner created at the end of
 * onboarding, or someone they invited afterwards.
 *
 * Not a User: carriers hold no seat, role or permission in the broker
 * application, and must never be reachable from the team endpoints. They
 * authenticate through the same Sanctum guard, so every carrier token carries
 * the `carrier-portal` ability and the broker routes reject a tokenable that
 * is not a User — see EnsureCarrierUser / EnsureBrokerUser.
 *
 * Roles come from config/carrier_rbac.php under the `carrier` Spatie guard, so
 * a broker role can never be assigned here and vice versa.
 */
class CarrierUser extends Authenticatable
{
    use HasApiTokens, HasRoles, SoftDeletes;

    /** Ability stamped on every carrier token. */
    public const TOKEN_ABILITY = 'carrier-portal';

    /**
     * Spatie guard for every role and permission on this model. Without it,
     * carrier seats and broker seats would share a namespace.
     */
    protected string $guard_name = 'carrier';

    protected $fillable = [
        'uuid',
        'carrier_company_id',
        'first_name',
        'last_name',
        'email',
        'password',
        'must_change_password',
        'legal_name',
        'dot_number',
        'phone',
        'profile_image',
        'status',
        'is_owner',
        'invited_by',
        'two_factor_enabled',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
        'must_change_password' => 'boolean',
        'status' => 'boolean',
        'is_owner' => 'boolean',
        'two_factor_enabled' => 'boolean',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'last_password_changed_at' => 'datetime',
    ];

    /**
     * Every broker onboarding that resolved to this account. A carrier that
     * onboards with three brokers has one login and three of these.
     */
    public function connectRequests()
    {
        return $this->hasMany(CarrierConnectRequest::class, 'carrier_user_id');
    }

    /** Devices this carrier chose to be remembered on. */
    public function trustedDevices()
    {
        return $this->hasMany(CarrierTrustedDevice::class, 'carrier_user_id');
    }

    /** Every sign-in attempt against this account, successful or not. */
    public function loginAttempts()
    {
        return $this->hasMany(CarrierLoginAttempt::class, 'carrier_user_id');
    }

    /** The trucking company this person belongs to. */
    public function carrierCompany()
    {
        return $this->belongsTo(CarrierCompany::class, 'carrier_company_id');
    }

    /** The carrier owner who invited them, if they were invited. */
    public function inviter()
    {
        return $this->belongsTo(CarrierUser::class, 'invited_by');
    }

    /**
     * The single seat this person holds. Carriers get exactly one.
     */
    public function role(): ?Role
    {
        $role = $this->roles->first();

        return $role instanceof Role ? $role : null;
    }

    public function roleSlug(): ?string
    {
        return $this->role()?->slug;
    }

    public function roleLevel(): int
    {
        return (int) ($this->role()?->level ?? 0);
    }

    /**
     * The person's own name where we have it, falling back to the company and
     * then the email — an owner created by onboarding has no first name.
     */
    public function displayName(): string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $name ?: ($this->legal_name ?: $this->email);
    }

    /**
     * Public URL for the avatar, or null when none is set — the column holds
     * an S3 key, which is useless to the portal on its own.
     */
    public function profileImageUrl(): ?string
    {
        return $this->profile_image
            ? Storage::disk('s3')->url($this->profile_image)
            : null;
    }
}
