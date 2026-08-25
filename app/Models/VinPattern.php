<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A decoded VIN pattern — see App\Support\Vin for what a pattern is and why
 * the cache is keyed on one instead of on the full VIN.
 */
class VinPattern extends Model
{
    protected $table = 'vin_patterns';

    protected $primaryKey = 'pattern';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'pattern', 'model_year', 'make', 'model',
        'vehicle_type', 'body_class', 'gvwr',
        'is_trailer', 'status', 'attempts', 'decoded_at',
    ];

    protected $casts = [
        'model_year' => 'integer',
        'is_trailer' => 'boolean',
        'attempts' => 'integer',
        'decoded_at' => 'datetime',
    ];

    public function scopeDecoded($query)
    {
        return $query->where('status', 'ok');
    }

    /**
     * Age in years, or null when the pattern never decoded to a model year.
     * Years outside a plausible range are treated as a bad decode: vPIC
     * occasionally reports a model year a year or two ahead of the calendar,
     * and the FMCSA feed contains VINs that decode to the 1960s by accident.
     */
    public function age(?int $currentYear = null): ?int
    {
        if (! $this->model_year) {
            return null;
        }

        $age = ($currentYear ?? (int) date('Y')) - $this->model_year;

        return ($age < 0 || $age > 60) ? null : $age;
    }
}
