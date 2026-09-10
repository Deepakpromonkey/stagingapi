<?php
 
namespace App\Models;
 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
 
class ShipmentTemplate extends Model
{
    use HasFactory;
 
    protected $fillable = [
        'company_id',
        'user_id',
        'tracking_number',
        'template_name',
        'template_data'
    ];
 
    protected $casts = [
        'template_data' => 'array',
    ];
}
