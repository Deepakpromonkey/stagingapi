<?php

namespace App\Http\Controllers;

use App\Models\CarrierQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CarrierQuestionController extends Controller
{
    public function index(Request $request)
    {
        $questions = CarrierQuestion::where('company_id', $request->user()->company_id)
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $questions
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'question' => 'required|string',
            'answer_type' => 'required|string|in:Yes / No,Text,Textarea,Number,Image Upload',
            'is_required' => 'required|boolean',
        ]);

        $question = CarrierQuestion::create([
            'company_id' => $request->user()->company_id,  
            'user_id' => $request->user()->id,            
            'question' => $request->question,
            'answer_type' => $request->answer_type,
            'is_required' => $request->is_required,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Question created successfully.',
            'data' => $question
        ]);
    }

    public function update(Request $request, $id)
    {
        $question = CarrierQuestion::where('company_id', $request->user()->company_id)->find($id);

        if (!$question) {
            return response()->json(['status' => 'error', 'message' => 'Question not found or unauthorized.'], 404);
        }

        $request->validate([
            'question' => 'sometimes|required|string',
            'answer_type' => 'sometimes|required|string|in:Yes / No,Text,Textarea,Number,Image Upload',
            'is_required' => 'sometimes|required|boolean',
        ]);

        $question->update($request->only(['question', 'answer_type', 'is_required']));

        return response()->json([
            'status' => 'success',
            'message' => 'Question updated successfully.',
            'data' => $question
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $question = CarrierQuestion::where('company_id', $request->user()->company_id)->find($id);

        if (!$question) {
            return response()->json(['status' => 'error', 'message' => 'Question not found or unauthorized.'], 404);
        }

        $question->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Question deleted successfully.'
        ]);
    }
}