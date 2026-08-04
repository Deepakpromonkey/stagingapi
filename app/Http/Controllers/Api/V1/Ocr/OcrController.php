<?php

namespace App\Http\Controllers\Api\V1\Ocr;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CoiDocumentExtraction;
use Illuminate\Support\Facades\Validator;

class OcrController extends Controller
{
    public function getOcrData(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'dot_number' => 'required|numeric'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        $dotNumber = $request->input('dot_number');

        $ocrData = CoiDocumentExtraction::where('dot_number', $dotNumber)
            ->orderBy('extracted_at', 'desc')
            ->get();

        if ($ocrData->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No OCR data found for DOT number: ' . $dotNumber,
                'data' => []
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'OCR data retrieved successfully.',
            'data' => $ocrData
        ], 200);
    }
}