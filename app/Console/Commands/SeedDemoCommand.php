<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Requests\Admin\Staff\StoreStaffRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Seeds a deployed host with everything a developer machine has (owner request 2026-11-18):
 * run it over SSH on Hostinger. It runs DatabaseSeeder — the same seed as local, with `demo.live_seed` on — reference data, the owner
 * (mominulfed@gmail.com) and six staff demo logins, catalog with images, stock, inbox, and
 * ~60 days of orders, payments, shipments, returns, purchasing, finance, marketing, reviews,
 * content and SEO — once: refused when any order exists. Every live setting is snapshotted to
 * storage/app/backups/ and restored afterwards.
 *
 * The demo logins get the password typed at a hidden prompt, never the factory "password":
 * a known password on an internet-facing host is a back door. Interactive only, like
 * toymart:owner:create, so the password never lands in shell history.
 */
#[Signature('toymart:demo:seed {--no-images : Skip the product photos (if the host\'s GD cannot encode WebP/AVIF)}')]
#[Description('Seed this host with the full local demo data set, once; live settings kept.')]
final class SeedDemoCommand extends Command
{
    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->components->error('Run this in an interactive terminal: the demo password is only read from a hidden prompt.');

            return self::FAILURE;
        }

        $env = (string) app()->environment();
        $this->components->warn("This fills the {$env} database with demo data: fake customers, orders, payments and finance, plus demo logins.");
        if ($env === 'production') {
            $this->components->warn('APP_ENV is production. Demo orders and money would mix with real ones in every report.');
        }
        if (! $this->confirm('Seed the full demo data set here?')) {
            return self::FAILURE;
        }

        $password = (string) $this->secret('Password for every demo login (min '.StoreStaffRequest::PASSWORD_MIN.' characters)');
        if (mb_strlen($password) < StoreStaffRequest::PASSWORD_MIN || $password !== (string) $this->secret('Confirm password')) {
            $this->components->error('Passwords must match and be at least '.StoreStaffRequest::PASSWORD_MIN.' characters.');

            return self::FAILURE;
        }

        config([
            'demo.live_seed' => true,
            'demo.password' => $password,
            'media.demo_seed_images' => ! $this->option('no-images'),
        ]);

        $status = $this->call('db:seed', ['--force' => true]);

        if ($status === self::SUCCESS) {
            $this->components->info('Done. Sign in as mominulfed@gmail.com (owner) or manager@, order_operator@, inventory_staff@, accountant@, content_editor@, viewer@toymart.test with the password you typed.');
            $this->line('Then run: php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache');
        }

        return $status;
    }
}
