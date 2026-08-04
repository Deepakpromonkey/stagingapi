<?php

namespace App\Http\Controllers\Api\V1\EmailTemplate;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\EmailTemplate\StoreEmailTemplateRequest;
use App\Http\Requests\EmailTemplate\UpdateEmailTemplateRequest;
use App\Http\Resources\EmailTemplateResource;
use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use Illuminate\Http\Request;

class EmailTemplateController extends BaseController
{
    public function __construct(
        protected EmailTemplateService $emailTemplateService
    ) {}

    /**
     * Company's templates. Filter with ?type= and ?status=active|inactive.
     */
    public function index(Request $request)
    {
        $status = $request->query('status');

        $templates = $this->emailTemplateService->list(
            $request->user(),
            array_filter([
                'type' => $request->query('type'),
                'is_active' => $status === null ? null : $status === 'active',
            ], fn ($value) => $value !== null),
            (int) $request->query('per_page', 15),
        );

        return $this->success([
            'templates' => EmailTemplateResource::collection($templates),
            'pagination' => [
                'current_page' => $templates->currentPage(),
                'per_page' => $templates->perPage(),
                'total' => $templates->total(),
                'last_page' => $templates->lastPage(),
                'has_more_pages' => $templates->hasMorePages(),
            ],
        ], 'Email templates retrieved successfully.');
    }

    /**
     * Template types and the placeholders each one supports, for the editor.
     */
    public function variables()
    {
        return $this->success(
            collect(config('email_templates.types'))
                ->map(fn ($type, $key) => [
                    'type' => $key,
                    'label' => $type['label'],
                    'description' => $type['description'],
                    'variables' => collect($type['variables'])
                        ->map(fn ($label, $name) => [
                            'name' => $name,
                            'placeholder' => '{{'.$name.'}}',
                            'description' => $label,
                        ])->values(),
                ])->values(),
            'Template variables retrieved successfully.'
        );
    }

    public function store(StoreEmailTemplateRequest $request)
    {
        $template = $this->emailTemplateService->create(
            $request->user(),
            $request->validated()
        );

        return $this->success(
            new EmailTemplateResource($template),
            'Email template created successfully.',
            201
        );
    }

    public function show(Request $request, string $uuid)
    {
        $template = $this->find($request, $uuid);

        if (! $template) {
            return $this->error('Email template not found.', null, 404);
        }

        return $this->success(
            new EmailTemplateResource($template),
            'Email template retrieved successfully.'
        );
    }

    public function update(UpdateEmailTemplateRequest $request, string $uuid)
    {
        $template = $this->find($request, $uuid);

        if (! $template) {
            return $this->error('Email template not found.', null, 404);
        }

        $template = $this->emailTemplateService->update(
            $template,
            $request->user(),
            $request->validated()
        );

        return $this->success(
            new EmailTemplateResource($template),
            'Email template updated successfully.'
        );
    }

    public function toggleStatus(Request $request, string $uuid)
    {
        $template = $this->find($request, $uuid);

        if (! $template) {
            return $this->error('Email template not found.', null, 404);
        }

        $template = $this->emailTemplateService->update(
            $template,
            $request->user(),
            ['is_active' => ! $template->is_active]
        );

        return $this->success(
            new EmailTemplateResource($template),
            $template->is_active ? 'Email template activated.' : 'Email template deactivated.'
        );
    }

    /**
     * Render the template with sample values so the editor can show what the
     * mail will look like. Pass `variables` to override any of them.
     */
    public function preview(Request $request, string $uuid)
    {
        $template = $this->find($request, $uuid);

        if (! $template) {
            return $this->error('Email template not found.', null, 404);
        }

        $request->validate([
            'variables' => ['nullable', 'array'],
        ]);

        return $this->success(
            $this->emailTemplateService->preview(
                $template,
                $request->user(),
                $request->input('variables', [])
            ),
            'Preview generated successfully.'
        );
    }

    public function destroy(Request $request, string $uuid)
    {
        $template = $this->find($request, $uuid);

        if (! $template) {
            return $this->error('Email template not found.', null, 404);
        }

        $this->emailTemplateService->delete($template);

        return $this->success(null, 'Email template deleted successfully.');
    }

    /**
     * Scoped to the caller's company, so another company's uuid is a 404.
     */
    protected function find(Request $request, string $uuid): ?EmailTemplate
    {
        return EmailTemplate::forCompany($request->user()->company_id)
            ->with('author:id,first_name,last_name')
            ->where('uuid', $uuid)
            ->first();
    }
}
