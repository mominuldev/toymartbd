<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Catalog\Import\Models\ProductImport;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Bulk product imports (P4-CAT-10): uploading, previewing, committing and reading the
 * error report all need catalog.import. Each row is then authorised again as the
 * product endpoints authorise it (catalog.create / catalog.update, update_price,
 * view_cost, inventory.adjust for opening stock), so the import grants nothing the
 * product form would not.
 */
final class ProductImportPolicy extends Policy
{
    public function create(User $user): bool
    {
        return $this->allows($user, Permission::CatalogImport);
    }

    public function view(User $user, ProductImport $import): bool
    {
        return $this->allows($user, Permission::CatalogImport);
    }

    public function commit(User $user, ProductImport $import): bool
    {
        return $this->allows($user, Permission::CatalogImport);
    }
}
