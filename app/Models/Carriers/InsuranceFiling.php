<?php

namespace App\Models\Carriers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsuranceFiling extends Model
{
    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

    protected $table = 'insurance_filings';

    protected $fillable = [
        'docket_number', 'dot_number', 'ins_form_code', 'row_id', 'ins_cancl_form', 'ins_type_ind', 'ins_type_desc',
        'policy_no', 'min_cov_amount', 'ins_class_code', 'effective_date', 'underl_lim_amount',
        'max_cov_amount', 'cancl_effective_date', 'cancl_method', 'cancl_method_gen', 'inser_branch', 'name_company',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'cancl_effective_date' => 'date',
        'min_cov_amount' => 'decimal:2',
        'underl_lim_amount' => 'decimal:2',
        'max_cov_amount' => 'decimal:2',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'dot_number', 'dot_number');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('cancl_effective_date')
            ->orWhere('cancl_effective_date', '>', now());
    }
}
