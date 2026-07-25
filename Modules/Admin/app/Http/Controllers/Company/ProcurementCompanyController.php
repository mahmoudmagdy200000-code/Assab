<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\ProcurementItemPrice;
use Modules\Admin\Models\SupplierItem;
use Modules\Admin\Models\SupplierRating;
use Modules\Admin\Models\SupplierRequest;
use Modules\Admin\Services\OperationFactory;
use Modules\Admin\Services\OperationService;
use Modules\Admin\Services\ProcurementCatalogBridgeService;

/**
 * Company-scoped Procurement surface — NEW endpoints beyond the shared
 * ProcurementController (COMPANY_DASHBOARD_API_SPEC.md §5.5).
 */
class ProcurementCompanyController extends AsabController
{
    public function __construct(
        private readonly OperationFactory $factory,
        private readonly ProcurementCatalogBridgeService $bridge,
        private readonly OperationService $operations,
    ) {}

    public function storeOrder(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'supplierId' => 'required|string', 'brandId' => 'sometimes|nullable|string', 'branchId' => 'sometimes|nullable|string',
                'items' => 'required|array|min:1',
                // items accept {itemId, qty} (doc shape) alongside the legacy {id, totalHalalas/unitPriceHalalas}.
                'items.*.itemId' => 'sometimes|string', 'items.*.qty' => 'sometimes|numeric|min:0',
                'items.*.unitPriceHalalas' => 'sometimes|integer|min:0', 'items.*.totalHalalas' => 'sometimes|integer|min:0',
                'description' => 'sometimes|nullable|string',
                'urgency' => 'sometimes|in:normal,urgent',
                // doc field `deadline`; `deliveryDate` kept as the legacy field.
                'deliveryDate' => 'sometimes|nullable|date', 'deadline' => 'sometimes|nullable|date',
            ]);
            $deliveryDate = $data['deliveryDate'] ?? $data['deadline'] ?? null;
            $total = collect($data['items'])->sum(fn ($i) => (int) ($i['totalHalalas'] ?? (($i['qty'] ?? 0) * ($i['unitPriceHalalas'] ?? 0))));
            // origin is a first-class column (§5.2b) — the payload copy is kept
            // for readers that still look there. brandId is persisted (T11.14).
            $op = $this->factory->createFromUpload('purchases', [
                'supplierId' => $data['supplierId'], 'brandId' => $data['brandId'] ?? null, 'items' => $data['items'],
                'description' => $data['description'] ?? null,
                'urgency' => $data['urgency'] ?? 'normal', 'deliveryDate' => $deliveryDate, 'origin' => 'procurement',
            ], $request->user(), $data['branchId'] ?? null, $total, 'procurement');

            return $this->created(['id' => $op->id, 'publicId' => $op->public_id, 'status' => $op->status, 'totalHalalas' => $total]);
        });
    }

    /**
     * PATCH /company/me/procurement/orders/{id} — edit a purchase order.
     *
     * A `final-approved` («مُغلق») or `rejected` operation is immutable
     * (NFR-10), and a status change here goes through the pipeline state
     * machine rather than writing the column directly — otherwise a locked
     * accounting record could be reopened behind the head accountant's back.
     */
    public function updateOrder(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $op = $this->order($request, $id);
            $this->operations->assertMutable($op, 'لا يمكن تعديل أمر شراء مُغلق');

            $data = $request->validate([
                'supplierId' => 'sometimes|string', 'items' => 'sometimes|array', 'description' => 'sometimes|nullable|string',
                'urgency' => 'sometimes|in:normal,urgent',
                'deliveryDate' => 'sometimes|nullable|date', 'deadline' => 'sometimes|nullable|date',
                // Only the transitions the pipeline owns; reason is required to reject.
                'status' => 'sometimes|in:'.implode(',', [
                    Operation::STATUS_PENDING, Operation::STATUS_APPROVED, Operation::STATUS_REJECTED, Operation::STATUS_FINAL,
                ]),
                'reason' => 'sometimes|string|max:500',
            ]);
            $deliveryDate = $data['deliveryDate'] ?? $data['deadline'] ?? null;
            $payload = array_merge($op->payload ?? [], array_filter([
                'supplierId' => $data['supplierId'] ?? null, 'items' => $data['items'] ?? null,
                'description' => $data['description'] ?? null, 'urgency' => $data['urgency'] ?? null, 'deliveryDate' => $deliveryDate,
            ], fn ($v) => $v !== null));
            $op->update(['payload' => $payload]);

            if (! empty($data['status']) && $data['status'] !== $op->status) {
                $op = $this->transition($op, $request, $data['status'], $data['reason'] ?? null);
            }

            return $this->ok(['id' => $op->id, 'publicId' => $op->public_id, 'status' => $op->fresh()->status]);
        });
    }

    /** Status changes are pipeline transitions, never column writes. */
    private function transition(Operation $op, Request $request, string $target, ?string $reason): Operation
    {
        return match ($target) {
            Operation::STATUS_APPROVED => $this->operations->approve($op, $request->user()),
            Operation::STATUS_REJECTED => $this->operations->reject(
                $op,
                $request->user(),
                $reason ?? throw new AsabException('REJECT_REASON_REQUIRED', 'A rejection reason is required', 'يجب إدخال سبب الرفض', 422),
            ),
            // Procurement may never self-set the final (locked) state — that is the
            // head accountant's transition through the pipeline (NFR-10).
            Operation::STATUS_FINAL => throw new AsabException(
                'OP_ALREADY_FINAL',
                'Final approval cannot be set from this endpoint',
                'لا يمكن الاعتماد النهائي من هذا المسار',
                409,
                ['currentStatus' => $op->status, 'requestedStatus' => $target],
            ),
            default => throw new AsabException(
                'OP_STATUS_TRANSITION_FORBIDDEN',
                'Use the pipeline endpoints for this transition',
                'استخدم مسار الاعتماد لتغيير هذه الحالة',
                422,
                ['currentStatus' => $op->status, 'requestedStatus' => $target],
            ),
        };
    }

    public function destroyOrder(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $op = $this->order($request, $id);
            $this->operations->assertMutable($op, 'لا يمكن حذف أمر شراء مُغلق');
            $op->delete();

            return $this->noContent();
        });
    }

    public function grouped(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            // Memory cap (guardrail): bound the working set, then cap the emitted groups.
            $ops = Operation::where('company_id', $request->user()->company_id)->where('module_key', 'purchases')
                ->where('status', Operation::STATUS_PENDING)->limit(1000)->get();
            $branchNames = \Modules\Branch\Models\Branch::whereIn('id', $ops->pluck('branch_id')->filter()->unique())
                ->pluck('name', 'id');

            $groups = $ops->groupBy(fn (Operation $o) => $o->payload['supplierId'] ?? 'unknown')
                ->map(function ($ops, $supplierId) use ($branchNames) {
                    $supplier = AsabSupplier::find($supplierId);

                    return [
                        'groupId' => $supplierId, 'supplierId' => $supplierId, 'supplierName' => $supplier?->name ?? '—',
                        'orderCount' => $ops->count(),
                        'branches' => $ops->pluck('branch_id')->filter()->unique()->map(fn ($id) => $branchNames[$id] ?? '—')->values()->all(),
                        'totalHalalas' => (int) $ops->sum('amount'), 'pending' => $ops->count(), 'orderIds' => $ops->pluck('id')->all(),
                    ];
                })->values()->all();

            $total = count($groups);

            return $this->listResponse(array_slice($groups, 0, 100), ['total' => $total, 'limit' => 100]);
        });
    }

    public function sent(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $ops = Operation::where('company_id', $request->user()->company_id)->where('module_key', 'purchases')
                ->whereIn('status', [Operation::STATUS_APPROVED, Operation::STATUS_FINAL])
                ->whereNotNull('payload->sentAt')->orderByDesc('operation_date')->limit(100)->get();
            $supplierNames = AsabSupplier::whereIn('id', $ops->pluck('payload.supplierId')->filter()->unique())->pluck('name', 'id');

            $rows = $ops->map(function (Operation $o) use ($supplierNames) {
                $sentAt = $o->payload['sentAt'] ?? null;
                $eta = $o->payload['deliveryDate'] ?? null;
                $sentCarbon = $sentAt ? \Illuminate\Support\Carbon::parse($sentAt) : null;
                $etaCarbon = $eta ? \Illuminate\Support\Carbon::parse($eta) : null;

                return [
                    'id' => $o->id, 'publicId' => $o->public_id, 'supplierId' => $o->payload['supplierId'] ?? null,
                    'supplierName' => $supplierNames[$o->payload['supplierId'] ?? ''] ?? '—',
                    'sentAt' => $sentAt, 'sentDateLabel' => optional($sentCarbon)->diffForHumans(),
                    'totalHalalas' => $o->amount, 'inTransit' => $o->status === Operation::STATUS_APPROVED,
                    'eta' => $eta, 'etaLabel' => optional($etaCarbon)->diffForHumans(),
                ];
            })->all();

            return $this->listResponse($rows, ['limit' => 100, 'count' => count($rows)]);
        });
    }

    public function storeItem(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'name' => 'required|string|max:200', 'unit' => 'required|string|max:16',
                'lastPriceHalalas' => 'sometimes|integer|min:0',
                // doc field `defaultPriceHalalas` is an alias for lastPriceHalalas.
                'defaultPriceHalalas' => 'sometimes|integer|min:0',
                'category' => 'sometimes|nullable|string|max:80', 'supplierId' => 'sometimes|nullable|string',
                'brandId' => 'sometimes|nullable|string', 'code' => 'sometimes|nullable|string|max:32',
            ]);
            $price = $data['lastPriceHalalas'] ?? $data['defaultPriceHalalas'] ?? null;
            $item = DB::transaction(function () use ($request, $data, $price) {
                $item = SupplierItem::create([
                    'company_id' => $request->user()->company_id, 'brand_id' => $data['brandId'] ?? null,
                    'name' => $data['name'], 'unit' => $data['unit'],
                    'price' => $price ?? 0, 'category' => $data['category'] ?? null, 'supplier_id' => $data['supplierId'] ?? null,
                    'code' => $data['code'] ?? null, 'status' => 'active',
                ]);
                if (! empty($price)) {
                    $this->recordPrice($item, $price);
                }
                // Write-through to the mobile purchasing catalog (client meeting: dashboard items appear in the app).
                $this->bridge->syncItem($item);

                return $item;
            });

            return $this->created([
                'id' => $item->id, 'name' => $item->name, 'unit' => $item->unit, 'category' => $item->category,
                'brandId' => $item->brand_id, 'supplierId' => $item->supplier_id, 'lastPriceHalalas' => $item->price,
            ]);
        });
    }

    public function updateItem(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $item = SupplierItem::where('company_id', $request->user()->company_id)->findOrFail($id);
            $data = $request->validate([
                'name' => 'sometimes|string|max:200', 'unit' => 'sometimes|string|max:16',
                'lastPriceHalalas' => 'sometimes|integer|min:0', 'status' => 'sometimes|string|max:16',
                'brandId' => 'sometimes|nullable|string', 'supplierId' => 'sometimes|nullable|string',
            ]);
            DB::transaction(function () use ($item, $data) {
                // Capture the price change BEFORE the update syncs the model's originals.
                $priceChanged = isset($data['lastPriceHalalas']) && $data['lastPriceHalalas'] !== (int) $item->price;
                $item->update(array_filter([
                    'name' => $data['name'] ?? null, 'unit' => $data['unit'] ?? null,
                    'price' => $data['lastPriceHalalas'] ?? null, 'status' => $data['status'] ?? null,
                    'brand_id' => $data['brandId'] ?? null, 'supplier_id' => $data['supplierId'] ?? null,
                ], fn ($v) => $v !== null));
                // Append a price-history point AFTER the item reflects the new
                // supplier, so the row is attributed to the right supplier (T11.12).
                if ($priceChanged) {
                    $this->recordPrice($item, $data['lastPriceHalalas']);
                }
                $this->bridge->syncItem($item);
            });

            return $this->ok(['id' => $item->id, 'name' => $item->name, 'brandId' => $item->brand_id, 'lastPriceHalalas' => $item->price]);
        });
    }

    /**
     * T11.12 — a price-history point carries the supplier it belongs to (from
     * the item's current supplier), so «مقارنة الأسعار» per supplier is possible.
     */
    private function recordPrice(SupplierItem $item, int $price): void
    {
        ProcurementItemPrice::create([
            'company_id' => $item->company_id,
            'item_id' => $item->id,
            'supplier_id' => $item->supplier_id,
            'supplier_name' => $item->supplier_id ? AsabSupplier::find($item->supplier_id)?->name : null,
            'price' => $price,
            'recorded_at' => now(),
        ]);
    }

    public function destroyItem(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $item = SupplierItem::where('company_id', $request->user()->company_id)->findOrFail($id);
            DB::transaction(function () use ($item) {
                // Deactivate (never hard-delete) the bridged mobile rows first.
                $this->bridge->deactivateItem($item);
                $item->delete();
            });

            return $this->noContent();
        });
    }

    public function priceHistory(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $rows = ProcurementItemPrice::where('company_id', $request->user()->company_id)->where('item_id', $id)
                ->orderByDesc('recorded_at')->get()
                ->map(fn ($p) => ['supplierId' => $p->supplier_id, 'supplierName' => $p->supplier_name, 'priceHalalas' => $p->price, 'recordedAt' => optional($p->recorded_at)->toIso8601String()])->all();

            return $this->listResponse($rows);
        });
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'name' => 'required|string|max:200', 'category' => 'sometimes|nullable|string|max:80',
                'contactName' => 'sometimes|nullable|string|max:200', 'contactPhone' => 'sometimes|nullable|string|max:32',
                'contactEmail' => 'sometimes|nullable|email', 'commercialReg' => 'sometimes|nullable|string|max:32',
                'paymentTerms' => 'sometimes|nullable|string|max:80', 'brandId' => 'sometimes|nullable|string',
                // doc aliases: `phone` -> contactPhone, `email` -> contactEmail.
                'phone' => 'sometimes|nullable|string|max:32', 'email' => 'sometimes|nullable|email',
                // internal (default) vs external (§10.2): a directly-registered
                // supplier is internal; the flag lets procurement mark one it only
                // deals with off-platform.
                'isExternal' => 'sometimes|boolean',
            ]);
            $sup = DB::transaction(function () use ($request, $data) {
                $sup = AsabSupplier::create([
                    'company_id' => $request->user()->company_id, 'brand_id' => $data['brandId'] ?? null, 'name' => $data['name'],
                    'category' => $data['category'] ?? null, 'contact_name' => $data['contactName'] ?? null,
                    'contact_phone' => $data['contactPhone'] ?? $data['phone'] ?? null,
                    'contact_email' => $data['contactEmail'] ?? $data['email'] ?? null,
                    'commercial_reg' => $data['commercialReg'] ?? null, 'payment_terms' => $data['paymentTerms'] ?? null, 'status' => 'active',
                    'is_external' => $data['isExternal'] ?? false,
                ]);
                // Provision the login-capable mobile-world supplier so the real order flow can use it.
                $this->bridge->provisionSupplier($sup);

                return $sup;
            });

            return $this->created(['id' => $sup->id, 'name' => $sup->name, 'category' => $sup->category, 'status' => $sup->status, 'isExternal' => $sup->is_external]);
        });
    }

    public function updateSupplier(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $sup = AsabSupplier::where('company_id', $request->user()->company_id)->findOrFail($id);
            $data = $request->validate([
                'name' => 'sometimes|string|max:200', 'category' => 'sometimes|nullable|string|max:80',
                'contactName' => 'sometimes|nullable|string|max:200', 'contactPhone' => 'sometimes|nullable|string|max:32',
                'contactEmail' => 'sometimes|nullable|email', 'paymentTerms' => 'sometimes|nullable|string|max:80',
            ]);
            DB::transaction(function () use ($sup, $data) {
                $sup->update(array_filter([
                    'name' => $data['name'] ?? null, 'category' => $data['category'] ?? null, 'contact_name' => $data['contactName'] ?? null,
                    'contact_phone' => $data['contactPhone'] ?? null, 'contact_email' => $data['contactEmail'] ?? null, 'payment_terms' => $data['paymentTerms'] ?? null,
                ], fn ($v) => $v !== null));
                $this->bridge->syncSupplier($sup);
            });

            return $this->ok(['id' => $sup->id, 'name' => $sup->name]);
        });
    }

    public function toggleSupplier(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $sup = AsabSupplier::where('company_id', $request->user()->company_id)->findOrFail($id);
            $new = $sup->status === 'active' ? 'inactive' : 'active';
            DB::transaction(function () use ($sup, $new) {
                $sup->update(['status' => $new]);
                $this->bridge->syncSupplierActive($sup);
            });

            return $this->ok(['id' => $sup->id, 'isActive' => $new === 'active', 'status' => $new]);
        });
    }

    public function rateSupplier(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $sup = AsabSupplier::where('company_id', $request->user()->company_id)->findOrFail($id);
            $data = $request->validate(['rating' => 'required|integer|min:1|max:5', 'comment' => 'sometimes|nullable|string|max:1000']);

            DB::transaction(function () use ($sup, $data, $request) {
                SupplierRating::create([
                    'company_id' => $request->user()->company_id, 'supplier_id' => $sup->id, 'rater_user_id' => $request->user()->id,
                    'rating' => $data['rating'], 'comment' => $data['comment'] ?? null, 'created_at' => now(),
                ]);
                $avg = (int) round(SupplierRating::where('supplier_id', $sup->id)->avg('rating') * 10); // 0-50 scale
                $sup->update(['rating' => $avg]);
            });

            return $this->created(['supplierId' => $sup->id, 'ratingAvg' => $sup->fresh()->rating]);
        });
    }

    /**
     * T12.6 — branch «طلب مورد جديد» rows awaiting procurement's decision.
     */
    public function supplierRequests(Request $request): JsonResponse
    {
        return $this->run(function () {
            $rows = SupplierRequest::where('status', SupplierRequest::STATUS_PENDING)
                ->orderByDesc('created_at')->limit(100)->get()
                ->map(fn (SupplierRequest $r) => [
                    'id' => $r->id, 'name' => $r->name, 'category' => $r->category,
                    'contactPhone' => $r->contact_phone, 'reason' => $r->reason, 'branchId' => $r->branch_id,
                    'status' => $r->status, 'statusLabel' => SupplierRequest::STATUS_LABELS[$r->status],
                    'requestedAt' => optional($r->created_at)->toIso8601String(),
                ])->all();

            return $this->listResponse($rows);
        });
    }

    /**
     * T12.6 — approve a branch supplier request: provision a real supplier from
     * it (T11 bridge) and mark the request approved so the «معتمد» chip flips.
     */
    public function approveSupplierRequest(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $req = SupplierRequest::where('status', SupplierRequest::STATUS_PENDING)->findOrFail($id);

            $sup = DB::transaction(function () use ($request, $req) {
                $sup = AsabSupplier::create([
                    'company_id' => $request->user()->company_id, 'name' => $req->name,
                    'category' => $req->category, 'contact_phone' => $req->contact_phone, 'status' => 'active',
                    // Branch-surfaced (§10.2): a supplier the branch deals with is
                    // external by origin, so «إرسال للمورد» routes it via WhatsApp.
                    'is_external' => true,
                ]);
                $this->bridge->provisionSupplier($sup);
                $req->update(['status' => SupplierRequest::STATUS_APPROVED, 'supplier_id' => $sup->id]);

                return $sup;
            });

            return $this->created([
                'id' => $sup->id, 'name' => $sup->name, 'status' => $sup->status, 'isExternal' => $sup->is_external,
                'requestId' => $req->id, 'requestStatus' => $req->status,
            ]);
        });
    }

    private function order(Request $request, string $id): Operation
    {
        return Operation::where('company_id', $request->user()->company_id)->where('module_key', 'purchases')
            ->where(fn ($q) => $q->where('id', $id)->orWhere('public_id', $id))->firstOrFail();
    }
}
