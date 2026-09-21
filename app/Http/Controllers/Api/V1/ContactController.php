<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitContactRequest;
use App\Models\ContactRequest;
use App\Mail\ContactFormSubmitted;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class ContactController extends Controller
{
    public function store(SubmitContactRequest $request)
    {
        // 1. Save to Database
        $contact = ContactRequest::create($request->validated());

        //info@dollartraq.com
        try {
            Mail::to('info@dollartraq.com')->send(new ContactFormSubmitted($contact));
        } catch (\Exception $e) {
            Log::error('Failed to send contact form email: ' . $e->getMessage());
        }

        // 3. Return Success
        return response()->json([
            'status' => 'success',
            'message' => 'Thank you for reaching out! Our team will contact you shortly.',
            'data' => $contact
        ], 201);
    }
}