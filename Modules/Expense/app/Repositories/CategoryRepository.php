<?php

namespace Modules\Expense\Repositories;

use Modules\Expense\Models\Category;

/**
 * Repository for Category data access.
 */
class CategoryRepository
{
    /**
     * Find category by ID with parent and children (for show endpoint).
     */
    public function findWithParentAndChildren(string $id): Category
    {
        return Category::with('parent', 'children')->findOrFail($id);
    }
}
