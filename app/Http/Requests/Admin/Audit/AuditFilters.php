<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Audit;

use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\User;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The activity log's `filter[…]` query against an explicit allowlist (rule 16): an unlisted
 * filter, a `sort`, or a malformed value is a 400 `bad-request`. The log is always newest first.
 *
 * `from` / `to` are Dhaka calendar dates (rule 11), turned into the UTC half-open interval
 * [from 00:00 Dhaka, the day after `to` 00:00 Dhaka) so the WHERE stays on the indexed column.
 */
final readonly class AuditFilters
{
    public const array ALLOWED = ['user_id', 'area', 'action', 'from', 'to'];

    /** The widest window one request may ask for. */
    public const int MAX_DAYS = 366;

    private function __construct(
        public ?int $userId,
        public ?string $area,
        public ?AuditAction $action,
        public ?string $from,
        public ?string $to,
    ) {}

    public static function from(Request $request): self
    {
        if ($request->query->has('sort')) {
            throw new BadRequestHttpException('The activity log is always newest first; sorting is not supported.');
        }

        $filter = $request->query('filter', []);
        if (! is_array($filter)) {
            throw new BadRequestHttpException('`filter` must be an object: filter[name]=value.');
        }
        $unknown = array_diff(array_map(strval(...), array_keys($filter)), self::ALLOWED);
        if ($unknown !== []) {
            throw new BadRequestHttpException('Unknown filter(s): '.implode(', ', $unknown).'. Allowed: '.implode(', ', self::ALLOWED).'.');
        }

        $values = [];
        foreach ($filter as $key => $value) {
            if (! is_string($value)) {
                throw new BadRequestHttpException("filter[{$key}] must be a single value.");
            }
            if (trim($value) !== '') {
                $values[(string) $key] = trim($value);
            }
        }

        $userId = null;
        if (isset($values['user_id'])) {
            if (! ctype_digit($values['user_id']) || (int) $values['user_id'] === 0) {
                throw new BadRequestHttpException('filter[user_id] must be a positive integer id.');
            }
            $userId = (int) $values['user_id'];
        }

        $area = $values['area'] ?? null;
        if ($area !== null && ! in_array($area, AuditAction::AREAS, true)) {
            throw new BadRequestHttpException('filter[area] must be one of: '.implode(', ', AuditAction::AREAS).'.');
        }

        $action = null;
        if (isset($values['action'])) {
            $action = AuditAction::tryFrom($values['action'])
                ?? throw new BadRequestHttpException('filter[action] must be one of: '.implode(', ', AuditAction::values()).'.');
        }

        $from = self::date($values, 'from');
        $to = self::date($values, 'to');
        if ($from !== null && $to !== null) {
            if ($from > $to) {
                throw new BadRequestHttpException('filter[from] must be on or before filter[to].');
            }
            $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to));
            if ($days >= self::MAX_DAYS) {
                throw new BadRequestHttpException('The date range may span at most '.self::MAX_DAYS.' days.');
            }
        }

        return new self($userId, $area, $action, $from, $to);
    }

    /** @param  Builder<AuditSensitive>  $query */
    public function apply(Builder $query): void
    {
        if ($this->userId !== null) {
            $query->where('user_id', $this->userId);
        }
        if ($this->action !== null) {
            $query->where('action', $this->action->value);
        }
        if ($this->area !== null) {
            $this->applyArea($query, $this->area);
        }
        if ($this->from !== null) {
            $query->where('created_at', '>=', DhakaDate::utcBounds($this->from)[0]);
        }
        if ($this->to !== null) {
            $query->where('created_at', '<', DhakaDate::utcBounds($this->to)[1]);
        }
    }

    /**
     * An area is a set of actions, except a credential change, which is a staff matter on a
     * staff account and an integration otherwise (AuditAction::area()).
     *
     * @param  Builder<AuditSensitive>  $query
     */
    private function applyArea(Builder $query, string $area): void
    {
        $plain = array_values(array_filter(
            AuditAction::cases(),
            static fn (AuditAction $a): bool => $a !== AuditAction::CredentialChanged && $a->area() === $area,
        ));
        $staffMorph = (new User)->getMorphClass();

        $query->where(static function (Builder $q) use ($plain, $area, $staffMorph): void {
            $q->whereIn('action', array_map(static fn (AuditAction $a): string => $a->value, $plain));
            if ($area === 'staff') {
                $q->orWhere(static fn (Builder $c) => $c->where('action', AuditAction::CredentialChanged->value)->where('model_type', $staffMorph));
            }
            if ($area === 'integrations') {
                $q->orWhere(static fn (Builder $c) => $c->where('action', AuditAction::CredentialChanged->value)
                    ->where(static fn (Builder $m) => $m->whereNull('model_type')->orWhere('model_type', '!=', $staffMorph)));
            }
        });
    }

    /** @param  array<string, string>  $values */
    private static function date(array $values, string $name): ?string
    {
        $value = $values[$name] ?? null;
        if ($value === null) {
            return null;
        }
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : null;
        if ($parsed === null || $parsed->toDateString() !== $value) {
            throw new BadRequestHttpException("filter[{$name}] must be a date (YYYY-MM-DD, Dhaka).");
        }

        return $value;
    }
}
