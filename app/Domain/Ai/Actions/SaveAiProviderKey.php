<?php

declare(strict_types=1);

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\Enums\AiProvider;
use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\Services\AiDrivers;
use App\Domain\Ai\Services\AiSettings;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Connects an AI provider: checks the key with a real call FIRST (outside any transaction,
 * rule 4), then stores it encrypted (rule 20) with its last four characters and an
 * `audit_sensitive` row (`credential_changed`, scope `ai_{provider}`, value never recorded).
 * A key the provider rejects is not saved.
 */
final readonly class SaveAiProviderKey
{
    public function __construct(private AiDrivers $drivers) {}

    /** @throws AiException when the provider rejects the key */
    public function handle(AiProvider $provider, string $apiKey, ?int $userId): void
    {
        // 429 = the provider authenticated the key but refused on quota or rate: the key is
        // good, the account is limited. Save it and say so, rather than refuse a working key.
        $limited = false;
        try {
            $this->drivers->for($provider)->check($apiKey, $provider->defaultModel());
        } catch (AiException $e) {
            if ($e->reason !== AiException::RATE_LIMITED) {
                throw $e;
            }
            $limited = true;
        }

        DB::transaction(function () use ($provider, $apiKey, $userId, $limited): void {
            $had = Setting::query()->whereKey(AiSettings::keyName($provider))->lockForUpdate()->exists();
            Setting::put(AiSettings::keyName($provider), $apiKey, AiSettings::group(), true, $userId);
            Setting::put(AiSettings::metaName($provider), ['last4' => mb_substr($apiKey, -4), 'checked_at' => now()->toIso8601String(), 'ok' => true, 'limited' => $limited], AiSettings::group(), false, $userId);
            AuditSensitive::query()->create([
                'user_id' => $userId,
                'action' => AuditAction::CredentialChanged,
                'before' => ['scope' => 'ai_'.$provider->value, 'api_key' => $had ? 'set' : null],
                'after' => ['scope' => 'ai_'.$provider->value, 'api_key' => 'changed'],
            ]);
        });
    }
}
