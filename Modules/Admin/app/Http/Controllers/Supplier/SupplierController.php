<?php

namespace Modules\Admin\Http\Controllers\Supplier;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\SupplierItem;
use Modules\Admin\Services\ExportService;
use Modules\Admin\Services\ProcurementCatalogBridgeService;
use Modules\Admin\Services\PurchasePresenterService;
use Modules\Admin\Support\SupplierOrderStatus;
use Modules\Branch\Models\Branch;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Supplier (المورد; BACKEND_API_SPEC.md §6.6) portal — orders + catalog.
 * Every order query is scoped to the supplier records linked to the logged-in
 * user (asab_suppliers.user_id): a supplier must never see or decide another
 * supplier's orders (zero-trust guardrail).
 */
class SupplierController extends AsabController
{
    public function __construct(
        private readonly ExportService $exports,
        private readonly PurchasePresenterService $purchases,
        private readonly ProcurementCatalogBridgeService $bridge,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $base = $this->ownOrders($request);
            $userId = $request->user()->id;

            $recent = (clone $base)->orderByDesc('operation_date')->limit(8)->get();

            return $this->ok([
                'kpis' => [
                    'newOrders' => (clone $base)->where('status', 'pending')->count(),
                    'acceptedThisMonth' => $this->acceptedThisMonth($base),
                    'totalSalesThisMonth' => $this->salesForMonth($base, now()->month, now()->year),
                    'totalSalesTrendPct' => $this->salesTrendPct($base),
                    'activeItems' => SupplierItem::where('supplier_user_id', $userId)->where('status', 'active')->count(),
                    'totalItems' => SupplierItem::where('supplier_user_id', $userId)->count(),
                ],
                'recentOrders' => $this->presentMany($recent),
            ]);
        });
    }

    public function orders(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $q = $this->ownOrders($request);
            // Filter on the canonical status key: `?status=accepted` matches every
            // accepted-synonym raw status (accepted/confirmed/approved/final-approved),
            // mirroring the export so the SUP-1.3 separate lists never miss a row.
            if ($status = $request->query('status')) {
                $q->whereIn('status', SupplierOrderStatus::synonyms($status));
            }
            $p = $q->orderByDesc('operation_date')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, $this->presentMany(collect($p->items())));
        });
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['deliveryDate' => 'nullable|date', 'note' => 'nullable|string']);
            $op = $this->find($request, $id);
            $this->assertStatusIn($op, SupplierOrderStatus::synonyms('pending'),
                'ORDER_NOT_PENDING', 'Order is not pending', 'لا يمكن قبول طلب ليس في انتظار الرد');
            DB::transaction(function () use ($op, $data) {
                $payload = $op->payload ?? [];
                $payload['supplierResponse'] = ['accepted' => true, 'deliveryDate' => $data['deliveryDate'] ?? null, 'note' => $data['note'] ?? null];
                $op->update(['status' => 'accepted', 'payload' => $payload]);
            });

            return $this->ok($this->presentOne($op->fresh()));
        });
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['reason' => 'required|string|max:500', 'note' => 'nullable|string']);
            $op = $this->find($request, $id);
            $this->assertStatusIn($op, SupplierOrderStatus::synonyms('pending'),
                'ORDER_NOT_PENDING', 'Order is not pending', 'لا يمكن رفض طلب ليس في انتظار الرد');
            DB::transaction(function () use ($op, $data) {
                $payload = $op->payload ?? [];
                $payload['supplierResponse'] = ['accepted' => false, 'reason' => $data['reason'], 'note' => $data['note'] ?? null];
                $op->update(['status' => 'rejected', 'reject_reason' => $data['reason'], 'payload' => $payload]);
            });

            return $this->ok($this->presentOne($op->fresh()));
        });
    }

    public function markDelivered(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['deliveredAt' => 'nullable|date', 'deliveryNote' => 'nullable|string']);
            $op = $this->find($request, $id);
            $this->assertStatusIn($op, SupplierOrderStatus::synonyms('accepted'),
                'ORDER_NOT_ACCEPTED', 'Order is not accepted', 'لا يمكن تسليم طلب غير مقبول');
            DB::transaction(function () use ($op, $data) {
                $payload = $op->payload ?? [];
                $payload['delivery'] = ['deliveredAt' => $data['deliveredAt'] ?? now()->toIso8601String(), 'note' => $data['deliveryNote'] ?? null];
                $op->update(['status' => 'delivered', 'payload' => $payload]);
            });

            return $this->ok($this->presentOne($op->fresh()));
        });
    }

    public function items(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $items = SupplierItem::where('supplier_user_id', $request->user()->id)->orderBy('name')->get();

            return $this->listResponse($items->map([$this, 'presentItem'])->all());
        });
    }

    public function storeItem(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'code' => 'nullable|string|max:32',
                'name' => 'required|string|max:200',
                'unit' => 'nullable|string|max:16',
                // priceHalalas is the doc field name; price is the legacy alias. Money is integer halalas.
                'price' => 'required_without:priceHalalas|integer|min:0',
                'priceHalalas' => 'required_without:price|integer|min:0',
                'minQty' => 'nullable|integer|min:0',
                'maxQty' => 'nullable|integer|min:0',
                'available' => 'nullable|boolean',
                'leadTimeDays' => 'nullable|integer|min:0',
                // The mobile pickers group by category, and `supplierId` names
                // which of this login's supplier records the item belongs to.
                'category' => 'nullable|string|max:80',
                'supplierId' => 'nullable|uuid',
            ]);
            $available = $data['available'] ?? true;
            // The supplier record this login owns: it carries the company whose
            // branches the item must be published to, and the supplier id the
            // mobile price row hangs off. Without them the item existed only in
            // asab_supplier_items and never reached the app (reported 2026-07-26).
            $owner = $this->ownSupplier($request);

            $item = DB::transaction(function () use ($request, $data, $available, $owner) {
                $item = SupplierItem::create([
                    'supplier_user_id' => $request->user()->id,
                    'company_id' => $owner->company_id,
                    'supplier_id' => $owner->id,
                    'brand_id' => $owner->brand_id,
                    'category' => $data['category'] ?? null,
                    'code' => $data['code'] ?? null,
                    'name' => $data['name'],
                    'unit' => $data['unit'] ?? null,
                    'price' => $data['priceHalalas'] ?? $data['price'],
                    'min_qty' => $data['minQty'] ?? null,
                    'max_qty' => $data['maxQty'] ?? null,
                    'available' => $available,
                    'lead_time_days' => $data['leadTimeDays'] ?? null,
                    'status' => $available ? 'active' : 'inactive',
                ]);
                // Same write-through the procurement surface performs, so an item
                // the SUPPLIER adds shows up in the app's item + source pickers.
                $this->bridge->syncItem($item);

                return $item;
            });

            return $this->created($this->presentItem($item->fresh()) + $this->publishState($item, $owner));
        });
    }

    public function updateItem(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $item = SupplierItem::where('supplier_user_id', $request->user()->id)->findOrFail($id);
            $data = $request->validate([
                'code' => 'sometimes|string|max:32',
                'name' => 'sometimes|string|max:200',
                'unit' => 'sometimes|string|max:16',
                // priceHalalas is the doc field name; price is the legacy alias. Money is integer halalas.
                'price' => 'sometimes|integer|min:0',
                'priceHalalas' => 'sometimes|integer|min:0',
                'minQty' => 'sometimes|integer|min:0',
                'maxQty' => 'sometimes|integer|min:0',
                'available' => 'sometimes|boolean',
                'leadTimeDays' => 'sometimes|integer|min:0',
            ]);
            $updates = array_filter([
                'code' => $data['code'] ?? null,
                'name' => $data['name'] ?? null,
                'unit' => $data['unit'] ?? null,
                'price' => $data['priceHalalas'] ?? $data['price'] ?? null,
                'min_qty' => $data['minQty'] ?? null,
                'max_qty' => $data['maxQty'] ?? null,
                'lead_time_days' => $data['leadTimeDays'] ?? null,
            ], fn ($v) => $v !== null);
            // available is a boolean: handle separately so an explicit false is not dropped by array_filter.
            if ($request->has('available')) {
                $updates['available'] = $data['available'];
                // Keep the legacy status flag in sync with availability.
                $updates['status'] = $data['available'] ? 'active' : 'inactive';
            }
            $owner = $this->ownSupplier($request);
            DB::transaction(function () use ($item, $updates, $owner) {
                // Repair the link on rows created before the bridge was wired, so
                // an edit publishes an item the create could not. Only fills what
                // is missing — an item already attached to a supplier record is
                // never re-pointed by an edit.
                $updates += array_filter([
                    'company_id' => $item->company_id ?: $owner->company_id,
                    'supplier_id' => $item->supplier_id ?: $owner->id,
                    'brand_id' => $item->brand_id ?: $owner->brand_id,
                ], fn ($v) => $v !== null);
                $item->update($updates);
                $this->bridge->syncItem($item->fresh());
            });

            return $this->ok($this->presentItem($item->fresh()) + $this->publishState($item->fresh(), $owner));
        });
    }

    public function toggleItem(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $item = SupplierItem::where('supplier_user_id', $request->user()->id)->findOrFail($id);
            $nowActive = $item->status !== 'active';

            DB::transaction(function () use ($item, $nowActive) {
                // Keep the availability flag in sync with the toggled status.
                $item->update(['status' => $nowActive ? 'active' : 'inactive', 'available' => $nowActive]);
                // syncItem mirrors `status` onto the mobile item's is_active, so
                // the toggle reaches the app in both directions.
                $this->bridge->syncItem($item->fresh());
            });

            return $this->ok($this->presentItem($item->fresh()));
        });
    }

    public function destroyItem(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $item = SupplierItem::where('supplier_user_id', $request->user()->id)->findOrFail($id);

            DB::transaction(function () use ($item) {
                // Deactivate (never hard-delete) the bridged mobile rows first —
                // orders already reference them.
                $this->bridge->deactivateItem($item);
                $item->delete();
            });

            return $this->noContent();
        });
    }

    /**
     * The supplier record whose catalog this request is editing.
     *
     * A login may own SEVERAL commercial supplier records, and guessing would
     * attach the catalog and its prices to the wrong one, so an ambiguous login
     * must name the record (`supplierId`). A login with no record at all cannot
     * be published to the app, and saying so beats storing an item nobody sees.
     *
     * @throws AsabException 422 no record / unknown record, 409 ambiguous
     */
    private function ownSupplier(Request $request): AsabSupplier
    {
        $owned = AsabSupplier::where('user_id', $request->user()->id)->get();

        if ($owned->isEmpty()) {
            throw new AsabException(
                'SUPPLIER_RECORD_MISSING',
                'This login is not linked to a supplier record, so its catalog cannot be published.',
                'هذا الحساب غير مرتبط بسجل مورد، فلا يمكن نشر أصنافه.',
                422,
            );
        }

        if ($owned->count() === 1) {
            return $owned->first();
        }

        $chosen = $request->input('supplierId');
        $match = $chosen ? $owned->firstWhere('id', $chosen) : null;

        if ($match === null) {
            throw new AsabException(
                'SUPPLIER_RECORD_AMBIGUOUS',
                'This login owns several supplier records — send supplierId to name the one the item belongs to.',
                'هذا الحساب مرتبط بأكثر من سجل مورد — أرسل supplierId لتحديد السجل.',
                409,
                ['supplierIds' => $owned->pluck('id')->all()],
            );
        }

        return $match;
    }

    /**
     * How far the item reached the mobile world, and what stopped it.
     *
     * Three things can hold it back, and each is invisible to the supplier
     * otherwise (they would learn it from a branch manager who cannot find the
     * item): a live global name/code collision means the bridge refused to touch
     * an `items` row it does not own; a company-less (platform) supplier has no
     * branch set to publish availability to; and a supplier record with no
     * mobile login has nothing to hang the price row off.
     *
     * @return array<string, mixed>
     */
    private function publishState(SupplierItem $item, AsabSupplier $owner): array
    {
        $warnings = [];

        if ($item->purchase_item_id === null) {
            $warnings[] = [
                'code' => 'ITEM_NAME_TAKEN',
                'message' => 'An item with this name/code already exists in the shared catalog and is owned by another party, so this row was not published to the app.',
                'messageAr' => 'يوجد صنف بنفس الاسم/الرمز في الكتالوج المشترك يملكه طرف آخر، فلم يُنشر هذا الصنف في التطبيق.',
            ];
        }
        if (! $item->company_id) {
            $warnings[] = [
                'code' => 'NO_BRANCH_AVAILABILITY',
                'message' => 'This supplier is not attached to a company, so the item was not added to any branch item list.',
                'messageAr' => 'هذا المورد غير مرتبط بشركة، فلم يُضَف الصنف إلى قائمة أصناف أي فرع.',
            ];
        }
        if (! $owner->legacy_supplier_id) {
            $warnings[] = [
                'code' => 'NO_MOBILE_SUPPLIER_LOGIN',
                'message' => 'This supplier has no mobile record yet, so the item carries no price for the app to order at.',
                'messageAr' => 'هذا المورد ليس له سجل في التطبيق بعد، فالصنف بلا سعر يُطلب به.',
            ];
        }

        return [
            'publishedToApp' => $warnings === [],
            'warnings' => $warnings,
        ];
    }

    /** GET /asab/supplier/items/export?format=xlsx|csv */
    public function itemsExport(Request $request): BinaryFileResponse
    {
        $format = $request->query('format', 'xlsx') === 'csv' ? 'csv' : 'xlsx';

        return $this->exports->supplierItems($format, $request->user()->id);
    }

    /** GET /asab/supplier/orders/export?status=accepted|rejected&format=xlsx|csv */
    public function ordersExport(Request $request): BinaryFileResponse
    {
        $format = $request->query('format', 'xlsx') === 'csv' ? 'csv' : 'xlsx';

        return $this->exports->supplierOrders($format, $request->query('status'), $this->ownSupplierIds($request));
    }

    public function reports(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            // Revenue counts fulfilled sales only (accepted + delivered); a rejected
            // or still-pending order is not a sale. SUP-3 breakdowns (topItems/
            // topBranches/monthly) are DEFERRED — the keys are omitted rather than
            // shipped as permanently-empty placeholders (dead-code rule).
            $base = $this->ownOrders($request)->whereIn('status', SupplierOrderStatus::fulfilled());

            return $this->ok([
                'totalRevenue' => (int) (clone $base)->sum('amount'),
                'orderCount' => (clone $base)->count(),
                'averageOrderValue' => (int) round((clone $base)->avg('amount') ?? 0),
            ]);
        });
    }

    /** Supplier records owned by the logged-in dashboard user. @return string[] */
    private function ownSupplierIds(Request $request): array
    {
        return AsabSupplier::where('user_id', $request->user()->id)->pluck('id')->all();
    }

    /** Purchase operations assigned to this user's suppliers only. */
    private function ownOrders(Request $request): Builder
    {
        return Operation::where('module_key', 'purchases')
            ->whereIn('payload->supplierId', $this->ownSupplierIds($request));
    }

    private function find(Request $request, string $id): Operation
    {
        return $this->ownOrders($request)
            ->where(fn ($q) => $q->where('id', $id)->orWhere('public_id', $id))->firstOrFail();
    }

    /**
     * SUP-1.4 «مقبولة هذا الشهر» — orders accepted this calendar month.
     *
     * Counts the fulfilled set (accepted + delivered): an order accepted and then
     * delivered in the same month has moved to `delivered`, but the supplier still
     * accepted it this month, so it must count. `updated_at` is a proxy for the
     * missing accepted-at timestamp — good enough for the KPI card; a dedicated
     * `acceptedAt` would remove the cross-month edge if the metric ever hardens.
     */
    private function acceptedThisMonth(Builder $base): int
    {
        return (clone $base)
            ->whereIn('status', SupplierOrderStatus::fulfilled())
            ->whereMonth('updated_at', now()->month)
            ->whereYear('updated_at', now()->year)
            ->count();
    }

    /** Fulfilled-sale revenue for a given month/year (halalas). */
    private function salesForMonth(Builder $base, int $month, int $year): int
    {
        return (int) (clone $base)
            ->whereIn('status', SupplierOrderStatus::fulfilled())
            ->whereMonth('operation_date', $month)
            ->whereYear('operation_date', $year)
            ->sum('amount');
    }

    /** This-month vs last-month sales change, percent (1 dp). */
    private function salesTrendPct(Builder $base): float
    {
        $now = now();
        $prev = $now->copy()->subMonthNoOverflow();
        $this_ = $this->salesForMonth($base, $now->month, $now->year);
        $last = $this->salesForMonth($base, $prev->month, $prev->year);

        if ($last > 0) {
            return round((($this_ - $last) / $last) * 100, 1);
        }

        return $this_ > 0 ? 100.0 : 0.0;
    }

    /** Guard a state transition: throw a 409 domain error when the op is off-state. */
    private function assertStatusIn(Operation $op, array $allowed, string $code, string $en, string $ar): void
    {
        if (! in_array($op->status, $allowed, true)) {
            throw new AsabException($code, $en, $ar, 409);
        }
    }

    /** Bulk-resolve branch names for a set of ops (no N+1). @return array<string,string> */
    private function branchNames(Collection $branchIds): array
    {
        $ids = $branchIds->filter()->unique()->values()->all();

        return $ids === [] ? [] : Branch::whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /** @param  Collection<int,Operation>  $ops */
    private function presentMany(Collection $ops): array
    {
        $branchNames = $this->branchNames($ops->pluck('branch_id'));

        return $ops->map(fn (Operation $o) => $this->present($o, $branchNames))->all();
    }

    private function presentOne(Operation $o): array
    {
        return $this->present($o, $this->branchNames(collect([$o->branch_id])));
    }

    /**
     * SUP-1.1 order row: who it came from (branch vs procurement), the items
     * text, order + delivery dates, and the canonical status key/label.
     *
     * @param  array<string,string>  $branchNames  pre-fetched branch_id → name map
     */
    private function present(Operation $o, array $branchNames = []): array
    {
        $payload = $o->payload ?? [];
        $isBranchRequest = ($payload['kind'] ?? null) === 'branch_request';
        $from = $isBranchRequest ? ($branchNames[$o->branch_id] ?? 'فرع') : 'مدير المشتريات';
        $status = SupplierOrderStatus::present($o->status);

        return [
            'id' => $o->id,
            'publicId' => $o->public_id,
            'total' => $o->amount,
            'status' => $o->status,
            'statusKey' => $status['key'],
            'statusLabel' => $status['label'],
            'from' => $from,
            'itemsText' => $this->itemsText($o),
            'orderDate' => optional($o->operation_date)->toIso8601String(),
            'deliveryDate' => $payload['supplierResponse']['deliveryDate'] ?? $payload['deliveryDate'] ?? null,
        ];
    }

    /** Human-readable item summary across all payload shapes (reuses the purchases presenter). */
    private function itemsText(Operation $o): ?string
    {
        $lines = $this->purchases->lines($o);
        $parts = [];
        foreach ($lines as $line) {
            if (($line['item'] ?? null) === null) {
                continue;
            }
            $qty = $this->num((float) ($line['ordQty'] ?? 0));
            $unit = $line['unit'] ?? null;
            $parts[] = trim($line['item'].' ×'.$qty.($unit ? ' '.$unit : ''));
        }
        if ($parts !== []) {
            return implode('، ', $parts);
        }

        $count = count($lines);

        return $count > 0 ? $count.' صنف' : null;
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }

    public function presentItem(SupplierItem $i): array
    {
        return [
            'id' => $i->id,
            'code' => $i->code,
            'name' => $i->name,
            'unit' => $i->unit,
            'price' => $i->price,
            'priceHalalas' => $i->price,
            'minQty' => $i->min_qty,
            'maxQty' => $i->max_qty,
            'available' => $i->available,
            'leadTimeDays' => $i->lead_time_days,
            'status' => $i->status,
        ];
    }
}
