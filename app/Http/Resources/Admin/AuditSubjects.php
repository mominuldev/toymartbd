<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Payment\Models\Refund;
use App\Domain\Reporting\Models\ReportScheduleRun;
use App\Models\AuditSensitive;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Names what each audit row is about, for a whole page at once: one query per subject type,
 * never one per row (lazy loading is off in tests). A subject deleted since keeps its type and
 * id with a null label; a row with no model (messaging or tracking settings) has no subject.
 *
 * Labels are names and numbers only — a variant's product and option, a person's name, an
 * order number — never a phone, an email address or an amount.
 */
final class AuditSubjects
{
    /** model_type → the short `type` the API shows. */
    private const array TYPES = [
        ProductVariant::class => 'variant',
        User::class => 'user',
        Role::class => 'role',
        Refund::class => 'refund',
        Shipment::class => 'shipment',
        CourierProvider::class => 'courier',
        ReportScheduleRun::class => 'report_run',
    ];

    /** @var array<string, array<int, string>> type → id → label */
    private array $labels = [];

    /** @param  iterable<AuditSensitive>  $rows */
    public static function resolve(iterable $rows): self
    {
        $ids = [];
        foreach ($rows as $row) {
            $type = self::typeOf($row->model_type);
            if ($type !== null && $row->model_id !== null) {
                $ids[$type][$row->model_id] = $row->model_id;
            }
        }

        $self = new self;
        foreach ($ids as $type => $set) {
            $self->labels[$type] = $self->load($type, array_values($set));
        }

        return $self;
    }

    public function entry(AuditSensitive $row): AuditEntry
    {
        return new AuditEntry($row, $this->subject($row));
    }

    /** @return array{type: string, id: int|null, label: string|null}|null */
    private function subject(AuditSensitive $row): ?array
    {
        if ($row->model_type === null) {
            return null;
        }
        $type = self::typeOf($row->model_type) ?? 'other';
        $id = $row->model_id;

        return ['type' => $type, 'id' => $id, 'label' => $id === null ? null : ($this->labels[$type][$id] ?? null)];
    }

    private static function typeOf(?string $modelType): ?string
    {
        if ($modelType === null) {
            return null;
        }
        if (isset(self::TYPES[$modelType])) {
            return self::TYPES[$modelType];
        }
        // A morph-map alias, should one be registered later.
        foreach (self::TYPES as $class => $type) {
            if ((new $class)->getMorphClass() === $modelType) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function load(string $type, array $ids): array
    {
        $labels = [];

        switch ($type) {
            case 'variant':
                foreach (ProductVariant::query()->with('product:id,name')->whereIn('id', $ids)->get(['id', 'product_id', 'sku', 'name']) as $variant) {
                    $option = $variant->name ?? $variant->sku;
                    $labels[$variant->id] = $variant->product->name.' / '.$option;
                }
                break;
            case 'user':
                $labels = User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
                break;
            case 'role':
                // The role key; the screen shows its translated name (`role.*`).
                $labels = Role::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
                break;
            case 'refund':
                // No refund number exists; a refund is known by its order.
                $labels = DB::table('refunds')->join('orders', 'orders.id', '=', 'refunds.order_id')
                    ->whereIn('refunds.id', $ids)->pluck('orders.number', 'refunds.id')->all();
                break;
            case 'shipment':
                $labels = Shipment::query()->whereIn('id', $ids)->pluck('number', 'id')->all();
                break;
            case 'courier':
                $labels = CourierProvider::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
                break;
            case 'report_run':
                $labels = DB::table('report_schedule_runs')->join('report_schedules', 'report_schedules.id', '=', 'report_schedule_runs.report_schedule_id')
                    ->whereIn('report_schedule_runs.id', $ids)->pluck('report_schedules.name', 'report_schedule_runs.id')->all();
                break;
        }

        /** @var array<int, string> */
        return array_map(static fn (mixed $label): string => (string) $label, $labels);
    }
}
