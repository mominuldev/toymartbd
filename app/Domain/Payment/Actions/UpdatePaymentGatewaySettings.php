<?php

declare(strict_types=1);

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Support\GatewayCredentials;
use App\Domain\Payment\Support\GatewayEnvFallback;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Integrations › Payments: save one online gateway's settings (`PUT /settings/payment-gateways/{code}`,
 * owner request 2026-11-19) — on/off, sandbox or live mode, and either mode's key set.
 *
 * Keys: a value that is absent or blank KEEPS the stored one; a key listed in `$clear` for a
 * mode removes it. Values are encrypted by the model cast and never logged (rule 20): the
 * audit row (`credential_changed`) names WHICH fields changed (`live.store_password`) and the
 * mode / on-off change, never a value.
 *
 * Under the row lock:
 *   - switching to `live` needs a complete live set (env never fills it);
 *   - an active gateway needs a complete set for the mode it ends up in (the sandbox set may
 *     lean on env) — checked when it is switched on, switched mode, or that set is edited.
 *
 * Saving never marks the gateway verified, and switching mode or changing a live key clears it: that stays the owner's CLI step
 * (`toymart:payments:gateway --verify`), and production refuses an unverified gateway.
 */
final readonly class UpdatePaymentGatewaySettings
{
    /**
     * @param  array<string, array<string, string>>  $values  mode => key => new value (blanks already dropped)
     * @param  array<string, list<string>>  $clear  mode => keys to remove
     */
    public function handle(PaymentMethod $method, ?bool $active, ?string $mode, array $values, array $clear, ?int $userId): PaymentProvider
    {
        return DB::transaction(static function () use ($method, $active, $mode, $values, $clear, $userId): PaymentProvider {
            /** @var PaymentProvider $row */
            $row = PaymentProvider::query()->where('code', $method->value)->lockForUpdate()->firstOrFail();
            $allowed = array_column(GatewayCredentials::fields($method), 'key');
            $modes = [GatewayCredentials::SANDBOX, GatewayCredentials::LIVE];

            /** @var array<string, array<string, string>> $sets */
            $sets = [];
            $changed = [];
            foreach ($modes as $m) {
                // Stored only, trimmed; a legacy flat set is read as sandbox and rewritten nested.
                $set = GatewayCredentials::forMode($row, $m === GatewayCredentials::SANDBOX);
                foreach ($clear[$m] ?? [] as $key) {
                    if (in_array($key, $allowed, true) && array_key_exists($key, $set)) {
                        unset($set[$key]);
                        $changed[] = $m.'.'.$key;
                    }
                }
                foreach ($values[$m] ?? [] as $key => $value) {
                    if (in_array($key, $allowed, true) && ($set[$key] ?? null) !== $value) {
                        $set[$key] = $value;
                        $changed[] = $m.'.'.$key;
                    }
                }
                $sets[$m] = $set;
            }

            $fromMode = GatewayCredentials::mode($row->sandbox);
            $toMode = $mode ?? $fromMode;
            $toActive = $active ?? $row->is_active;
            $modeChanged = $toMode !== $fromMode;
            $activeChanged = $toActive !== $row->is_active;

            $errors = [];
            if ($modeChanged && $toMode === GatewayCredentials::LIVE && ! GatewayCredentials::complete($method, $sets[GatewayCredentials::LIVE])) {
                $errors['mode'] = 'Enter every live key before switching to live mode.';
            }
            $setTouched = array_filter($changed, static fn (string $path): bool => str_starts_with($path, $toMode.'.')) !== [];
            if ($errors === [] && $toActive && ($activeChanged || $modeChanged || $setTouched)) {
                $effective = $toMode === GatewayCredentials::SANDBOX
                    ? $sets[$toMode] + self::envFallback($method)
                    : $sets[$toMode];
                if (! GatewayCredentials::complete($method, $effective)) {
                    $field = $activeChanged ? 'is_active' : ($modeChanged ? 'mode' : 'credentials.'.$toMode);
                    $errors[$field] = "Enter every {$toMode} key before switching this gateway on.";
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            if ($changed === [] && ! $modeChanged && ! $activeChanged) {
                return $row;
            }

            $stored = array_filter($sets, static fn (array $set): bool => $set !== []);
            $row->credentials = $stored === [] ? null : $stored;
            $row->sandbox = $toMode === GatewayCredentials::SANDBOX;
            $row->is_active = $toActive;
            // A verification proves one key set in one mode. Changing mode or the live keys
            // means real money would flow through something nobody has tested — re-verify.
            $liveKeysChanged = array_filter($changed, static fn (string $c): bool => str_starts_with($c, GatewayCredentials::LIVE.'.')) !== [];
            $unverified = $row->verified && ($modeChanged || $liveKeysChanged);
            if ($unverified) {
                $row->verified = false;
                $row->verified_at = null;
            }
            $row->save();

            $after = ['gateway' => $row->code, 'changed' => array_values(array_unique($changed))];
            if ($modeChanged) {
                $after['mode'] = ['from' => $fromMode, 'to' => $toMode];
            }
            if ($unverified) {
                $after['verified'] = ['from' => true, 'to' => false];
            }
            if ($activeChanged) {
                $after['is_active'] = ['from' => ! $toActive, 'to' => $toActive];
            }
            AuditSensitive::query()->create([
                'user_id' => $userId,
                'action' => AuditAction::CredentialChanged,
                'model_type' => $row->getMorphClass(),
                'model_id' => $row->id,
                'before' => null,
                // Field names and switches only — a key's value lives in the encrypted column alone.
                'after' => $after,
            ]);

            return $row->refresh();
        });
    }

    /** @return array<string, string> the env values that fill sandbox gaps, blanks dropped */
    private static function envFallback(PaymentMethod $method): array
    {
        return array_filter(
            array_map(static fn (?string $v): string => trim((string) $v), GatewayEnvFallback::for($method)),
            static fn (string $v): bool => $v !== '',
        );
    }
}
