<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,

            'type' => $this->type,
            'type_label' => config("email_templates.types.{$this->type}.label", 'Custom'),

            'subject' => $this->subject,
            'body_html' => $this->body_html,

            'is_active' => $this->is_active,
            'status' => $this->is_active ? 'active' : 'inactive',
            'is_default' => $this->is_default,

            // Placeholders the editor can offer for this type.
            'available_variables' => $this->availableVariables(),

            'updated_by' => $this->whenLoaded('author', fn () => trim(
                $this->author->first_name.' '.$this->author->last_name
            )),

            'created_at' => $this->created_at?->format('m/d/y'),
            'updated_at' => $this->updated_at?->format('m/d/y'),
        ];
    }
}
