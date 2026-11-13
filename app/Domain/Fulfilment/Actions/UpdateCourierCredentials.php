<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Support\CourierCredentialFields;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use Illuminate\Support\Facades\DB;

/**
 * Replace some of a courier's credentials (`PUT /settings/couriers/{provider}/credentials`).
 * A key that is absent or blank KEEPS the stored value; a key listed in `$clear` removes it.
 * Values are encrypted by the model cast (rule 20) and never logged: the audit row
 * (`credential_changed`) records WHICH keys changed, never their values.
 *
 * Saving credentials does not mark a provider verified: `verified` flips only once a real
 * booking has succeeded through its driver (rule 33a).
 */
final readonly class UpdateCourierCredentials
{
    /**
     * @param  array<string, string>  $values  key => new value (blank already removed)
     * @param  list<string>  $clear
     */
    public function handle(CourierProvider $provider, array $values, array $clear, ?string $webhookSecret, bool $clearWebhookSecret, ?int $userId): CourierProvider
    {
        return DB::transaction(static function () use ($provider, $values, $clear, $webhookSecret, $clearWebhookSecret, $userId): CourierProvider {
            /** @var CourierProvider $locked */
            $locked = CourierProvider::query()->lockForUpdate()->findOrFail($provider->id);
            $allowed = CourierCredentialFields::for($locked);
            $stored = $locked->credentials ?? [];
            $changed = [];

            foreach ($clear as $key) {
                if (in_array($key, $allowed, true) && array_key_exists($key, $stored)) {
                    unset($stored[$key]);
                    $changed[] = $key;
                }
            }
            foreach ($values as $key => $value) {
                if (in_array($key, $allowed, true) && ($stored[$key] ?? null) !== $value) {
                    $stored[$key] = $value;
                    $changed[] = $key;
                }
            }

            if ($clearWebhookSecret && $locked->webhook_secret !== null) {
                $locked->webhook_secret = null;
                $changed[] = 'webhook_secret';
            } elseif ($webhookSecret !== null && $webhookSecret !== $locked->webhook_secret) {
                $locked->webhook_secret = $webhookSecret;
                $changed[] = 'webhook_secret';
            }

            if ($changed === []) {
                return $locked;
            }

            $locked->credentials = $stored === [] ? null : $stored;
            $locked->credentials_updated_at = now();
            $locked->save();

            AuditSensitive::query()->create([
                'user_id' => $userId,
                'action' => AuditAction::CredentialChanged,
                'model_type' => $locked->getMorphClass(),
                'model_id' => $locked->id,
                'before' => null,
                // Key names only — a credential value is never written anywhere but the encrypted column.
                'after' => ['provider' => $locked->code, 'changed' => array_values(array_unique($changed))],
            ]);

            return $locked->refresh();
        });
    }
}
