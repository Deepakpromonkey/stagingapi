<?php

namespace App\Models\Carriers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMeasure extends Model
{
    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

    protected $table = 'sms_measures';

    protected $fillable = [
        'dot_number',
        'insp_total', 'driver_insp_total', 'driver_oos_insp_total', 'row_id',
        'vehicle_insp_total', 'vehicle_oos_insp_total',
        'unsafe_driv_insp_w_viol', 'unsafe_driv_measure', 'unsafe_driv_ac',
        'hos_driv_insp_w_viol', 'hos_driv_measure', 'hos_driv_ac',
        'driv_fit_insp_w_viol', 'driv_fit_measure', 'driv_fit_ac',
        'contr_subst_insp_w_viol', 'contr_subst_measure', 'contr_subst_ac',
        'veh_maint_insp_w_viol', 'veh_maint_measure', 'veh_maint_ac',
    ];

    protected $casts = [
        'unsafe_driv_ac' => 'boolean',
        'hos_driv_ac' => 'boolean',
        'driv_fit_ac' => 'boolean',
        'contr_subst_ac' => 'boolean',
        'veh_maint_ac' => 'boolean',
        'unsafe_driv_measure' => 'decimal:4',
        'hos_driv_measure' => 'decimal:4',
        'driv_fit_measure' => 'decimal:4',
        'contr_subst_measure' => 'decimal:4',
        'veh_maint_measure' => 'decimal:4',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'dot_number', 'dot_number');
    }

    /** Returns true if any BASIC has an active/critical investigation flag */
    public function hasAcFlag(): bool
    {
        return $this->unsafe_driv_ac || $this->hos_driv_ac || $this->driv_fit_ac
            || $this->contr_subst_ac || $this->veh_maint_ac;
    }
}
