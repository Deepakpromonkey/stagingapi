<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

        return $template->render(array_merge($samples, $overrides));
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
            default => Str::headline($key),
        };
    }
}
