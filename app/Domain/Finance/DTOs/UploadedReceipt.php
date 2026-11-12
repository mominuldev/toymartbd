<?php

declare(strict_types=1);

namespace App\Domain\Finance\DTOs;

/** A receipt file handed to an expense Action: the temp path and the client's file name. */
final readonly class UploadedReceipt
{
    public function __construct(public string $path, public string $clientName) {}
}
