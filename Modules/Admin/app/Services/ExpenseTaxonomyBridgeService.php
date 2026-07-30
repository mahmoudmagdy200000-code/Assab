<?php

namespace Modules\Admin\Services;

use Modules\Expense\Models\Category;

/**
 * Write-through from a brand-level catalog upload (UploadController) into the
 * mobile Expense taxonomy (`categories`), so the app's category pickers are not
 * empty (BUG-9 / FR-EXP-1 «تصنيف واحد، وجهتين»).
 *
 * Mobile read paths this satisfies (ExpenseHelperService, GET
 * .../expenses/categories/parent-categories?type=…):
 *  - the «المصروفات» tab lists categories where type = 'expense';
 *  - the «الأصناف/المشتريات» tab lists categories where type = 'purchase'.
 *
 * Type is derived from the upload kind — the sheet carries no expense/purchase
 * flag: raw-materials are things the brand BUYS → 'purchase'; the sales-items
 * catalog feeds the expense taxonomy → 'expense' (FR-EXP-1: "expense items come
 * from the sales-items taxonomy"). A one-line change here flips that mapping.
 *
 * CAUTION: `categories` is a legacy GLOBAL table — it has no brand/company
 * column, and the mobile pickers read it unscoped. So this is create-only and
 * de-duplicated by (name, type): brands converge on one shared taxonomy (which
 * matches the meeting's "one taxonomy" framing) rather than each minting its
 * own rows. True per-brand isolation would require adding a tenant column to
 * `categories` AND scoping the mobile read — out of scope here. Runs inside the
 * caller's DB transaction (no facades, plain model writes).
 */
class ExpenseTaxonomyBridgeService
{
    /** categories.name is varchar(100). */
    private const NAME_MAX = 100;

    /**
     * Ensure the mobile Expense taxonomy for one uploaded catalog row exists.
     * «الفئة» is the PARENT category and the optional «اسم الفئة» is its
     * SUB-category (parent_id child) — the mobile pickers are hierarchical
     * (parent-categories → children, exactly like CategorySeeder), so a flat
     * row here rendered wrong in the app. No-op for a blank category cell.
     *
     * @param  string  $uploadType  'sales-items' | 'raw-materials'
     */
    public function syncCategoryFor(string $uploadType, ?string $categoryName, ?string $subCategoryName = null): void
    {
        $name = trim((string) $categoryName);
        if ($name === '') {
            return;
        }

        $type = $uploadType === 'raw-materials' ? 'purchase' : 'expense';

        $parent = Category::firstOrCreate(
            [
                'name' => mb_substr($name, 0, self::NAME_MAX),
                'type' => $type,
                'parent_id' => null,
            ],
            ['is_active' => true],
        );

        $subName = trim((string) $subCategoryName);
        if ($subName === '') {
            return;
        }

        Category::firstOrCreate(
            [
                'name' => mb_substr($subName, 0, self::NAME_MAX),
                'type' => $type,
                'parent_id' => $parent->id,
            ],
            ['is_active' => true],
        );
    }
}
