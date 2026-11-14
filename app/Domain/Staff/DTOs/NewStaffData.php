<?php

declare(strict_types=1);

namespace App\Domain\Staff\DTOs;

use App\Models\Enums\Role;
use SensitiveParameter;

/** A staff account to create. `password` is the temporary password given in person. */
final readonly class NewStaffData
{
    public function __construct(
        public string $name,
        public string $email,
        public Role $role,
        #[SensitiveParameter] public string $password,
    ) {}
}
