<?php

namespace Modules\Expense\Repositories;

use Modules\Expense\Models\Supplier;

/**
 * Repository for Supplier data access.
 * Keeps query logic out of controllers; response shape remains unchanged.
 */
class SupplierRepository
{
    /**
     * Find a supplier by ID (UUID or primary key).
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail(string $id): Supplier
    {
        return Supplier::findOrFail($id);
    }
}
