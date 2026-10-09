<?php

use App\Models\Company;
use App\Services\EmailTemplateService;
use Illuminate\Database\Migrations\Migration;

/**
 * Gives every existing company the stock email designs as editable templates.
 * New companies get them at signup. A company that already has a template of
 * a type keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $service = app(EmailTemplateService::class);

        Company::query()->select('id')->chunkById(200, function ($companies) use ($service) {
            foreach ($companies as $company) {
                $service->installDefaults($company);
            }
        });
    }

    public function down(): void
    {
        // Left in place: by now they may hold the company's own edits.
    }
};
