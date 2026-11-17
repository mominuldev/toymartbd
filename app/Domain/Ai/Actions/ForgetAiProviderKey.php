<?php

declare(strict_types=1);

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\Enums\AiProvider;
use App\Domain\Ai\Services\AiSettings;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/** Disconnects an AI provider: deletes its key and meta, audited. Features routed to it then answer "not set up". */
final readonly class ForgetAiProviderKey
{
    public function handle(AiProvider $provider, ?int $userId): void
    {
        DB::transaction(function () use ($provider, $userId): void {
            $deleted = Setting::query()->whereKey([AiSettings::keyName($provider), AiSettings::metaName($provider)])->delete();
            if ($deleted === 0) {
                return;
            }
            AuditSensitive::query()->create([
                'user_id' => $userId,
                'action' => AuditAction::CredentialChanged,
                'before' => ['scope' => 'ai_'.$provider->value, 'api_key' => 'set'],
                'after' => ['scope' => 'ai_'.$provider->value, 'api_key' => 'cleared'],
            ]);
        });
    }
}
