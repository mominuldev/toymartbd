<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Audit\AuditFilters;
use App\Http\Resources\Admin\AuditEntry;
use App\Http\Resources\Admin\AuditEntryResource;
use App\Http\Resources\Admin\AuditSubjects;
use App\Models\AuditSensitive;
use App\Support\CsvReport;
use App\Support\DhakaDate;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin → Settings → Staff & roles → Activity log (Figma B · 39 `241:51276`): the immutable
 * `audit_sensitive` trail — price and cost changes, refunds, roles, credentials, riders and
 * report resends — newest first. Reading needs audit.view; the CSV export also needs
 * reports.export, like every other export. Read-only: there is no way to edit or delete a row.
 */
final class AuditLogController extends ApiController
{
    public const int PER_PAGE = 50;

    /** Rows per label-resolving batch in the export. */
    private const int EXPORT_CHUNK = 500;

    /** `GET /audit` — cursor-paginated `{ data, links, meta }`, newest first; needs audit.view. */
    #[QueryParameter('filter[user_id]', 'Changes made by one staff member.', type: 'integer')]
    #[QueryParameter('filter[area]', 'One area of the admin.', type: "'catalog'|'refunds'|'staff'|'integrations'|'fulfilment'|'reports'")]
    #[QueryParameter('filter[action]', 'One audited action (App\Models\Enums\AuditAction).', type: 'string')]
    #[QueryParameter('filter[from]', 'First Dhaka calendar date, inclusive (YYYY-MM-DD).', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Last Dhaka calendar date, inclusive (YYYY-MM-DD).', type: 'string', format: 'date')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter, a sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditSensitive::class);

        $page = $this->query(AuditFilters::from($request))
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        $subjects = AuditSubjects::resolve($page->items());

        return AuditEntryResource::collection($page->through($subjects->entry(...)));
    }

    /** `GET /audit/export` — the same rows and filters as CSV (masked IP, short device). */
    #[QueryParameter('filter[user_id]', 'As on GET /audit.', type: 'integer')]
    #[QueryParameter('filter[area]', 'As on GET /audit.', type: 'string')]
    #[QueryParameter('filter[action]', 'As on GET /audit.', type: 'string')]
    #[QueryParameter('filter[from]', 'As on GET /audit.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'As on GET /audit.', type: 'string', format: 'date')]
    #[OpenApiResponse(400, 'Unlisted filter, a sort, or an invalid filter value (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing audit.view or reports.export (`forbidden`)')]
    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('export', AuditSensitive::class);

        $filters = AuditFilters::from($request);
        $query = $this->query($filters);

        $rows = (static function () use ($query): iterable {
            foreach ($query->lazyByIdDesc(self::EXPORT_CHUNK)->chunk(self::EXPORT_CHUNK) as $chunk) {
                $subjects = AuditSubjects::resolve($chunk);
                foreach ($chunk as $audit) {
                    yield self::csvRow($subjects->entry($audit));
                }
            }
        })();

        return CsvReport::download(
            'activity-log-'.($filters->from ?? 'start').'-to-'.($filters->to ?? DhakaDate::today()).'.csv',
            ['id', 'time_dhaka', 'person', 'action', 'kind', 'area', 'subject_type', 'subject', 'changes', 'ip', 'device'],
            $rows,
        );
    }

    /** @return Builder<AuditSensitive> */
    private function query(AuditFilters $filters): Builder
    {
        $query = AuditSensitive::query()->with('user:id,name');
        $filters->apply($query);

        return $query->orderByDesc('id');
    }

    /** @return list<string|int|null> */
    private static function csvRow(AuditEntry $entry): array
    {
        $audit = $entry->audit;
        $changes = array_map(
            static fn (array $c): string => $c['field'].': '.($c['before'] === null ? '' : $c['before'].' → ').($c['after'] ?? '—'),
            $entry->changes(),
        );

        return [
            $audit->id,
            DhakaDate::local($audit->created_at)->format('Y-m-d H:i'),
            $audit->user->name ?? 'System',
            $audit->action->value,
            $entry->kind(),
            $entry->area(),
            $entry->subject['type'] ?? null,
            $entry->subject === null ? null : ($entry->subject['label'] ?? '#'.$entry->subject['id']),
            implode('; ', $changes),
            $entry->maskedIp(),
            $entry->device(),
        ];
    }
}
