<?php

namespace App\Models\Carriers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsuranceFilingHistory extends Model
{
    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

    protected $table = 'insurance_filings_history';

    protected $fillable = [
        'docket_number', 'dot_number', 'ins_form_code', 'row_id', 'ins_type_desc', 'name_company',
        'policy_no', 'trans_date', 'underl_lim_amount', 'max_cov_amount', 'effective_date', 'cancl_effective_date',
    ];

    protected $casts = [
        'trans_date' => 'date',
        'effective_date' => 'date',
        'cancl_effective_date' => 'date',
        'underl_lim_amount' => 'decimal:2',
        'max_cov_amount' => 'decimal:2',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'dot_number', 'dot_number');
    }
}
