<?php

declare(strict_types=1);

use App\Domain\Ai\DTOs\AiPrompt;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\Models\AiRequest;
use App\Domain\Ai\Services\AiClient;
use App\Domain\Ai\Services\AiDrivers;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;

beforeEach(function (): void {
    $this->fake = AiDrivers::fake();
});

function connectAnthropic(): void
{
    Setting::put('ai.key.anthropic', 'sk-ant-test-0000000000000000', SettingGroup::Ai, true);
}

it('refuses with not_configured when the feature’s provider has no key, without logging spend', function (): void {
    expect(fn () => app(AiClient::class)->run(AiFeature::Advisor, new AiPrompt('s', 'u'), null))
        ->toThrow(fn (AiException $e) => expect($e->reason)->toBe(AiException::NOT_CONFIGURED));
    expect($this->fake->sent)->toBe([]);
});

it('redacts the user turn before the provider sees it and logs tokens and cost', function (): void {
    connectAnthropic();
    $this->fake->respond('Hello');

    $result = app(AiClient::class)->run(AiFeature::InboxReply, new AiPrompt('system', 'Customer 01711000222 rafi@example.com asks about TMB-26-00126'), $uid = App\Models\User::factory()->create()->id);

    expect($result->text)->toBe('Hello')
        ->and($this->fake->sent[0]['prompt']->user)->toBe('Customer [removed] [removed] asks about TMB-26-00126')
        ->and($this->fake->sent[0]['model'])->toBe('claude-opus-5-5');
    $row = AiRequest::query()->sole();
    expect($row->status)->toBe('ok')->and($row->feature)->toBe('inbox_reply')->and($row->user_id)->toBe($uid)
        ->and($row->input_tokens)->toBe(1000)->and((string) $row->cost_usd)->toBe('0.008000');
});

it('stops at the monthly cap and logs the blocked call', function (): void {
    connectAnthropic();
    Setting::put('ai.cap_usd', '0.01', SettingGroup::Ai);
    AiRequest::query()->create(['feature' => 'advisor', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'cost_usd' => '0.010000']);

    expect(fn () => app(AiClient::class)->run(AiFeature::Advisor, new AiPrompt('s', 'u'), null))
        ->toThrow(fn (AiException $e) => expect($e->reason)->toBe(AiException::CAP_REACHED));
    expect(AiRequest::query()->where('status', 'blocked')->count())->toBe(1)->and($this->fake->sent)->toBe([]);
});

it('does not count last month toward the cap (Dhaka month)', function (): void {
    connectAnthropic();
    Setting::put('ai.cap_usd', '0.01', SettingGroup::Ai);
    $this->travelTo(now('Asia/Dhaka')->startOfMonth()->subMinute());
    AiRequest::query()->create(['feature' => 'advisor', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'cost_usd' => '5.000000']);
    $this->travelBack();

    expect(app(AiClient::class)->run(AiFeature::Advisor, new AiPrompt('s', 'u'), null)->text)->toBe('OK');
});

it('logs a failed call with its reason and rethrows', function (): void {
    connectAnthropic();
    $this->fake->respond(new AiException(AiException::REFUSED));

    expect(fn () => app(AiClient::class)->run(AiFeature::ProductCopy, new AiPrompt('s', 'u'), null))->toThrow(AiException::class);
    expect(AiRequest::query()->sole()->only(['status', 'reason']))->toBe(['status' => 'failed', 'reason' => 'refused']);
});

it('keeps the usage log append-only', function (): void {
    $row = AiRequest::query()->create(['feature' => 'advisor', 'provider' => 'anthropic', 'model' => 'm', 'status' => 'ok']);

    expect(fn () => $row->update(['status' => 'failed']))->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class);
});

it('routes a feature to the provider and model saved for it', function (): void {
    Setting::put('ai.key.gemini', 'gemini-test-key-0000000000', SettingGroup::Ai, true);
    Setting::put('ai.features', ['restock_note' => ['provider' => 'gemini', 'model' => 'gemini-3.5-flash-lite']], SettingGroup::Ai);

    app(AiClient::class)->run(AiFeature::RestockNote, new AiPrompt('s', 'u'), null);

    expect($this->fake->sent[0]['model'])->toBe('gemini-3.5-flash-lite')
        ->and(AiRequest::query()->sole()->provider)->toBe('gemini');
});
