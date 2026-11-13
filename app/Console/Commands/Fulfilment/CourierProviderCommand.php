<?php

declare(strict_types=1);

namespace App\Console\Commands\Fulfilment;

use App\Domain\Fulfilment\Models\CourierProvider;
use Illuminate\Console\Command;

/**
 * Show or switch a courier provider's `verified` flag (rule 33a). Production booking through
 * a provider is refused until it is verified, and verifying is an owner decision taken only
 * after a live merchant account has booked, tracked and cancelled a real test parcel end to
 * end (docs/api/couriers.md). Deliberately a console step, not an admin button, so it cannot
 * be flipped by accident. Never prints a credential.
 */
final class CourierProviderCommand extends Command
{
    protected $signature = 'toymart:couriers:provider {code : pathao | steadfast}
        {--verify : Mark the provider verified (allows production booking)}
        {--unverify : Mark the provider unverified (blocks production booking)}';

    protected $description = 'Show or change a courier provider\'s verified flag';

    public function handle(): int
    {
        $row = CourierProvider::query()->where('code', (string) $this->argument('code'))->first();

        if (! $row instanceof CourierProvider) {
            $this->error('Unknown courier provider.');

            return self::FAILURE;
        }

        if ($this->option('verify') && $this->option('unverify')) {
            $this->error('Choose --verify or --unverify, not both.');

            return self::INVALID;
        }

        if ($this->option('verify') || $this->option('unverify')) {
            $row->verified = (bool) $this->option('verify');
            $row->save();
        }

        $this->table(['provider', 'active', 'default', 'verified', 'credentials saved'], [[
            $row->code,
            $row->is_active ? 'yes' : 'no',
            $row->is_default ? 'yes' : 'no',
            $row->verified ? 'yes' : 'no',
            $row->credentials === null || $row->credentials === [] ? 'no' : 'yes',
        ]]);

        return self::SUCCESS;
    }
}
