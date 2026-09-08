<?php

namespace App\Models;

use App\Models\Carriers\Carrier;
use Illuminate\Database\Eloquent\Model;

/**
 * A carrier shortlisted by a company. `user_id` records who added it.
 */
class CarrierShortlist extends Model
{
    protected $table = 'carrier_shortlists';

    protected $guarded = [];

    public function carrier()
    {
        return $this->belongsTo(Carrier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
