<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoiDocumentExtraction extends Model
{
    use HasFactory;
    protected $connection = 'external_db';

    protected $table = 'coi_document_extractions';

    public $timestamps = false;

    protected $fillable = [
        'coi_document_id',
        'dot_number',
        'status',
        'extracted_json',
        'error',
        'extracted_at'
    ];

    protected $casts = [
        'extracted_json' => 'array',
        'extracted_at' => 'datetime'
    ];
}
