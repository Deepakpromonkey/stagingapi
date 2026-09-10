<?php
 
namespace App\Http\Controllers\Api\V1\Shipment;
 
use App\Http\Controllers\Api\V1\BaseController;
use App\Models\ShipmentTemplate;
 
class ShipmentTemplateController extends BaseController
{
    public function index()
    {
        $templates = ShipmentTemplate::where('company_id', auth()->user()->company_id)
            ->select('tracking_number', 'template_name')
            ->latest()
            ->get();
 
        return $this->success($templates, 'Templates retrieved successfully.', 200);
    }
 
    public function show($tracking_number)
    {
        $template = ShipmentTemplate::where('company_id', auth()->user()->company_id)
            ->where('tracking_number', $tracking_number)
            ->first();
 
        if (!$template) {
            return $this->error('Template not found.', 404);
        }
 
        return $this->success($template->template_data, 'Template data retrieved successfully.', 200);
    }
}
