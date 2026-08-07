<?php

namespace App\Models\Carriers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsuranceFilingPending extends Model
{
    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

    protected $table = 'insurance_filings_pending';

    protected $fillable = [
        'docket_number', 'dot_number', 'ins_form_code', 'row_id', 'ins_type_desc', 'ins_type_ind',
        'name_company', 'policy_no', 'recv_date', 'ins_class_code', 'underl_lim_amount',
        'max_cov_amount', 'rej_date', 'inser_branch', 'rej_reasons', 'min_cov_amount',
    ];

    protected $casts = [
        'recv_date' => 'date',
        'rej_date' => 'date',
        'min_cov_amount' => 'decimal:2',
        'underl_lim_amount' => 'decimal:2',
        'max_cov_amount' => 'decimal:2',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'dot_number', 'dot_number');
    }

    public function scopeRejected($query)
    {
        return $query->whereNotNull('rej_date');
    }
}
