<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Domain\Inbox\Enums\ConversationStatus;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Support\MetaSignature;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Hand-authored Meta webhook fixtures (tests/Feature/Inbox/fixtures), written from the shapes
 * on Meta's published docs — no WhatsApp Business account or Facebook Page exists, so none
 * was captured from a live account.
 */
final class InboxFixtures
{
    public const string WA_SECRET = 'wa-app-secret-TEST-0001';

    public const string WA_VERIFY = 'wa-verify-TEST-0001';

    public const string FB_SECRET = 'fb-app-secret-TEST-0002';

    public const string FB_VERIFY = 'fb-verify-TEST-0002';

    public static function configure(): void
    {
        config([
            'inbox.whatsapp.driver' => 'log',
            'inbox.whatsapp.app_secret' => self::WA_SECRET,
            'inbox.whatsapp.verify_token' => self::WA_VERIFY,
            'inbox.whatsapp.phone_number_id' => null,
            'inbox.whatsapp.access_token' => null,
            'inbox.messenger.driver' => 'log',
            'inbox.messenger.app_secret' => self::FB_SECRET,
            'inbox.messenger.verify_token' => self::FB_VERIFY,
            'inbox.messenger.page_id' => null,
            'inbox.messenger.page_access_token' => null,
        ]);
    }

    /** @param  array<string, string>  $replace */
    public static function body(string $name, array $replace = []): string
    {
        $raw = (string) file_get_contents(__DIR__.'/fixtures/'.$name.'.json');

        return strtr($raw, $replace);
    }

    public static function post(TestCase $test, string $channel, string $body, ?string $signature = 'valid'): TestResponse
    {
        $secret = $channel === 'whatsapp' ? self::WA_SECRET : self::FB_SECRET;
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($signature === 'valid') {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = MetaSignature::sign($body, $secret);
        } elseif ($signature !== null) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = $signature;
        }

        return $test->call('POST', '/api/v1/webhooks/inbox/'.$channel, [], [], [], $headers, $body);
    }

    /** @param  array<string, mixed>  $attributes */
    public static function conversation(array $attributes = []): Conversation
    {
        return Conversation::query()->create([
            'channel' => InboxChannel::WhatsApp,
            'external_thread_id' => '88017'.random_int(10_000_000, 99_999_999),
            'contact_name' => 'Nusrat Jahan',
            'contact_phone' => '+8801841523907',
            'status' => ConversationStatus::Open,
            'last_message_at' => now()->subMinutes(2),
            'last_inbound_at' => now()->subMinutes(2),
            'last_message_preview' => 'আমার পার্সেল কোথায়?',
            'last_message_direction' => 'in',
            'needs_human' => true,
            'unread_count' => 1,
            ...$attributes,
        ]);
    }
}
