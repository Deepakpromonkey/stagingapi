<?php

namespace App\Services;

use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class EmailTemplateService
{
    public function list(User $user, array $filters = [], int $perPage = 15)
    {
        return EmailTemplate::forCompany($user->company_id)
            ->with('author:id,first_name,last_name')
            ->when(! empty($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', $filters['is_active']))
            ->orderByDesc('is_default')
            ->latest()
            ->paginate($perPage);
    }

    public function create(User $user, array $data): EmailTemplate
    {
        return DB::transaction(function () use ($user, $data) {
            $template = EmailTemplate::create([
                'uuid' => Str::uuid(),
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'name' => $data['name'],
                'type' => $data['type'] ?? 'custom',
                'subject' => $data['subject'],
                'body_html' => $this->sanitize($data['body_html']),
                'is_active' => $data['is_active'] ?? true,
                'is_default' => $data['is_default'] ?? false,
            ]);

            if ($template->is_default) {
                $this->clearOtherDefaults($template);
            }

            return $template->load('author:id,first_name,last_name');
        });
    }

    public function update(EmailTemplate $template, User $user, array $data): EmailTemplate
    {
        return DB::transaction(function () use ($template, $user, $data) {
            if (array_key_exists('body_html', $data)) {
                $data['body_html'] = $this->sanitize($data['body_html']);
            }

            $template->fill($data);

            // Record who last edited it.
            $template->user_id = $user->id;

            $template->save();

            if ($template->is_default) {
                $this->clearOtherDefaults($template);
            }

            return $template->fresh('author');
        });
    }

    public function delete(EmailTemplate $template): void
    {
        $template->delete();
    }

    /**
     * Render with sample values so the editor can show a live preview.
     */
    public function preview(EmailTemplate $template, User $user, array $overrides = []): array
    {
        $samples = collect($template->availableVariables())
            ->mapWithKeys(fn ($label, $key) => [$key => $this->sampleValue($key, $user)])
            ->all();

        return $template->render(array_merge(['logo_url' => $this->logoUrl()], $samples, $overrides));
    }

    /**
     * The subject and body to send for a type of mail: the company's own
     * template when it has an active one, the stock design otherwise.
     * `logo_url` is always supplied, so a template can use the hosted logo.
     */
    public function resolve(?int $companyId, string $type, array $data): array
    {
        $template = $companyId === null ? null : EmailTemplate::forCompany($companyId)
            ->active()
            ->where('type', $type)
            ->orderByDesc('is_default')
            ->latest('updated_at')
            ->first();

        $template ??= $this->stock($type);

        return $template->render(array_merge(['logo_url' => $this->logoUrl()], $data));
    }

    /**
     * The packaged design for a type, unsaved.
     */
    public function stock(string $type): EmailTemplate
    {
        return new EmailTemplate([
            'name' => config("email_templates.types.{$type}.label"),
            'type' => $type,
            'subject' => config("email_templates.types.{$type}.default_subject"),
            'body_html' => File::get(resource_path("views/emails/templates/{$type}.html")),
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    /**
     * Copy every stock design into the company as an editable template. A type
     * the company already has a template for is left alone, so running this
     * again never overwrites anyone's edits.
     */
    public function installDefaults(Company $company): void
    {
        $types = collect(config('email_templates.types'))
            ->filter(fn ($definition) => ! empty($definition['default_subject']))
            ->keys();

        $existing = EmailTemplate::forCompany($company->id)
            ->whereIn('type', $types)
            ->pluck('type')
            ->all();

        foreach ($types->diff($existing) as $type) {
            $stock = $this->stock($type);

            EmailTemplate::create([
                'uuid' => Str::uuid(),
                'company_id' => $company->id,
                'user_id' => null,
                'name' => $stock->name,
                'type' => $type,
                'subject' => $stock->subject,

                // The logo is written in as an absolute URL so the editor can
                // show it; every other placeholder stays for send time.
                'body_html' => $stock->render(['logo_url' => $this->logoUrl()])['body_html'],

                'is_active' => true,
                'is_default' => true,
            ]);
        }
    }

    /**
     * Hosted, not embedded: Gmail and Outlook drop base64 images.
     */
    public function logoUrl(): string
    {
        return rtrim((string) config('email_templates.asset_url'), '/').'/images/email/dollartraq-logo.png';
    }

    /**
     * Only one default per type per company.
     */
    protected function clearOtherDefaults(EmailTemplate $template): void
    {
        EmailTemplate::forCompany($template->company_id)
            ->where('type', $template->type)
            ->where('id', '!=', $template->id)
            ->update(['is_default' => false]);
    }

    /**
     * Rich text from the editor is stored as-is apart from anything that could
     * execute when the template is previewed in the dashboard.
     */
    protected function sanitize(string $html): string
    {
        // Script and iframe blocks, including their content.
        $html = preg_replace('#<\s*(script|iframe|object|embed)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);

        // Self-closing or unclosed variants of the same tags.
        $html = preg_replace('#<\s*/?\s*(script|iframe|object|embed)\b[^>]*>#i', '', $html);

        // Inline event handlers: onclick=, onerror=, …
        $html = preg_replace('#\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)#is', '', $html);

        // javascript: URLs in href/src.
        $html = preg_replace('#(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*#i', '$1=$2#', $html);

        return trim($html);
    }

    protected function sampleValue(string $key, User $user): string
    {
        return match ($key) {
            'first_name' => $user->first_name ?: 'Nina',
            'last_name' => $user->last_name ?: 'Patel',
            'email' => 'nina.patel@example.com',
            'temporary_password' => 'lP6K6P2AZ9IO',
            'role_name' => 'Agent',
            'company_name' => $user->company?->company_name ?? 'Your Company',
            'invited_by', 'sender_name' => trim($user->first_name.' '.$user->last_name),
            'login_url' => rtrim(config('app.frontend_url'), '/').'/login',
            'expires_at' => now()->addDays(7)->format('m/d/y'),
            'otp' => '482913',
            'minutes' => '10',
            'carrier_name' => 'POWELL DISTRIBUTING CO INC',
            'dot_number' => '10000',
            'mc_number' => 'MC189048',
            'agreement_url' => rtrim(config('app.frontend_url'), '/').'/agreements/sample',
            'connect_url' => rtrim(config('app.url'), '/').'/carrier/connect/sample-token',
            'accept_url' => rtrim(config('app.frontend_url'), '/').'/accept-invitation?token=sample-token',
            'sent_at' => now()->format('m/d/y'),
            'report_id' => 'RPT-'.strtoupper(Str::random(8)),
            'report_url' => rtrim(config('app.frontend_url'), '/').'/carriers/sample',
            'logo_url' => $this->logoUrl(),
            default => Str::headline($key),
        };
    }
}
