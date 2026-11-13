<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Contracts;

use App\Domain\Fulfilment\DTOs\FraudLookupResult;
use App\Support\PhoneNumber;

/**
 * A courier driver that can report a phone's delivery history through an OFFICIALLY
 * published endpoint (DATABASE-DESIGN §3.6 `courier_fraud_lookups`, rules 24, 24a, 33b).
 * Only drivers with such an endpoint implement it — today Steadfast. A courier whose
 * history is only reachable by scraping its merchant portal never implements this
 * (rule 24a).
 *
 * Without a merchant account (or while the provider is unverified) the answer is
 * {@see FraudLookupResult::notConnected()} — never a zero, which would read as "this
 * customer has no failed deliveries" (rule 33b). Makes an external call: never call it
 * inside a database transaction (rule 4).
 */
interface FraudLookup
{
    public function fraudLookup(PhoneNumber $phone): FraudLookupResult;
}
