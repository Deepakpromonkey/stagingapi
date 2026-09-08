<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgreementDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,

            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,

            'is_active' => $this->is_active,
            'status' => $this->is_active ? 'active' : 'inactive',

            // Signed, expires in 5 minutes — the bucket stays private.
            'download_url' => $this->downloadUrl(),

            'uploaded_by' => $this->whenLoaded('uploader', fn () => trim(
                $this->uploader->first_name.' '.$this->uploader->last_name
            )),

            'created_at' => $this->created_at?->format('m/d/y'),
            'updated_at' => $this->updated_at?->format('m/d/y'),
        ];
    }
}
