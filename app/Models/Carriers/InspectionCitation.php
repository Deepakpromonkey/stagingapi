<?php

namespace App\Models\Carriers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionCitation extends Model
{
    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

    protected $table = 'inspection_citations';

    protected $fillable = ['change_date', 'row_id', 'inspection_id', 'vioseqnum', 'adjseq', 'citation_code', 'citation_result'];

    protected $casts = ['change_date' => 'date'];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class, 'inspection_id', 'unique_id');
    }
}
