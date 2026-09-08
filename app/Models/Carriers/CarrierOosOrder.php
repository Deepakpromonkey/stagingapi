<?php

namespace App\Models\Carriers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierOosOrder extends Model
{
    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

    protected $table = 'carrier_oos_orders';

    protected $fillable = ['dot_number', 'row_id', 'legal_name', 'dba_name', 'oos_date', 'oos_reason', 'status', 'rescind_date'];

    protected $casts = ['oos_date' => 'date', 'rescind_date' => 'date'];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'dot_number', 'dot_number');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('rescind_date');
    }
}
