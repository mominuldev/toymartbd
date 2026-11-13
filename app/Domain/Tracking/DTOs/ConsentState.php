<?php

declare(strict_types=1);

namespace App\Domain\Tracking\DTOs;

/**
 * The visitor's consent at the moment the event happened.
 *
 * @param  string  $mode  'opt_in' | 'notice'
 */
final readonly class ConsentState
{
    public function __construct(
        public bool $analytics,
        public bool $ads,
        public string $mode,
        public ?string $policyVersion = null,
    ) {}
}
