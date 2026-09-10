<?php

namespace App\Models\Carriers;

use App\Casts\FmcsaFlag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The MCS-150 operation classification flags.
 *
 * These live in the SMS census extract rather than the main census file, so
 * they are a separate record. Coverage is only the ~761k SMS-rated carriers of
 * the 4.48M in `carriers`; everything else reports null, which reads as "not
 * set" rather than "false".
 *
 * Kept off the `carriers` view deliberately: joining it in turned the search's
 * paging query from an index scan into a full scan of 4M rows.
 */
class CarrierCensus extends Model
{
    protected $connection = 'external_db';

    protected $table = 'carrier_census';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $casts = [
        'pc_flag' => FmcsaFlag::class,
        'private_only' => FmcsaFlag::class,
        'authorized_for_hire' => FmcsaFlag::class,
        'exempt_for_hire' => FmcsaFlag::class,
        'private_property' => FmcsaFlag::class,
        'private_passenger_business' => FmcsaFlag::class,
        'private_passenger_nonbusiness' => FmcsaFlag::class,
        'migrant' => FmcsaFlag::class,
        'us_mail' => FmcsaFlag::class,
        'federal_government' => FmcsaFlag::class,
        'state_government' => FmcsaFlag::class,
        'local_government' => FmcsaFlag::class,
        'indian_tribe' => FmcsaFlag::class,
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'dot_number', 'dot_number');
    }
}
