<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Ai;

use App\Domain\Ai\Actions\ForgetAiProviderKey;
use App\Domain\Ai\Actions\RecheckAiProvider;
use App\Domain\Ai\Actions\SaveAiProviderKey;
use App\Domain\Ai\Actions\UpdateAiSettings;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Enums\AiProvider;
use App\Domain\Ai\Models\AiRequest;
use App\Domain\Ai\Services\AiClient;
use App\Domain\Ai\Services\AiSettings;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Ai\SaveAiKeyRequest;
use App\Http\Requests\Admin\Ai\UpdateAiSettingsRequest;
use App\Http\Resources\Admin\Ai\AiSettingsResource;
use App\Models\Enums\Permission;
use Illuminate\Http\Request;

/**
 * Integrations › AI assistant. Read with settings.view; routing, cap and sharing with
 * settings.update; keys with settings.manage_integrations.
 */
final class AiSettingsController extends ApiController
{
    /** `GET /settings/ai` */
    public function show(Request $request, AiSettings $settings): AiSettingsResource
    {
        $this->authorize(Permission::SettingsView->value);

        return self::view($request, $settings);
    }

    /** `PUT /settings/ai` */
    public function update(UpdateAiSettingsRequest $request, UpdateAiSettings $update, AiSettings $settings): AiSettingsResource
    {
        $update->handle($request->features(), $request->string('cap_usd')->toString(), $request->share(), self::uid($request));

        return self::view($request, $settings);
    }

    /** `PUT /settings/ai/providers/{provider}` — check the key, then save it. 503 `ai-unavailable` when the provider rejects it. */
    public function saveKey(SaveAiKeyRequest $request, string $provider, SaveAiProviderKey $save, AiSettings $settings): AiSettingsResource
    {
        $save->handle(self::provider($provider), $request->apiKey(), self::uid($request));

        return self::view($request, $settings);
    }

    /** `DELETE /settings/ai/providers/{provider}` */
    public function forgetKey(Request $request, string $provider, ForgetAiProviderKey $forget, AiSettings $settings): AiSettingsResource
    {
        $this->authorize(Permission::SettingsManageIntegrations->value);
        $forget->handle(self::provider($provider), self::uid($request));

        return self::view($request, $settings);
    }

    /** `POST /settings/ai/providers/{provider}/check` */
    public function check(Request $request, string $provider, RecheckAiProvider $recheck, AiSettings $settings): AiSettingsResource
    {
        $this->authorize(Permission::SettingsManageIntegrations->value);
        $recheck->handle(self::provider($provider), self::uid($request));

        return self::view($request, $settings);
    }

    private static function provider(string $value): AiProvider
    {
        return AiProvider::tryFrom($value) ?? abort(404);
    }

    private static function uid(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }

    private static function view(Request $request, AiSettings $settings): AiSettingsResource
    {
        $providers = [];
        foreach (AiProvider::cases() as $p) {
            $meta = $settings->meta($p);
            $providers[] = [
                'provider' => $p->value,
                'label' => $p->label(),
                'connected' => $settings->connected($p),
                'last4' => $meta['last4'],
                'checked_at' => $meta['checked_at'],
                'check_ok' => $meta['ok'],
                'default_model' => $p->defaultModel(),
                'models' => array_map(static fn (string $id, array $m): array => ['id' => $id, 'label' => $m[0], 'input_usd_per_million' => $m[1], 'output_usd_per_million' => $m[2]], array_keys($p->models()), $p->models()),
            ];
        }
        $features = [];
        foreach (AiFeature::cases() as $f) {
            ['provider' => $p, 'model' => $m] = $settings->route($f);
            $features[] = ['feature' => $f->value, 'provider' => $p->value, 'model' => $m, 'connected' => $settings->connected($p)];
        }

        $since = AiClient::monthStart();
        $rows = AiRequest::query()->where('created_at', '>=', $since)
            ->selectRaw('feature, status, COUNT(*) AS n, SUM(cost_usd) AS cost')->groupBy('feature', 'status')->get();
        $byFeature = array_fill_keys(array_column(AiFeature::cases(), 'value'), '0.00');
        $requests = 0;
        $failed = 0;
        $total = '0';
        foreach ($rows as $row) {
            $n = (int) $row->getAttribute('n');
            $cost = number_format((float) $row->getAttribute('cost'), 6, '.', '');
            $feature = (string) $row->getAttribute('feature');
            $requests += $n;
            $failed += $row->getAttribute('status') === AiRequest::OK ? 0 : $n;
            $total = bcadd($total, $cost, 6);
            $byFeature[$feature] = number_format((float) bcadd($byFeature[$feature] ?? '0', $cost, 6), 2, '.', '');
        }
        $user = $request->user();

        return new AiSettingsResource([
            'providers' => $providers,
            'features' => $features,
            'cap_usd' => $settings->cap(),
            'share' => $settings->share(),
            'usage' => ['spent_usd' => number_format((float) $total, 2, '.', ''), 'requests' => $requests, 'failed' => $failed, 'by_feature' => $byFeature, 'since' => $since->toIso8601String()],
            'can' => ['update' => (bool) $user?->can(Permission::SettingsUpdate->value), 'manage_keys' => (bool) $user?->can(Permission::SettingsManageIntegrations->value)],
        ]);
    }
}
