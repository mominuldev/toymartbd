<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\User;

/**
 * One activity-log row ready to show: the stored audit plus its subject, resolved in batch by
 * AuditSubjects. Everything a reader sees is derived here, server-side — the area, a sentence
 * `kind`, the before → after changes, a masked IP and a short device string. The raw IP and
 * User-Agent never leave this class.
 */
final readonly class AuditEntry
{
    /** @param  array{type: string, id: int|null, label: string|null}|null  $subject */
    public function __construct(public AuditSensitive $audit, public ?array $subject) {}

    public function staffSubject(): bool
    {
        return $this->audit->model_type === (new User)->getMorphClass();
    }

    public function area(): string
    {
        return $this->audit->action->area($this->staffSubject());
    }

    /** `scope` from the payload: which settings a subject-less credential change touched. */
    public function scope(): ?string
    {
        $scope = $this->audit->after['scope'] ?? $this->audit->before['scope'] ?? null;

        return is_string($scope) ? $scope : null;
    }

    /**
     * What happened, finer than the action: the screen has one sentence per kind
     * (`audit.kind.*`). A role_assigned row is a new staff account, a reactivation or a
     * role change depending on its payload.
     */
    public function kind(): string
    {
        $after = $this->audit->after ?? [];

        return match ($this->audit->action) {
            AuditAction::PriceChanged => 'price_changed',
            AuditAction::CostChanged => 'cost_changed',
            AuditAction::RefundCreated => 'refund_created',
            AuditAction::RefundApproved => 'refund_approved',
            AuditAction::RoleAssigned => ($after['created'] ?? false) === true
                ? 'staff_added'
                : (($after['is_active'] ?? null) === true ? 'staff_reactivated' : 'role_changed'),
            AuditAction::RoleRevoked => 'staff_deactivated',
            AuditAction::RolePermissionsChanged => 'permissions_changed',
            AuditAction::ShipmentRiderRecorded => 'rider_recorded',
            AuditAction::ScheduledReportResent => 'report_resent',
            AuditAction::CapitalEntryRecorded => ($after['kind'] ?? null) === 'drawing' ? 'capital_drawing_recorded' : 'capital_contribution_recorded',
            AuditAction::CapitalEntryReversed => 'capital_entry_reversed',
            AuditAction::CapitalHolderSaved => ($after['created'] ?? false) === true ? 'capital_holder_added' : 'capital_holder_changed',
            AuditAction::CredentialChanged => match ($this->scope()) {
                'staff_password' => 'password_reset',
                'two_factor' => match ($after['two_factor'] ?? null) {
                    'enabled' => 'two_factor_enabled',
                    'disabled' => 'two_factor_disabled',
                    'reset_by_owner' => 'two_factor_reset',
                    'recovery_code_used' => 'two_factor_recovery_used',
                    'recovery_codes_regenerated' => 'two_factor_codes_regenerated',
                    default => 'two_factor_changed',
                },
                'two_factor_policy' => ($after['required'] ?? null) === true ? 'two_factor_required' : 'two_factor_optional',
                'messaging' => 'messaging_settings',
                'tracking' => 'tracking_settings',
                'inbox_connection_test' => 'connection_tested',
                default => $this->subject !== null && $this->subject['type'] === 'courier' ? 'courier_credentials' : 'credentials_changed',
            },
        };
    }

    /**
     * `before` / `after` as a list of field changes, `scope` dropped (it names the row, it is
     * not a change). A field equal on both sides is context, not a change, and is dropped too.
     * A role's permission edit becomes one `permissions` change: "+added −removed".
     *
     * @return list<array{field: string, before: string|null, after: string|null}>
     */
    public function changes(): array
    {
        $before = $this->audit->before ?? [];
        $after = $this->audit->after ?? [];

        if ($this->audit->action === AuditAction::RolePermissionsChanged) {
            $parts = [
                ...array_map(static fn (mixed $p): string => '+'.self::text($p), self::listOf($after['added'] ?? [])),
                ...array_map(static fn (mixed $p): string => '−'.self::text($p), self::listOf($before['removed'] ?? [])),
            ];

            return [['field' => 'permissions', 'before' => null, 'after' => implode(' ', $parts)]];
        }

        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            $field = (string) $field;
            if ($field === 'scope') {
                continue;
            }
            $from = array_key_exists($field, $before) ? self::text($before[$field]) : null;
            $to = array_key_exists($field, $after) ? self::text($after[$field]) : null;
            if (array_key_exists($field, $before) && array_key_exists($field, $after) && $from === $to) {
                continue;
            }
            $changes[] = ['field' => $field, 'before' => $from, 'after' => $to];
        }

        return $changes;
    }

    /** IPv4 keeps its first two octets (`103.108.xx.xx`); IPv6 its first two groups. */
    public function maskedIp(): ?string
    {
        return self::maskIp($this->audit->ip);
    }

    /** "Chrome · macOS" from the stored User-Agent; null when neither part is recognised. */
    public function device(): ?string
    {
        return self::deviceOf($this->audit->user_agent);
    }

    public static function maskIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $octets = explode('.', $ip);

            return $octets[0].'.'.$octets[1].'.xx.xx';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);
            $groups = $packed === false ? [] : str_split(bin2hex($packed), 4);

            return count($groups) === 8 ? ltrim($groups[0], '0').':'.ltrim($groups[1], '0').':xxxx::' : null;
        }

        return null;
    }

    public static function deviceOf(?string $agent): ?string
    {
        if ($agent === null || $agent === '') {
            return null;
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'SamsungBrowser/') => 'Samsung Internet',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') && str_contains($agent, 'Version/') => 'Safari',
            default => null,
        };
        $os = match (true) {
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'CrOS') => 'ChromeOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        $parts = array_values(array_filter([$browser, $os], static fn (?string $p): bool => $p !== null));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private static function text(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => implode(', ', array_map(static fn (mixed $v): string => (string) self::text($v), $value)),
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    /** @return list<mixed> */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
