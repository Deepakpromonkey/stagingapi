<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScoringWeightRequest;
use App\Models\ScoringWeight;
use Illuminate\Http\Request;

class ScoringWeightController extends Controller
{
    public function store(StoreScoringWeightRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        $isTemplate = $request->boolean('save_as_template');

        // 1. ALWAYS UPDATE OR CREATE THEIR ACTIVE DEFAULTS (is_template = false)
        $activeWeight = ScoringWeight::updateOrCreate(
            [
                'company_id' => $user->company_id,
                'is_template' => false,
            ],
            [
                // Who last saved it.
                'user_id' => $user->id,
                'authority' => $validated['authority'],
                'insurance_coi' => $validated['insurance_coi'],
                'safety_csa' => $validated['safety_csa'],
                'inspection_vin' => $validated['inspection_vin'],
                'fraud_signals' => $validated['fraud_signals'],
                'payment_history' => $validated['payment_history'],
                'template_name' => null,
            ]
        );

        // 2. If they wanted a template, create it and return THAT in the response
        if ($isTemplate) {
            $templateWeight = ScoringWeight::create([
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'is_template' => true,
                'template_name' => $validated['template_name'] ?? 'New Template',
                'authority' => $validated['authority'],
                'insurance_coi' => $validated['insurance_coi'],
                'safety_csa' => $validated['safety_csa'],
                'inspection_vin' => $validated['inspection_vin'],
                'fraud_signals' => $validated['fraud_signals'],
                'payment_history' => $validated['payment_history'],
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Active weights updated AND template saved successfully.',
                'data' => $templateWeight,
            ], 200);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Active scoring weights updated successfully.',
            'data' => $activeWeight,
        ], 200);
    }

    public function getActiveWeights(Request $request)
    {
        $user = $request->user();

        $weights = ScoringWeight::where('company_id', $user->company_id)
            ->where('is_template', false)
            ->first();

        return response()->json([
            'status' => 'success',
            'message' => $weights ? 'Active weights retrieved.' : 'No active weights found. Using system defaults.',
            'data' => $weights,
        ], 200);
    }

    public function getTemplates(Request $request)
    {
        $user = $request->user();

        $templates = ScoringWeight::where('company_id', $user->company_id)
            ->where('is_template', true)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Templates retrieved successfully.',
            'data' => $templates,
        ], 200);
    }
}
