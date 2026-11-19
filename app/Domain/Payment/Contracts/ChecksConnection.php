<?php

declare(strict_types=1);

namespace App\Domain\Payment\Contracts;

/**
 * A gateway driver that can prove its keys work for the CURRENT mode (sandbox or live)
 * without taking money — backs Integrations › Payments › Check connection. Never inside a
 * DB transaction (rule 4); never logs or returns a credential (rule 20).
 *
 * Result codes: `ok` · `not_configured` (keys missing for this mode) · `credentials_rejected`
 * (the provider answered and refused the keys) · `unreachable` (timeout / 5xx / bad body) ·
 * `sandbox_only` (the driver can only check safely in sandbox mode; nothing was sent).
 */
interface ChecksConnection
{
    /** @return array{ok: bool, code: 'ok'|'not_configured'|'credentials_rejected'|'unreachable'|'sandbox_only'} */
    public function checkConnection(): array;
}
