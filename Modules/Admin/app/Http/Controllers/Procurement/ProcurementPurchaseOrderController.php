<?php

namespace Modules\Admin\Http\Controllers\Procurement;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\Notifications\SupplierDispatch;
use Modules\Admin\Services\Notifications\SupplierOrderNotifier;
use Modules\Admin\Services\TenantBranchResolver;
use Modules\Admin\Support\TenantContext;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\TimelineEventType;
use Modules\Purchase\Exceptions\PurchaseOrderException;
use Modules\Purchase\Models\OrderTimeline;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderGroup;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Services\OrderConsolidationService;
use Modules\Purchase\Services\ProcurementDecisionService;
use Modules\Supplier\Models\Supplier;

/**
 * Purchasing-manager surface over the MOBILE purchase_orders pipeline
 * (meeting flow: branch submits from the app → purchasing manager reviews on
 * the dashboard → approved orders continue to the supplier). Decisions go
 * through ProcurementDecisionService so mobile and dashboard share one state
 * machine; the Operation-based endpoints remain for dashboard-created orders.
 */
class ProcurementPurchaseOrderController extends AsabController
{
    /** Statuses still awaiting a purchasing-manager decision. */
    private const INCOMING = ['pending', 'emergency', 'variance'];

    /** Statuses a client may filter by (draft excluded: unsubmitted branch data). */
    private const FILTERABLE = ['pending', 'emergency', 'variance', 'confirmed', 'sent_awaiting_response', 'rejected',
        'cancelled', 'cancelled_by_branch', 'cancelled_by_supplier', 'preparing', 'on_the_way',
        'delivered', 'closed'];

    /** Order types routed to suppliers; internal branch transfers stay branch-side. */
    private const SUPPLIER_BOUND = ['direct_supplier', 'via_purchasing_officer', 'multiple_sources'];

    public function __construct(
        private readonly ProcurementDecisionService $decisions,
        private readonly OrderConsolidationService $consolidation,
        private readonly TenantBranchResolver $branches,
        private readonly TenantContext $tenant,
        private readonly SupplierOrderNotifier $notifier,
    ) {}

    /** GET procurement/purchase-orders?status=incoming|<status>&branchId= */
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate([
                'status' => 'sometimes|string|in:incoming,'.implode(',', self::FILTERABLE),
                'branchId' => 'sometimes|string',
                'priority' => 'sometimes|string|in:high,normal',
            ]);

            $q = $this->scoped()->with('branch:id,name');

            $status = $request->query('status', 'incoming');
            $status === 'incoming'
                ? $q->whereIn('status', self::INCOMING)
                : $q->where('status', $status);

            if ($branch = $request->query('branchId')) {
                $q->where('branch_id', $branch);
            }
            if ($priority = $request->query('priority')) {
                $q->where('priority', $priority);
            }

            $perPage = min((int) $request->query('pageSize', 20), 100);
            $p = $q->orderByDesc('submitted_at')->orderByDesc('created_at')
                ->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, array_map([$this, 'present'], $p->items()));
        });
    }

    /** GET procurement/purchase-orders/approved-by-me — orders this manager decided. */
    public function approvedByMe(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = $this->scoped()->with('branch:id,name')
                ->decidedBy($request->user()->id)
                ->where('status', '!=', 'rejected');

            $perPage = min((int) $request->query('pageSize', 20), 100);
            $p = $q->orderByDesc('decided_at')
                ->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, array_map([$this, 'present'], $p->items()));
        });
    }

    public function show(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $order = $this->find($id)->load(['branch:id,name', 'items']);
            $data = $this->present($order);
            $data['items'] = $order->items->map(fn (PurchaseOrderItem $i) => [
                'orderItemId' => $i->id,
                'itemId' => $i->item_id,
                'name' => $i->item_name,
                'unit' => $i->unit_of_measurement,
                'quantityOrdered' => (float) $i->quantity_ordered,
                'quantityConfirmed' => $i->quantity_confirmed !== null ? (float) $i->quantity_confirmed : null,
                'unitPrice' => (float) $i->unit_price,
                'totalPrice' => (float) $i->total_price,
                'status' => $i->status?->value,
                // Review-before-approve consumption context (client UI: بيانات الاستهلاك).
                'dailyConsumption' => $i->daily_consumption !== null ? (float) $i->daily_consumption : null,
                'remainingBalance' => $i->remaining_balance !== null ? (float) $i->remaining_balance : null,
                'weekendForecast' => $i->weekend_forecast !== null ? (float) $i->weekend_forecast : null,
                'nextSupplyDate' => optional($i->next_supply_date)->toDateString(),
            ])->all();

            return $this->ok($data);
        });
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            return $this->decide(fn () => $this->decisions->approve($this->find($id), $request->user()->id));
        });
    }

    /**
     * POST .../purchase-orders/bulk-approve {orderIds:[...]} — "اعتماد الكل".
     * Each order approves independently; failures are reported, not fatal.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['orderIds' => 'required|array|min:1', 'orderIds.*' => 'string']);

            $orders = $this->scoped()->whereIn('id', $data['orderIds'])->get();
            $approved = [];
            $failed = [];

            foreach ($orders as $order) {
                try {
                    $this->decisions->approve($order, $request->user()->id);
                    $approved[] = $order->id;
                } catch (PurchaseOrderException $e) {
                    $failed[] = ['id' => $order->id, 'reason' => $e->getMessage()];
                }
            }

            // Ids outside the tenant scope (or unknown) are reported as not found.
            foreach (array_diff($data['orderIds'], $orders->pluck('id')->all()) as $missing) {
                $failed[] = ['id' => $missing, 'reason' => 'not found'];
            }

            return $this->ok(['approved' => $approved, 'failed' => $failed, 'count' => count($approved)]);
        });
    }

    /** POST .../partial-approve {items:[{orderItemId, quantity}], note?} — quantity 0 rejects the line. */
    public function partialApprove(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate([
                'items' => 'required|array|min:1',
                'items.*.orderItemId' => 'required|string',
                'items.*.quantity' => 'required|numeric|min:0',
                'note' => 'nullable|string|max:500',
            ]);

            $quantities = collect($data['items'])->mapWithKeys(
                fn ($line) => [$line['orderItemId'] => (float) $line['quantity']],
            )->all();

            return $this->decide(fn () => $this->decisions->approvePartial(
                $this->find($id), $quantities, $request->user()->id, $data['note'] ?? null,
            ));
        });
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['reason' => 'required|string|max:500']);

            return $this->decide(fn () => $this->decisions->reject($this->find($id), $request->user()->id, $data['reason']));
        });
    }

    /** GET .../purchase-orders/grouped?by=supplier|city|item — "الطلبات المجمعة" (live preview). */
    public function grouped(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate(['by' => 'sometimes|string|in:supplier,city,item']);
            $branchIds = $this->branches->legacyBranchIds($this->tenant);

            return $this->ok(match ($request->query('by', 'supplier')) {
                'city' => ['cities' => $this->consolidation->previewByCity($branchIds)],
                // PRC-2.1 core value loop — group cards per catalog item.
                'item' => ['items' => $this->consolidation->previewByItem($branchIds)],
                default => $this->consolidation->previewBySupplier($branchIds),
            });
        });
    }

    /** POST .../purchase-orders/grouped/send {supplierId, orderIds?, expectedDeliveryDate} — "إرسال للمورد". */
    public function sendGroup(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'supplierId' => 'required|string',
                'orderIds' => 'sometimes|array|min:1',
                'orderIds.*' => 'string',
                'expectedDeliveryDate' => 'required|date',
            ]);

            try {
                $group = $this->consolidation->send(
                    $data['supplierId'], $data['orderIds'] ?? null, $request->user()->id,
                    $this->branches->legacyBranchIds($this->tenant), $data['expectedDeliveryDate'] ?? null,
                    $this->tenant->companyId,
                );
            } catch (PurchaseOrderException $e) {
                return $this->fail('CONSOLIDATION_FAILED', $e->getMessage(), 'تعذّر تجميع الطلبات وإرسالها', [], 409);
            }

            return $this->created([
                'groupId' => $group->id,
                'groupNumber' => $group->group_number,
                'supplierId' => $group->supplier_id,
                'ordersCount' => $group->orders->count(),
                'savings' => $group->savings_amount !== null ? (float) $group->savings_amount : null,
                'savingsPct' => $group->savings_pct !== null ? (float) $group->savings_pct : null,
                'eta' => optional($group->expected_delivery_date)->toDateString(),
                'sentAt' => optional($group->sent_at)->toIso8601String(),
                // FR-PUR-1 — the ready-to-open WhatsApp order link for this batch.
                'whatsapp' => $this->supplierDispatch($group)->toArray(),
            ]);
        });
    }

    /** GET .../purchase-orders/sent — "المرسلة للموردين". */
    public function sent(): JsonResponse
    {
        return $this->run(fn () => $this->listResponse(
            $this->consolidation->sentGroups($this->branches->legacyBranchIds($this->tenant)),
        ));
    }

    /** GET .../purchase-orders/groups/{groupId} — batch details / tracking. */
    public function groupShow(string $groupId): JsonResponse
    {
        return $this->run(fn () => $this->ok(
            $this->consolidation->groupDetails($groupId, $this->branches->legacyBranchIds($this->tenant)),
        ));
    }

    /**
     * DEC-06: Record a manual supplier response (WhatsApp / phone) for a
     * non-subscribed supplier on a dispatched purchase order.
     */
    public function manualSupplierResponse(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate([
                'decision' => 'required|string|in:accepted,rejected',
                'source' => 'required|string|in:whatsapp,phone',
                'respondedAt' => 'required|date',
                'reason' => 'required_if:decision,rejected|nullable|string|max:500',
                'note' => 'nullable|string|max:500',
            ]);

            return DB::transaction(function () use ($request, $id, $data) {
                $order = $this->scoped()
                    ->where(fn ($q) => $q->where('id', $id)->orWhere('order_number', $id))
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $order->supplier_id) {
                    return $this->fail('SUPPLIER_REQUIRED', 'Order has no assigned supplier', 'الطلب غير مرتبط بمورد', [], 422);
                }

                $supplierRows = AsabSupplier::withoutGlobalScopes()
                    ->where('legacy_supplier_id', $order->supplier_id)
                    ->get();

                $companyId = $this->tenant->companyId;
                if ($companyId !== null && $supplierRows->isNotEmpty()) {
                    $inScope = $supplierRows->contains(
                        fn (AsabSupplier $s) => $s->company_id === null || $s->company_id === $companyId
                    );
                    if (! $inScope) {
                        return $this->fail('SUPPLIER_OUT_OF_SCOPE', 'Supplier is outside tenant scope', 'المورد خارج نطاق الشركة', [], 404);
                    }
                }

                $isSubscribed = $supplierRows->contains(
                    fn (AsabSupplier $s) => ! $s->is_external && $s->user_id !== null
                );
                if ($isSubscribed) {
                    return $this->fail(
                        'SUPPLIER_IS_SUBSCRIBED',
                        'Subscribed suppliers must respond through the Supplier Portal',
                        'المورد المشترك يسجل رده عبر بوابة الموردين فقط',
                        [],
                        422,
                    );
                }

                $awaiting = $order->status === OrderStatus::SENT_AWAITING_RESPONSE
                    || ($order->order_type === OrderType::DIRECT_SUPPLIER && in_array($order->status, [
                        OrderStatus::PENDING,
                        OrderStatus::EMERGENCY,
                    ], true));

                if (! $awaiting) {
                    return $this->fail(
                        'ORDER_NOT_AWAITING_RESPONSE',
                        'Order is not awaiting supplier response',
                        'الطلب ليس في حالة انتظار رد المورد',
                        [],
                        409,
                    );
                }

                $alreadyRecorded = OrderTimeline::where('timelineable_type', PurchaseOrder::class)
                    ->where('timelineable_id', $order->id)
                    ->where('title', 'Manual supplier response recorded')
                    ->exists();
                if ($alreadyRecorded) {
                    return $this->fail(
                        'SUPPLIER_RESPONSE_ALREADY_RECORDED',
                        'Supplier response has already been recorded for this order',
                        'تم تسجيل رد المورد مسبقًا لهذا الطلب',
                        [],
                        409,
                    );
                }

                $oldStatus = $order->status->value;
                $respondedAt = Carbon::parse($data['respondedAt']);
                $note = $data['note'] ?? $data['reason'] ?? null;
                $targetStatus = $data['decision'] === 'accepted'
                    ? OrderStatus::CONFIRMED
                    : OrderStatus::REJECTED;

                if (! $order->transitionTo($targetStatus)) {
                    return $this->fail(
                        'ORDER_TRANSITION_REJECTED',
                        'Order status transition is not allowed',
                        'لا يمكن تغيير حالة الطلب من حالته الحالية',
                        [],
                        409,
                    );
                }

                if ($targetStatus === OrderStatus::CONFIRMED) {
                    foreach ($order->items()->where('status', OrderItemStatus::PENDING->value)->get() as $item) {
                        $item->confirm((float) $item->quantity_ordered);
                    }
                } else {
                    $order->forceFill([
                        'rejection_reason' => $data['reason'] ?? $data['note'] ?? 'Rejected by supplier',
                    ])->save();
                    $order->items()
                        ->where('status', OrderItemStatus::PENDING->value)
                        ->update([
                            'status' => OrderItemStatus::REJECTED->value,
                            'quantity_confirmed' => 0,
                        ]);
                }

                $timeline = OrderTimeline::create([
                    'timelineable_type' => PurchaseOrder::class,
                    'timelineable_id' => $order->id,
                    'event_type' => $data['decision'] === 'accepted'
                        ? TimelineEventType::ORDER_CONFIRMED
                        : TimelineEventType::ORDER_REJECTED,
                    'old_status' => $oldStatus,
                    'new_status' => $order->fresh()->status->value,
                    'actor_id' => $request->user()->id,
                    'actor_type' => AsabUser::class,
                    'actor_name' => $request->user()->name,
                    'actor_role' => 'procurement',
                    'title' => 'Manual supplier response recorded',
                    'description' => $note,
                    'metadata' => [
                        'manual_supplier_response' => true,
                        'response_decision' => $data['decision'],
                        'response_source' => $data['source'],
                        'responded_at' => $respondedAt->toIso8601String(),
                        'recorded_by_user_id' => $request->user()->id,
                        'note' => $note,
                    ],
                    'occurred_at' => $respondedAt,
                ]);

                return $this->ok($this->present($order->fresh()) + [
                    'manualResponse' => [
                        'decision' => $data['decision'],
                        'source' => $data['source'],
                        'respondedAt' => $respondedAt->toIso8601String(),
                        'recordedByUserId' => $request->user()->id,
                        'recordedByName' => $request->user()->name,
                        'note' => $note,
                        'timelineId' => $timeline->id,
                    ],
                ]);
            });
        });
    }

    /**
     * FR-PUR-1 — the WhatsApp order dispatch for a freshly-sent batch: the
     * group's aggregated item lines addressed to the supplier's phone.
     */
    private function supplierDispatch(PurchaseOrderGroup $group): SupplierDispatch
    {
        $details = $this->consolidation->groupDetails($group->id, $this->branches->legacyBranchIds($this->tenant));

        $lines = array_map(fn (array $it) => [
            'name' => $it['name'],
            'qty' => $this->qtyString((float) $it['totalQuantity']),
            'unit' => $it['unit'] ?? null,
        ], $details['items']);

        $supplier = Supplier::whereKey($group->supplier_id)->first(['name', 'phone']);

        return $this->notifier->forOrder(
            $group->group_number,
            $supplier?->name ?? ($details['supplierName'] ?? '—'),
            $supplier?->phone,
            $lines,
            optional($group->expected_delivery_date)->toDateString(),
        );
    }

    /** A quantity without trailing-zero noise: 5.000 → "5", 2.500 → "2.5". */
    private function qtyString(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    }

    /** Run a decision, translating domain refusals into the spec error envelope. */
    private function decide(callable $fn): JsonResponse
    {
        try {
            return $this->ok($this->present($fn()));
        } catch (PurchaseOrderException $e) {
            return $this->fail('ORDER_NOT_DECIDABLE', $e->getMessage(), 'لا يمكن اتخاذ قرار على الطلب في حالته الحالية', [], 409);
        }
    }

    /** Base query: supplier-bound submitted orders inside the tenant's branches. */
    private function scoped(): Builder
    {
        $q = PurchaseOrder::query()->whereIn('order_type', self::SUPPLIER_BOUND);

        $branchIds = $this->branches->legacyBranchIds($this->tenant);
        if ($branchIds !== null) {
            $q->whereIn('branch_id', $branchIds);
        }

        return $q;
    }

    private function find(string $id): PurchaseOrder
    {
        return $this->scoped()->where(fn ($q) => $q->where('id', $id)->orWhere('order_number', $id))->firstOrFail();
    }

    private function present(PurchaseOrder $o): array
    {
        return [
            'id' => $o->id,
            'orderNumber' => $o->order_number,
            'branchId' => $o->branch_id,
            'branchName' => $o->relationLoaded('branch') ? $o->branch?->name : null,
            'orderType' => $o->order_type?->value,
            'status' => $o->status?->value,
            'statusLabel' => $o->status_label,
            'priority' => $o->priority?->value,
            'supplierId' => $o->supplier_id,
            'totalAmount' => (float) $o->total_amount,
            'totalItems' => (int) $o->total_items,
            'rejectionReason' => $o->rejection_reason,
            'submittedAt' => optional($o->submitted_at)->toIso8601String(),
            'decidedAt' => optional($o->decided_at)->toIso8601String(),
        ];
    }
}
