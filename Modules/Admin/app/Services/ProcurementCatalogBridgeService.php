<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\SupplierItem as AsabSupplierItem;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item as PurchaseItem;
use Modules\Purchase\Models\SupplierItem as MobileSupplierItem;
use Modules\Supplier\Models\Supplier as LegacySupplier;

/**
 * Write-through bridge from the purchasing manager's dashboard catalog
 * (asab_supplier_items / asab_suppliers) into the mobile Purchase world, so
 * items he adds appear in the app's supplier / purchasing-officer lists and
 * suppliers he adds are usable by the real order flow (client meeting).
 *
 * Mobile read paths this satisfies (OrderDataService):
 *  - getPurchasingOfficerItems / getSupplierItems paginate branch_item rows of
 *    the requesting branch — so the bridge seeds branch_item for every legacy
 *    branch of the tenant (create-only).
 *  - getDirectSupplierItems / getSupplierItems price via supplier_items rows
 *    (is_available + active supplier) — upserted when the asab item carries a
 *    linked supplier and a price.
 *
 * CAUTION: items / branch_item / supplier_items are tenant-unscoped legacy
 * tables. Create-only like the raw-materials upload bridge
 * (UploadController::importCatalogRow): any code/name collision with a row
 * this bridge didn't create — live or soft-deleted — is skipped, never
 * overwritten. Runs inside the caller's DB transaction (no facades, DI only).
 */
class ProcurementCatalogBridgeService
{
    public function __construct(private readonly IdentityMapService $identity) {}

    /**
     * Upsert the mobile catalog projection of an asab item. Idempotent; used
     * by both storeItem and updateItem. Persists purchase_item_id on the asab
     * row the first time the mobile row is created/restored.
     */
    public function syncItem(AsabSupplierItem $item): void
    {
        $mobile = $this->resolveMobileItem($item);
        if ($mobile === null) {
            return; // live collision with a row we don't own — skip, never clobber
        }

        if ($item->purchase_item_id !== $mobile->id) {
            $item->forceFill(['purchase_item_id' => $mobile->id])->save();
        }

        $mobile->fill([
            'name' => $item->name,
            'unit' => $item->unit,
            'category' => $item->category,
            'is_active' => $item->status !== 'inactive',
        ])->save();

        $this->seedBranchItems($item, $mobile);
        $this->syncPricedSupplierRow($item, $mobile);
    }

    /**
     * destroyItem: never hard-delete mobile rows another flow may reference —
     * soft-delete the catalog item (mobile-world convention, mirrors the
     * upload bridge's withTrashed/restore semantics) and flag priced supplier
     * rows unavailable so they drop out of the available() read scopes.
     */
    public function deactivateItem(AsabSupplierItem $item): void
    {
        if (! $item->purchase_item_id) {
            return;
        }

        $mobile = PurchaseItem::find($item->purchase_item_id);
        if ($mobile !== null) {
            $mobile->fill(['is_active' => false])->save();
            $mobile->delete(); // soft delete
        }

        MobileSupplierItem::where('item_id', $item->purchase_item_id)
            ->whereIn('supplier_id', $this->tenantLegacySupplierIds($item->company_id))
            ->update(['is_available' => false]);

        // getPurchasingOfficerItems joins branch_item to items with no
        // deleted_at/is_active filter, so the bridge's own seeds would keep
        // surfacing the deleted item as a null-named ghost row. Remove the
        // seeds for this tenant's branches (BranchItem is a hard-delete
        // pivot); rows with quantity > 0 hold real stock and are kept.
        BranchItem::where('item_id', $item->purchase_item_id)
            ->whereIn('branch_id', Branch::where('asab_company_id', $item->company_id)->pluck('id'))
            ->where('quantity', 0)
            ->delete();
    }

    /**
     * Provision the login-capable legacy supplier row for a dashboard-created
     * supplier so OrderConsolidationService::send (Supplier::findOrFail) and
     * the supplier_items price rows can reference it. Persists
     * legacy_supplier_id on the asab row.
     *
     * @throws AsabException 422 when the email belongs to another tenant's supplier.
     */
    public function provisionSupplier(AsabSupplier $sup): void
    {
        $email = $sup->contact_email;
        $existing = $email
            ? LegacySupplier::withTrashed()->where('email', $email)->first()
            : null;

        if ($existing !== null) {
            // Multi-tenancy guard (mirrors BrandOwnerProvisioningService): an
            // email already linked from a different company's asab supplier
            // cannot be re-claimed here.
            $claimedElsewhere = AsabSupplier::withoutGlobalScope('tenant')
                ->where('legacy_supplier_id', $existing->id)
                ->where('company_id', '!=', $sup->company_id)
                ->exists();
            if ($claimedElsewhere) {
                throw new AsabException(
                    'EMAIL_CONFLICT',
                    'Email already belongs to a supplier in another company',
                    'البريد الإلكتروني مسجّل مسبقاً لمورد في شركة أخرى',
                    422,
                );
            }

            if ($existing->trashed()) {
                $existing->restore();
                $existing->fill(['is_active' => true])->save();
            }

            $sup->forceFill(['legacy_supplier_id' => $existing->id])->save();
            $this->identity->linkSupplier($sup->id, $existing->id, $sup->company_id, $email);

            return;
        }

        $legacy = LegacySupplier::create([
            'name' => $sup->name,
            'email' => $email,
            'phone' => $this->availablePhone($sup->contact_phone),
            // Bootstrap secret, generated and discarded unread: suppliers.password
            // is nullable, and Hash::check($x, null) TypeErrors on PHP 8.2, which
            // would turn a wrong-password attempt on the public login route into a
            // 500. A real credential is written later, and emailed, only when an
            // admin creates the supplier's login user (SupplierUserProvisioner).
            'password' => Str::password(12),
            'is_active' => $sup->status === 'active',
            'created_by_admin_at' => now(),
        ]);

        $sup->forceFill(['legacy_supplier_id' => $legacy->id])->save();
        $this->identity->linkSupplier($sup->id, $legacy->id, $sup->company_id, $email);
    }

    /** updateSupplier: keep the legacy row's name/phone in sync. */
    public function syncSupplier(AsabSupplier $sup): void
    {
        if (! $sup->legacy_supplier_id) {
            return;
        }

        $legacy = LegacySupplier::find($sup->legacy_supplier_id);
        if ($legacy === null) {
            return;
        }

        $legacy->fill(array_filter([
            'name' => $sup->name,
            'phone' => $this->availablePhone($sup->contact_phone, $legacy->id),
        ], fn ($v) => $v !== null))->save();
    }

    /** toggleSupplier: mobile read paths filter on suppliers.is_active. */
    public function syncSupplierActive(AsabSupplier $sup): void
    {
        if (! $sup->legacy_supplier_id) {
            return;
        }

        LegacySupplier::where('id', $sup->legacy_supplier_id)
            ->update(['is_active' => $sup->status === 'active']);
    }

    /**
     * Create-only resolution into the global items table: reuse the linked
     * row, else match by code (or name when codeless). Any unlinked match —
     * live OR soft-deleted — may be another brand's row (items carries no
     * tenant column), so it is skipped unless our own bridge created it
     * (purchase_item_id linkage, set only at creation time). Stricter than
     * the upload bridge, which restores trashed matches: resurrecting and
     * renaming a foreign brand's deleted row would rewrite its history.
     */
    private function resolveMobileItem(AsabSupplierItem $item): ?PurchaseItem
    {
        if ($item->purchase_item_id) {
            $linked = PurchaseItem::withTrashed()->find($item->purchase_item_id);
            if ($linked !== null) {
                if ($linked->trashed()) {
                    $linked->restore();
                }

                return $linked;
            }
        }

        $code = trim((string) ($item->code ?? ''));
        $existing = PurchaseItem::withTrashed()
            ->where($code !== '' ? 'code' : 'name', $code !== '' ? $code : $item->name)
            ->first();

        if ($existing === null) {
            return PurchaseItem::create([
                'name' => $item->name,
                'code' => $code !== '' ? $code : null,
                'unit' => $item->unit,
                'category' => $item->category,
                'is_active' => true,
            ]);
        }

        if ($existing->trashed() && $this->createdByTenantBridge($existing->id, $item->company_id)) {
            $existing->restore();

            return $existing;
        }

        return null;
    }

    /**
     * Ownership signal for a trashed code/name match: only rows this tenant's
     * bridge created (and thus linked) are safe to restore and rewrite.
     */
    private function createdByTenantBridge(string $mobileItemId, string $companyId): bool
    {
        return AsabSupplierItem::withTrashed()
            ->where('company_id', $companyId)
            ->where('purchase_item_id', $mobileItemId)
            ->exists();
    }

    /**
     * The purchasing-officer and supplier item lists only surface items that
     * exist as branch_item rows of the requesting branch, so seed one per
     * legacy branch of this tenant. Create-only: existing rows keep their
     * branch-set price/quantity.
     */
    private function seedBranchItems(AsabSupplierItem $item, PurchaseItem $mobile): void
    {
        $branchIds = Branch::where('asab_company_id', $item->company_id)->pluck('id');

        foreach ($branchIds as $branchId) {
            BranchItem::firstOrCreate(
                ['branch_id' => $branchId, 'item_id' => $mobile->id],
                ['price' => $this->riyals($item->price), 'quantity' => 0],
            );
        }
    }

    /**
     * Priced row in supplier_items — the table getDirectSupplierItems /
     * getSupplierItems read (legacy branch of the merge). Only written for the
     * tenant's own provisioned legacy supplier, so no cross-brand risk.
     */
    private function syncPricedSupplierRow(AsabSupplierItem $item, PurchaseItem $mobile): void
    {
        $legacySupplierId = $item->supplier_id
            ? AsabSupplier::where('company_id', $item->company_id)
                ->whereKey($item->supplier_id)
                ->value('legacy_supplier_id')
            : null;

        if ($legacySupplierId === null || (int) $item->price <= 0) {
            return;
        }

        MobileSupplierItem::updateOrCreate(
            ['supplier_id' => $legacySupplierId, 'item_id' => $mobile->id],
            [
                'unit_price' => $this->riyals($item->price),
                'is_available' => $item->status !== 'inactive' && $item->available !== false,
                'min_order_quantity' => $item->min_qty,
                'max_order_quantity' => $item->max_qty,
                'delivery_hours' => $item->lead_time_days !== null ? $item->lead_time_days * 24 : null,
            ],
        );
    }

    /** @return string[] legacy supplier ids provisioned by this tenant */
    private function tenantLegacySupplierIds(string $companyId): array
    {
        return AsabSupplier::withoutGlobalScope('tenant')
            ->where('company_id', $companyId)
            ->whereNotNull('legacy_supplier_id')
            ->pluck('legacy_supplier_id')
            ->all();
    }

    /**
     * Keep one phone to one legacy supplier. NOT enforced by the schema — the
     * Supplier module's create-table migration never runs (Expense's creates
     * `suppliers` first, so the later one takes its ALTER branch), leaving phone
     * a plain index. The de-duplication is this bridge's own invariant.
     */
    private function availablePhone(?string $phone, ?string $exceptId = null): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $taken = LegacySupplier::withTrashed()
            ->where('phone', $phone)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        return $taken ? null : $phone;
    }

    /** Dashboard prices are integer halalas; the mobile world stores riyals. */
    private function riyals(?int $halalas): float
    {
        return round(((int) $halalas) / 100, 2);
    }
}
