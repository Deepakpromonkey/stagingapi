<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'company_name' => $this->company_name,
            'company_email' => $this->company_email,
            'company_phone' => $this->company_phone,
            'website' => $this->website,
            'logo' => $this->logo,
            'industry' => $this->industry,

            // Captured at signup. dot_number is null whenever the company has
            // no authority number, which is normal rather than incomplete.
            'business_type' => $this->business_type,
            'business_type_label' => $this->businessTypeLabel(),
            'dot_number' => $this->dot_number,

            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'zip_code' => $this->zip_code,

            // Lets the client route a freshly signed-up company straight to
            // plan selection.
            'subscription' => $this->whenLoaded(
                'subscription',
                fn () => $this->subscription
                    ? new SubscriptionResource($this->subscription)
                    : null
            ),
        ];
    }
}
