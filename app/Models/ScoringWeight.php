<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScoringWeight extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_template' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}