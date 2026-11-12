<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Notification\Actions\UpdateNotificationTemplate;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Services\TemplateMeasurer;
use App\Domain\Notification\Support\TemplateRenderer;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Notification\PreviewNotificationTemplateRequest;
use App\Http\Requests\Admin\Notification\UpdateNotificationTemplateRequest;
use App\Http\Resources\Admin\NotificationTemplatePreviewResource;
use App\Http\Resources\Admin\NotificationTemplateResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Notification templates (P4-NOT-03, P4-I18N-03) — Settings → Automations → Templates.
 * The set is fixed by the code catalog (no create/delete); staff edit EN + BN copy and
 * switch a template off. Counts and cost are always computed here, never by the client.
 */
final class NotificationTemplateController extends ApiController
{
    private const array FILTERS = ['channel', 'category'];

    /**
     * `GET /notifications/templates` — every template, ordered by key then channel; needs
     * settings.view. Filters: `filter[channel]` = sms|whatsapp|email, `filter[category]`.
     */
    #[QueryParameter('filter[channel]', 'Only one channel.', type: "'sms'|'whatsapp'|'email'")]
    #[QueryParameter('filter[category]', 'Only one category.', type: "'security'|'order_updates'|'marketing'")]
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

        $query = NotificationTemplate::query()->orderBy('key')->orderBy('channel');
        $allowed = ['channel' => ['sms', 'whatsapp', 'email'], 'category' => ['security', 'order_updates', 'marketing']];
        foreach ($filters as $name => $value) {
            if (! is_string($value) || ! in_array($value, $allowed[$name], true)) {
                throw new BadRequestHttpException("filter[{$name}] must be one of: ".implode(', ', $allowed[$name]).'.');
            }
            $query->where($name, $value);
        }

        return NotificationTemplateResource::collection($query->get());
    }

    /** `GET /notifications/templates/{template}` — needs settings.view. */
    public function show(NotificationTemplate $template): NotificationTemplateResource
    {
        $this->authorize('view', $template);

        return new NotificationTemplateResource($template);
    }

    /** `PUT /notifications/templates/{template}` — replace both bodies; re-measured on save. Needs settings.update. */
    public function update(UpdateNotificationTemplateRequest $request, NotificationTemplate $template, UpdateNotificationTemplate $update): NotificationTemplateResource
    {
        $userId = $request->user()?->getAuthIdentifier();

        return new NotificationTemplateResource($update->handle($template, $request->toData(), is_int($userId) ? $userId : null));
    }

    /** `POST /notifications/templates/{template}/preview` — measure unsaved bodies; writes nothing. Needs settings.view. */
    public function preview(PreviewNotificationTemplateRequest $request, NotificationTemplate $template, TemplateMeasurer $measurer): NotificationTemplatePreviewResource
    {
        $en = $request->string('body_en')->toString();
        $bn = $request->string('body_bn')->toString();
        $allowed = $template->allowedVariables();

        return new NotificationTemplatePreviewResource([
            'en' => $measurer->measure($template->key, $template->channel, 'en', $en),
            'bn' => $measurer->measure($template->key, $template->channel, 'bn', $bn),
            'unknown_variables' => array_values(array_unique([
                ...TemplateRenderer::unknownPlaceholders($en, $allowed),
                ...TemplateRenderer::unknownPlaceholders($bn, $allowed),
            ])),
        ]);
    }
}
