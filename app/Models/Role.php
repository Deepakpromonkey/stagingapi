<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected $fillable = [
        'slug',
        'name',
        'guard_name',
        'description',
        'level',
        'can_override_soft',
        'can_override_gate',
        'payment_release_limit',
        'is_active',
    ];

    protected $casts = [
        'level' => 'integer',
        'can_override_soft' => 'boolean',
        'can_override_gate' => 'boolean',
        'payment_release_limit' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSlugs(Builder $query, array $slugs): Builder
    {
        return $query->whereIn('slug', $slugs);
    }

    /**
     * Risk authority label declared for this seat in config/rbac.php.
     */
    public function riskAuthority(): string
    {
        return config("rbac.roles.{$this->slug}.risk_authority", 'none');
    }
}
