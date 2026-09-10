<?php

namespace App\Models;

use App\Models\Carrier;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    // Removing a teammate must not take their history with them — loads they
    // booked, payments they released and the invitations they sent all point
    // back here. `deleted_at` has been on this table since the first
    // migration; this only starts honouring it, so a removed user drops out
    // of every query (auth included) while the audit trail stays intact.
    use HasApiTokens, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'uuid',
        'company_id',
        'first_name',
        'last_name',
        'email',
        'phone',

        // Added in main branch.
        'country_code',

        'designation',
        'profile_image',
        'password',
        'is_owner',
        'status',
        'can_override_soft',
        'can_override_gate',
        'payment_release_limit',
        'must_change_password',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
        'is_owner' => 'boolean',
        'status' => 'boolean',
        'two_factor_enabled' => 'boolean',

        // Nullable on purpose: null means "inherit the role's capability".
        'can_override_soft' => 'boolean',
        'can_override_gate' => 'boolean',
        'payment_release_limit' => 'decimal:2',
        'must_change_password' => 'boolean',
        'last_login_at' => 'datetime',
        'last_password_changed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        // static::creating(function ($user) {
        //     if (empty($user->name)) {
        //         $user->name = trim($user->first_name . ' ' . $user->last_name);
        //     }
        // });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The single seat type this user holds.
     */
    public function role(): ?Role
    {
        return $this->roles->first();
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
     * Override capability is layered on top of the seat: the user column wins
     * when set, otherwise the role default applies.
     */
    public function canOverrideSoft(): bool
    {
        return ($this->getAttributes()['can_override_soft'] ?? null) === null
            ? (bool) $this->role()?->can_override_soft
            : (bool) $this->can_override_soft;
    }

    public function canOverrideGate(): bool
    {
        return ($this->getAttributes()['can_override_gate'] ?? null) === null
            ? (bool) $this->role()?->can_override_gate
            : (bool) $this->can_override_gate;
    }

    /**
     * Per-payment conditional release cap. Null means no cap.
     */
    public function paymentReleaseLimit(): ?string
    {
        return ($this->getAttributes()['payment_release_limit'] ?? null) === null
            ? $this->role()?->payment_release_limit
            : $this->payment_release_limit;
    }

    public function scoringWeights()
    {
        return $this->hasMany(ScoringWeight::class);
    }

    public function shortlistedCarriers()
    {
        return $this->belongsToMany(
            Carrier::class,
            'carrier_shortlists',
            'user_id',
            'carrier_id'
        )->withTimestamps();
    }
}