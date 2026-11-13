<?php

declare(strict_types=1);

use App\Domain\Notification\Actions\ReplaceUnchangedDefaultTemplate;
use App\Domain\Notification\Actions\SyncDefaultTemplates;
use App\Domain\Notification\Enums\NotificationChannel;
use Illuminate\Database\Migrations\Migration;

/**
 * `customer.birthday` is now the reminder to a parent before each CHILD's birthday, with an
 * optional gift code (P8 batch 3). Rows still holding the batch-1 default copy move to the new
 * copy (kept as a version, re-measured; the WhatsApp provider name becomes
 * `child_birthday_reminder`, still not submitted). An edited row is left alone. Missing rows are
 * inserted by the sync. Never shipped to production, so normally only development databases
 * have the old rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $replace = app(ReplaceUnchangedDefaultTemplate::class);
        $replace->handle(
            'customer.birthday',
            NotificationChannel::Sms,
            'Happy birthday, {name}! Warm wishes from everyone at Toy Mart BD. {store_url}',
            'শুভ জন্মদিন, {name}! টয় মার্ট বিডির সবার পক্ষ থেকে শুভেচ্ছা। {store_url}',
        );
        $replace->handle(
            'customer.birthday',
            NotificationChannel::WhatsApp,
            'Happy birthday, {name}! Everyone at Toy Mart BD wishes you a wonderful year ahead.',
            'শুভ জন্মদিন, {name}! টয় মার্ট বিডির সবার পক্ষ থেকে আপনার জন্য রইল নতুন বছরের শুভেচ্ছা।',
        );
        app(SyncDefaultTemplates::class)->handle();
    }

    public function down(): void
    {
        // Templates are data an admin may have edited; leave them.
    }
};
