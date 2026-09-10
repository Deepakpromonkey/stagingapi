<?php

namespace App\Models\Carriers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierAuthorityOrder extends Model
{
    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

    protected $table = 'carrier_authority_orders';

    protected $fillable = ['docket_number', 'row_id', 'dot_number', 'type_license', 'order1_serve_date', 'order2_type_desc', 'order2_effective_date'];

    protected $casts = ['order1_serve_date' => 'date', 'order2_effective_date' => 'date'];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'dot_number', 'dot_number');
    }
}
