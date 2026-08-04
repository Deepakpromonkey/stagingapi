<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'company_id',
        'user_id',
        'role_id',
        'can_override_soft',
        'can_override_gate',
        'first_name',
        'last_name',
        'phone',
        'email',
        'token',
        'expires_at',
        'accepted_at',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        // Nullable: null means "inherit the role's capability".
        'can_override_soft' => 'boolean',
        'can_override_gate' => 'boolean',
    ];

    /**
     * Invitation belongs to a Company.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Invitation belongs to a Role.
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Account created for the invitee.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * User who sent the invitation.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
