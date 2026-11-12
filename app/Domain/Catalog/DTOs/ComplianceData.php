<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

use DateTimeInterface;

/**
 * BSTI certification as the product document carries it. The certificate PDF is not
 * part of the document; it is uploaded on its own (AttachComplianceCertificate).
 */
final readonly class ComplianceData
{
    public function __construct(
        public ?string $bstiCertificateNo = null,
        public ?DateTimeInterface $bstiExpiresOn = null,
    ) {}
}
