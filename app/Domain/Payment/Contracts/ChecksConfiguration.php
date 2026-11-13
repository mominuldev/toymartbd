<?php

declare(strict_types=1);

namespace App\Domain\Payment\Contracts;

/** An online driver that can say whether it has the credentials it needs (P4-PAY-02). */
interface ChecksConfiguration
{
    public function isConfigured(): bool;

    /** True when requests would go to the provider's sandbox, not its live host. */
    public function isSandbox(): bool;
}
