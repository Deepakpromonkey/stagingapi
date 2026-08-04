<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'company_name',
        'company_email',
        'company_phone',
        'website',
        'logo',
        'industry',
        'address',
        'city',
        'state',
        'country',
        'zip_code',
        'status',
        'created_by',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
