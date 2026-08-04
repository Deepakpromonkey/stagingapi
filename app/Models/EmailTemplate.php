<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = [
        'uuid',
        'company_id',
        'user_id',
        'name',
        'type',
        'subject',
        'body_html',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Placeholders this template's type supports.
     */
    public function availableVariables(): array
    {
        return config("email_templates.types.{$this->type}.variables", []);
    }

    /**
     * Substitute {{ placeholder }} values in the subject and body.
     */
    public function render(array $data): array
    {
        return [
            'subject' => $this->replace($this->subject, $data),
            'body_html' => $this->replace($this->body_html, $data),
        ];
    }

    protected function replace(string $content, array $data): string
    {
        foreach ($data as $key => $value) {
            // Tolerates {{name}} and {{ name }} alike.
            $content = preg_replace(
                '/\{\{\s*'.preg_quote($key, '/').'\s*\}\}/',
                (string) $value,
                $content
            );
        }

        return $content;
    }
}
