<?php

namespace Modules\Admin\Http\Controllers\Supplier;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\SupplierItem;
use Modules\Admin\Services\ExportService;
use Modules\Admin\Services\ProcurementCatalogBridgeService;
use Modules\Admin\Services\SupplierItemImportService;
use Modules\Admin\Support\SupplierOrderStatus;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\TimelineEventType;
use Modules\Purchase\Models\OrderTimeline;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Supplier\Models\Supplier;
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
        private readonly ProcurementCatalogBridgeService $bridge,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $base = $this->ownOrders($request);
            $userId = $request->user()->id;

            $recent = (clone $base)
                ->orderByDesc('submitted_at')
                ->orderByDesc('created_at')
                ->limit(8)
                ->get();

            return $this->ok([
                'kpis' => [
                    'newOrders' => (clone $base)->whereIn('status', SupplierOrderStatus::synonyms('pending'))->count(),
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
            if ($status = $request->query('status')) {
                $q->whereIn('status', SupplierOrderStatus::synonyms($status));
            }
            $p = $q->orderByDesc('submitted_at')
                ->orderByDesc('created_at')
                ->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, $this->presentMany(collect($p->items())));
        });
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['deliveryDate' => 'nullable|date', 'note' => 'nullable|string|max:500']);

            $order = DB::transaction(function () use ($request, $id, $data) {
                $order = $this->find($request, $id, true);
                $this->assertStatusIn($order, SupplierOrderStatus::synonyms('pending'),
                    'ORDER_NOT_PENDING', 'Order is not pending', 'لا يمكن قبول طلب ليس في انتظار الرد');

                $oldStatus = $order->status->value;
                if (! empty($data['deliveryDate'])) {
                    $order->forceFill(['expected_delivery_at' => Carbon::parse($data['deliveryDate'])])->save();
                }
                foreach ($order->items()->where('status', OrderItemStatus::PENDING->value)->get() as $item) {
                    $item->confirm((float) $item->quantity_ordered);
                }
                if (! $order->transitionTo(OrderStatus::CONFIRMED)) {
                    throw new AsabException('ORDER_TRANSITION_REJECTED', 'Order status transition is not allowed', 'لا يمكن قبول الطلب في حالته الحالية', 409);
                }

                OrderTimeline::create([
                    'timelineable_type' => PurchaseOrder::class,
                    'timelineable_id' => $order->id,
                    'event_type' => TimelineEventType::ORDER_CONFIRMED,
                    'old_status' => $oldStatus,
                    'new_status' => OrderStatus::CONFIRMED->value,
                    'actor_id' => $request->user()->id,
                    'actor_type' => AsabUser::class,
                    'actor_name' => $request->user()->name,
                    'actor_role' => 'supplier',
                    'title' => 'Supplier accepted order',
                    'description' => $data['note'] ?? null,
                    'metadata' => [
                        'deliveryDate' => $data['deliveryDate'] ?? null,
                        'note' => $data['note'] ?? null,
                    ],
                    'occurred_at' => now(),
                ]);

                return $order->fresh(['branch:id,name', 'items', 'group:id,group_number,expected_delivery_date']);
            });

            return $this->ok($this->present($order));
        });
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['reason' => 'required|string|max:500', 'note' => 'nullable|string|max:500']);

            $order = DB::transaction(function () use ($request, $id, $data) {
                $order = $this->find($request, $id, true);
                $this->assertStatusIn($order, SupplierOrderStatus::synonyms('pending'),
                    'ORDER_NOT_PENDING', 'Order is not pending', 'لا يمكن رفض طلب ليس في انتظار الرد');

                $oldStatus = $order->status->value;
                $order->forceFill(['rejection_reason' => $data['reason']])->save();
                $order->transitionTo(OrderStatus::REJECTED);

                OrderTimeline::create([
                    'timelineable_type' => PurchaseOrder::class,
                    'timelineable_id' => $order->id,
                    'event_type' => TimelineEventType::ORDER_REJECTED,
                    'old_status' => $oldStatus,
                    'new_status' => OrderStatus::REJECTED->value,
                    'actor_id' => $request->user()->id,
                    'actor_type' => AsabUser::class,
                    'actor_name' => $request->user()->name,
                    'actor_role' => 'supplier',
                    'title' => 'Supplier rejected order',
                    'description' => $data['reason'],
                    'metadata' => [
                        'reason' => $data['reason'],
                        'note' => $data['note'] ?? null,
                    ],
                    'occurred_at' => now(),
                ]);

                return $order->fresh(['branch:id,name', 'items', 'group:id,group_number,expected_delivery_date']);
            });

            return $this->ok($this->present($order));
        });
    }

    public function prepare(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $order = DB::transaction(function () use ($request, $id) {
                $order = $this->find($request, $id, true);
                $this->assertStatusIn($order, [OrderStatus::CONFIRMED->value],
                    'ORDER_NOT_CONFIRMED', 'Order is not confirmed', 'لا يمكن بدء التحضير لطلب غير مؤكد');

                $oldStatus = $order->status->value;
                $order->transitionTo(OrderStatus::PREPARING);

                OrderTimeline::create([
                    'timelineable_type' => PurchaseOrder::class,
                    'timelineable_id' => $order->id,
                    'event_type' => TimelineEventType::PREPARATION_STARTED,
                    'old_status' => $oldStatus,
                    'new_status' => OrderStatus::PREPARING->value,
                    'actor_id' => $request->user()->id,
                    'actor_type' => AsabUser::class,
                    'actor_name' => $request->user()->name,
                    'actor_role' => 'supplier',
                    'title' => 'Supplier started preparation',
                    'occurred_at' => now(),
                ]);

                return $order->fresh(['branch:id,name', 'items', 'group:id,group_number,expected_delivery_date']);
            });

            return $this->ok($this->present($order));
        });
    }

    public function dispatch(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $order = DB::transaction(function () use ($request, $id) {
                $order = $this->find($request, $id, true);
                $this->assertStatusIn($order, [OrderStatus::CONFIRMED->value, OrderStatus::PREPARING->value],
                    'ORDER_NOT_READY_FOR_DISPATCH', 'Order cannot be dispatched in its current status', 'لا يمكن شحن الطلب في حالته الحالية');

                $oldStatus = $order->status->value;
                $order->transitionTo(OrderStatus::ON_THE_WAY);

                OrderTimeline::create([
                    'timelineable_type' => PurchaseOrder::class,
                    'timelineable_id' => $order->id,
                    'event_type' => TimelineEventType::OUT_FOR_DELIVERY,
                    'old_status' => $oldStatus,
                    'new_status' => OrderStatus::ON_THE_WAY->value,
                    'actor_id' => $request->user()->id,
                    'actor_type' => AsabUser::class,
                    'actor_name' => $request->user()->name,
                    'actor_role' => 'supplier',
                    'title' => 'Supplier dispatched order',
                    'occurred_at' => now(),
                ]);

                return $order->fresh(['branch:id,name', 'items', 'group:id,group_number,expected_delivery_date']);
            });

            return $this->ok($this->present($order));
        });
    }

    public function markDelivered(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['deliveredAt' => 'nullable|date', 'deliveryNote' => 'nullable|string|max:500']);

            $order = DB::transaction(function () use ($request, $id, $data) {
                $order = $this->find($request, $id, true);
                $this->assertStatusIn($order, [
                    OrderStatus::CONFIRMED->value,
                    OrderStatus::PREPARING->value,
                    OrderStatus::ON_THE_WAY->value,
                ], 'ORDER_NOT_ACCEPTED', 'Order is not accepted', 'لا يمكن تسليم طلب غير مقبول');

                $oldStatus = $order->status->value;
                // PORTAL-01: Supplier delivery declaration only — does NOT create GoodsReceipt or InventoryStockMovement.
                $order->transitionTo(OrderStatus::DELIVERED);
                if (! empty($data['deliveredAt'])) {
                    $order->forceFill(['actual_delivery_at' => Carbon::parse($data['deliveredAt'])])->save();
                }

                OrderTimeline::create([
                    'timelineable_type' => PurchaseOrder::class,
                    'timelineable_id' => $order->id,
                    'event_type' => TimelineEventType::DELIVERED,
                    'old_status' => $oldStatus,
                    'new_status' => OrderStatus::DELIVERED->value,
                    'actor_id' => $request->user()->id,
                    'actor_type' => AsabUser::class,
                    'actor_name' => $request->user()->name,
                    'actor_role' => 'supplier',
                    'title' => 'Supplier declared delivery',
                    'description' => $data['deliveryNote'] ?? null,
                    'metadata' => [
                        'supplier_delivery_declaration' => true,
                        'deliveredAt' => $data['deliveredAt'] ?? now()->toIso8601String(),
                        'note' => $data['deliveryNote'] ?? null,
                    ],
                    'occurred_at' => now(),
                ]);

                return $order->fresh(['branch:id,name', 'items', 'group:id,group_number,expected_delivery_date']);
            });

            return $this->ok($this->present($order));
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
                // priceHalalas is the doc field name; price is the legacy alias.
                // BOTH are integer halalas. `priceSar` is the decimal the «السعر
                // (ر.س)» field actually holds — sending 20 there means 20 ر.س,
                // sending 20 in `price` means 0.20 ر.س (2026-08-04).
                'price' => 'required_without_all:priceHalalas,priceSar|integer|min:0',
                'priceHalalas' => 'required_without_all:price,priceSar|integer|min:0',
                'priceSar' => 'required_without_all:price,priceHalalas|numeric|min:0',
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
                    'price' => $this->priceHalalas($data),
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
                // Decimal ر.س — see storeItem() for why the two live side by side.
                'priceSar' => 'sometimes|numeric|min:0',
                'minQty' => 'sometimes|integer|min:0',
                'maxQty' => 'sometimes|integer|min:0',
                'available' => 'sometimes|boolean',
                'leadTimeDays' => 'sometimes|integer|min:0',
            ]);
            $updates = array_filter([
                'code' => $data['code'] ?? null,
                'name' => $data['name'] ?? null,
                'unit' => $data['unit'] ?? null,
                'price' => $this->priceHalalas($data, null),
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
     * item): a name/code that matches a DELETED row of another catalog, which
     * the bridge will not resurrect (a live match is shared, not blocked, since
     * 2026-08-15); a company-less (platform) supplier has no branch set to
     * publish availability to; and a supplier record with no mobile login has
     * nothing to hang the price row off.
     *
     * @return array<string, mixed>
     */
    private function publishState(SupplierItem $item, AsabSupplier $owner): array
    {
        $warnings = [];

        if ($item->purchase_item_id === null) {
            $warnings[] = [
                'code' => 'ITEM_NAME_TAKEN',
                'message' => 'A deleted item with this name/code exists in the shared catalog and belongs to another party, so this row was not published to the app.',
                'messageAr' => 'يوجد صنف محذوف بنفس الاسم/الرمز في الكتالوج المشترك يخصّ طرفاً آخر، فلم يُنشر هذا الصنف في التطبيق.',
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

    /**
     * GET /asab/supplier/items/template?format=xlsx|csv — the sheet shape the
     * import expects. The portal's Excel button had no upload target at all
     * before 2026-08-04, so a supplier could only add items one by one.
     */
    public function itemsTemplate(Request $request): Response
    {
        $headers = SupplierItemImportService::TEMPLATE;

        if ($request->query('format') === 'csv') {
            $csv = "\xEF\xBB\xBF".implode(',', $headers)."\n";

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="supplier-items-template.csv"',
            ]);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'sup_').'.xlsx';
        $writer = new \OpenSpout\Writer\XLSX\Writer;
        $writer->openToFile($tmp);
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($headers));
        $writer->close();

        return response(file_get_contents($tmp), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="supplier-items-template.xlsx"',
        ])->withHeaders(['X-Filename' => 'supplier-items-template.xlsx']);
    }

    /** POST /asab/supplier/items/import — bulk «الأصناف والأسعار» upload. */
    public function itemsImport(Request $request, SupplierItemImportService $importer): JsonResponse
    {
        return $this->run(function () use ($request, $importer) {
            $request->validate([
                'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:5120',
            ]);
            $owner = $this->ownSupplier($request);

            $result = $importer->import($request->file('file'), $request->user(), $owner);

            return $this->ok([
                'itemCount' => $result['count'],
                'errors' => $result['errors'],
            ]);
        });
    }

    /** GET /asab/supplier/orders/export?status=accepted|rejected&format=xlsx|csv */
    public function ordersExport(Request $request): BinaryFileResponse
    {
        $format = $request->query('format', 'xlsx') === 'csv' ? 'csv' : 'xlsx';

        return $this->exports->supplierOrders($format, $request->query('status'), $request->user()->id);
    }

    public function reports(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $base = $this->ownOrders($request)->whereIn('status', SupplierOrderStatus::fulfilled());
            $count = (clone $base)->count();
            $totalHalalas = (int) round(((float) (clone $base)->sum('total_amount')) * 100);

            return $this->ok([
                'totalRevenue' => $totalHalalas,
                'orderCount' => $count,
                'averageOrderValue' => $count > 0 ? (int) round($totalHalalas / $count) : 0,
            ]);
        });
    }

    /** Purchase orders assigned to this user's suppliers (tenant-isolated, direct or dispatched group). */
    private function ownOrders(Request $request): Builder
    {
        return SupplierOrderStatus::scopePurchaseOrdersForUser(
            PurchaseOrder::query()->with(['branch:id,name', 'items', 'group:id,group_number,expected_delivery_date']),
            $request->user()->id,
        );
    }

    private function find(Request $request, string $id, bool $lock = false): PurchaseOrder
    {
        $q = $this->ownOrders($request)
            ->where(fn ($q) => $q->where('id', $id)->orWhere('order_number', $id));

        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->firstOrFail();
    }

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
        return (int) round(((float) (clone $base)
            ->whereIn('status', SupplierOrderStatus::fulfilled())
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->sum('total_amount')) * 100);
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

    /** Guard a state transition: throw a 409 domain error when the order is off-state. */
    private function assertStatusIn(PurchaseOrder $order, array $allowed, string $code, string $en, string $ar): void
    {
        if (! in_array($order->status?->value, $allowed, true)) {
            throw new AsabException($code, $en, $ar, 409);
        }
    }

    /** @param  Collection<int,PurchaseOrder>  $orders */
    private function presentMany(Collection $orders): array
    {
        return $orders->map(fn (PurchaseOrder $o) => $this->present($o))->all();
    }

    private function present(PurchaseOrder $o): array
    {
        $rawStatus = $o->status?->value;
        $status = SupplierOrderStatus::present($rawStatus);
        $from = $o->order_type === OrderType::DIRECT_SUPPLIER
            ? ($o->branch?->name ?? 'فرع')
            : 'مدير المشتريات';

        return [
            'id' => $o->id,
            'publicId' => $o->order_number,
            'groupNumber' => $o->group?->group_number,
            'total' => (int) round(((float) $o->total_amount) * 100),
            'status' => $rawStatus,
            'statusKey' => $status['key'],
            'statusLabel' => $status['label'],
            'from' => $from,
            'itemsText' => $this->itemsText($o),
            'orderDate' => optional($o->submitted_at ?? $o->created_at)->toIso8601String(),
            'deliveryDate' => optional($o->expected_delivery_at ?? $o->group?->expected_delivery_date ?? $o->preferred_delivery_date)->toDateString(),
        ];
    }

    private function itemsText(PurchaseOrder $o): ?string
    {
        $parts = [];
        foreach ($o->items as $line) {
            if (! $line->item_name) {
                continue;
            }
            $qty = $this->num((float) ($line->quantity_confirmed ?? $line->quantity_ordered));
            $unit = $line->unit_of_measurement;
            $parts[] = trim($line->item_name.' ×'.$qty.($unit ? ' '.$unit : ''));
        }
        if ($parts !== []) {
            return implode('، ', $parts);
        }

        $count = $o->items->count();

        return $count > 0 ? $count.' صنف' : null;
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }

    /**
     * The stored price in halalas from whichever field the client sent.
     *
     * @param  array<string, mixed>  $data
     */
    private function priceHalalas(array $data, ?int $default = 0): ?int
    {
        if (isset($data['priceHalalas'])) {
            return (int) $data['priceHalalas'];
        }
        if (isset($data['price'])) {
            return (int) $data['price'];
        }
        if (isset($data['priceSar'])) {
            return (int) round(((float) $data['priceSar']) * 100);
        }

        return $default;
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
            // The «السعر (ر.س)» column renders this one verbatim.
            'priceSar' => round(((int) $i->price) / 100, 2),
            'minQty' => $i->min_qty,
            'maxQty' => $i->max_qty,
            'available' => $i->available,
            'leadTimeDays' => $i->lead_time_days,
            'status' => $i->status,
        ];
    }
}
