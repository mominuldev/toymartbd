<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Notification\Actions\ArchiveNotificationTemplate;
use App\Domain\Notification\Actions\CreateCustomTemplate;
use App\Domain\Notification\Actions\RestoreTemplateVersion;
use App\Domain\Notification\Actions\SetWhatsAppApprovalStatus;
use App\Domain\Notification\Actions\UpdateNotificationTemplate;
use App\Domain\Notification\Exceptions\TemplateLibraryViolation;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Models\NotificationTemplateVersion;
use App\Domain\Notification\Services\TemplateMeasurer;
use App\Domain\Notification\Support\TemplateVariableCheck;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Notification\ArchiveNotificationTemplateRequest;
use App\Http\Requests\Admin\Notification\PreviewNotificationTemplateRequest;
use App\Http\Requests\Admin\Notification\RestoreNotificationTemplateVersionRequest;
use App\Http\Requests\Admin\Notification\StoreNotificationTemplateRequest;
use App\Http\Requests\Admin\Notification\UpdateNotificationTemplateRequest;
use App\Http\Requests\Admin\Notification\UpdateWhatsAppStatusRequest;
use App\Http\Resources\Admin\NotificationTemplatePreviewResource;
use App\Http\Resources\Admin\NotificationTemplateResource;
use App\Http\Resources\Admin\NotificationTemplateVersionResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Notification templates (P4-NOT-03, P4-I18N-03) and the Message templates library (P7) —
 * Settings → Automations → Message templates. System templates come from the code catalog
 * (edit copy, switch off — never create, archive, delete or re-key); staff-made marketing /
 * manual templates are created and archived here. Counts and cost are always computed
 * here, never by the client.
 */
final class NotificationTemplateController extends ApiController
{
    private const array FILTERS = ['channel', 'category', 'kind', 'active', 'archived', 'search'];

    private const array ENUM_FILTERS = [
        'channel' => ['sms', 'whatsapp', 'email'],
        'category' => ['security', 'order_updates', 'marketing'],
        'kind' => ['system', 'marketing', 'manual'],
        'active' => ['0', '1'],
        'archived' => ['0', '1'],
    ];

    /**
     * `GET /notifications/templates` — every template, ordered by key then channel; needs
     * settings.view. Archived templates are left out unless `filter[archived]=1`, which
     * returns only the archive.
     */
    #[QueryParameter('filter[channel]', 'Only one channel.', type: "'sms'|'whatsapp'|'email'")]
    #[QueryParameter('filter[category]', 'Only one opt-out category.', type: "'security'|'order_updates'|'marketing'")]
    #[QueryParameter('filter[kind]', 'System (event-bound) or staff-made.', type: "'system'|'marketing'|'manual'")]
    #[QueryParameter('filter[active]', 'Only switched on (1) or off (0).', type: "'0'|'1'")]
    #[QueryParameter('filter[archived]', '1 = only archived custom templates; default 0.', type: "'0'|'1'")]
    #[QueryParameter('filter[search]', 'Matches key, name or either body.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or invalid value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', NotificationTemplate::class);

        $filters = $request->query('filter', []);
        if (! is_array($filters)) {
            throw new BadRequestHttpException('filter must be an object.');
        }
        $unknown = array_diff(array_keys($filters), self::FILTERS);
        if ($unknown !== [] || $request->has('sort')) {
            throw new BadRequestHttpException('Allowed filters: '.implode(', ', self::FILTERS).'. Sorting is not supported.');
        }

        $query = NotificationTemplate::query()->with('editor')->orderBy('key')->orderBy('channel');
        $archived = false;
        foreach ($filters as $name => $value) {
            if (! is_string($value)) {
                throw new BadRequestHttpException("filter[{$name}] must be a string.");
            }
            if ($name === 'search') {
                $this->search($query, $value);

                continue;
            }
            if (! in_array($value, self::ENUM_FILTERS[$name], true)) {
                throw new BadRequestHttpException("filter[{$name}] must be one of: ".implode(', ', self::ENUM_FILTERS[$name]).'.');
            }
            match ($name) {
                'active' => $query->where('is_active', $value === '1'),
                'archived' => $archived = $value === '1',
                default => $query->where($name, $value),
            };
        }
        $archived ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');

        return NotificationTemplateResource::collection($query->get());
    }

    /** `GET /notifications/templates/{template}` — needs settings.view. */
    public function show(NotificationTemplate $template): NotificationTemplateResource
    {
        $this->authorize('view', $template);

        return new NotificationTemplateResource($template->load('editor'));
    }

    /**
     * `POST /notifications/templates` — a staff-made marketing / manual template, one row per
     * chosen channel under one generated `custom.*` key; needs settings.update. Returns the
     * created rows. Nothing sends a custom template yet.
     */
    #[OpenApiResponse(422, 'Unknown variable, variable outside the generic set, or missing field (`validation-error`)')]
    #[OpenApiResponse(201, 'The created rows, one per channel')]
    public function store(StoreNotificationTemplateRequest $request, CreateCustomTemplate $create): JsonResponse
    {
        $rows = $create->handle($request->toData(), $this->userId($request));

        return NotificationTemplateResource::collection(collect($rows)->map->load('editor'))->response()->setStatusCode(201);
    }

    /** `PUT /notifications/templates/{template}` — replace both bodies; re-measured and versioned on save. Needs settings.update. */
    #[OpenApiResponse(409, 'The template is archived (`conflict`)')]
    public function update(UpdateNotificationTemplateRequest $request, NotificationTemplate $template, UpdateNotificationTemplate $update): NotificationTemplateResource
    {
        try {
            $saved = $update->handle($template, $request->toData(), $this->userId($request));
        } catch (TemplateLibraryViolation $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        return new NotificationTemplateResource($saved->load('editor'));
    }

    /** `POST /notifications/templates/{template}/archive` — every channel of a custom template. Needs settings.update. */
    #[OpenApiResponse(409, 'A system template cannot be archived (`conflict`)')]
    public function archive(ArchiveNotificationTemplateRequest $request, NotificationTemplate $template, ArchiveNotificationTemplate $archive): AnonymousResourceCollection
    {
        return $this->archiveOrRestore($request, $template, $archive, true);
    }

    /** `POST /notifications/templates/{template}/unarchive` — bring a custom template back (switched off). Needs settings.update. */
    #[OpenApiResponse(409, 'A system template is never archived (`conflict`)')]
    public function unarchive(ArchiveNotificationTemplateRequest $request, NotificationTemplate $template, ArchiveNotificationTemplate $archive): AnonymousResourceCollection
    {
        return $this->archiveOrRestore($request, $template, $archive, false);
    }

    /** `GET /notifications/templates/{template}/versions` — saved copies, newest first. Needs settings.view. */
    public function versions(NotificationTemplate $template): AnonymousResourceCollection
    {
        $this->authorize('view', $template);

        return NotificationTemplateVersionResource::collection(
            $template->versions()->with('author')->orderByDesc('version')->limit(100)->get(),
        );
    }

    /** `POST /notifications/templates/{template}/versions/{version}/restore` — copy an old version back as a new one. Needs settings.update. */
    #[OpenApiResponse(409, 'The template is archived (`conflict`)')]
    #[OpenApiResponse(422, 'The version names a variable no longer allowed (`validation-error`)')]
    public function restoreVersion(RestoreNotificationTemplateVersionRequest $request, NotificationTemplate $template, int $version, RestoreTemplateVersion $restore): NotificationTemplateResource
    {
        $row = NotificationTemplateVersion::query()
            ->where('notification_template_id', $template->id)
            ->where('version', $version)
            ->firstOrFail();

        try {
            $saved = $restore->handle($template, $row, $this->userId($request));
        } catch (TemplateLibraryViolation $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        return new NotificationTemplateResource($saved->load('editor'));
    }

    /** `PUT /notifications/templates/{template}/whatsapp-status` — record the Meta approval state. Needs settings.update. */
    #[OpenApiResponse(409, 'Not a WhatsApp template (`conflict`)')]
    public function whatsAppStatus(UpdateWhatsAppStatusRequest $request, NotificationTemplate $template, SetWhatsAppApprovalStatus $set): NotificationTemplateResource
    {
        try {
            $saved = $set->handle($template, $request->status(), $this->userId($request));
        } catch (TemplateLibraryViolation $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        return new NotificationTemplateResource($saved->load('editor'));
    }

    /**
     * `POST /notifications/templates/{template}/preview` — measure unsaved bodies; writes
     * nothing. Needs settings.view. Reports unknown variables, WhatsApp parameters a body
     * dropped, and a warning when an SMS side runs to 3+ segments.
     */
    public function preview(PreviewNotificationTemplateRequest $request, NotificationTemplate $template, TemplateMeasurer $measurer): NotificationTemplatePreviewResource
    {
        $en = $request->string('body_en')->toString();
        $bn = $request->string('body_bn')->toString();
        $allowed = $template->isCustom() ? $template->allowedVariables() : null;

        return new NotificationTemplatePreviewResource([
            'en' => $measurer->measure($template->key, $template->channel, 'en', $en, $allowed),
            'bn' => $measurer->measure($template->key, $template->channel, 'bn', $bn, $allowed),
            'unknown_variables' => array_values(array_unique([
                ...TemplateVariableCheck::unknown($template, $en),
                ...TemplateVariableCheck::unknown($template, $bn),
            ])),
            'missing_variables' => array_values(array_unique([
                ...TemplateVariableCheck::missing($template, $en),
                ...TemplateVariableCheck::missing($template, $bn),
            ])),
        ]);
    }

    private function archiveOrRestore(Request $request, NotificationTemplate $template, ArchiveNotificationTemplate $archive, bool $toArchive): AnonymousResourceCollection
    {
        try {
            $rows = $archive->handle($template, $toArchive, $this->userId($request));
        } catch (TemplateLibraryViolation $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        return NotificationTemplateResource::collection(collect($rows)->map->load('editor'));
    }

    /** @param  Builder<NotificationTemplate>  $query */
    private function search(Builder $query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        if (mb_strlen($term) > 100) {
            throw new BadRequestHttpException('filter[search] must be at most 100 characters.');
        }
        $like = '%'.addcslashes($term, '%_\\').'%';
        $query->where(static function (Builder $q) use ($like): void {
            $q->where('key', 'like', $like)
                ->orWhere('name_en', 'like', $like)
                ->orWhere('name_bn', 'like', $like)
                ->orWhere('body_en', 'like', $like)
                ->orWhere('body_bn', 'like', $like);
        });
    }

    private function userId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
