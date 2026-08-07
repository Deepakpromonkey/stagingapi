<?php

namespace App\Http\Controllers;

use App\Models\SearchHistory;
use Illuminate\Http\Request;

class SearchHistoryController extends Controller
{
    public function index(Request $request)
    {
        $history = SearchHistory::where('company_id', $request->user()->company_id)
            ->latest() 
            ->take(10) 
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $history
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'carrier_id' => 'required|integer',
            'company_name' => 'required|string',
        ]);

        $log = SearchHistory::updateOrCreate(
            [
                'company_id' => $request->user()->company_id,
                'carrier_id' => $request->carrier_id,
            ],
            [
                'user_id' => $request->user()->id,
                'company_name' => $request->company_name,
            ]
        );

        $log->touch();

        return response()->json([
            'status' => 'success',
            'message' => 'Search logged successfully.',
            'data' => $log
        ]);
    }
}