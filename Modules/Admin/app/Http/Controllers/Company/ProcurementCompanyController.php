<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\ProcurementItemPrice;
use Modules\Admin\Models\SupplierItem;
use Modules\Admin\Models\SupplierRating;
use Modules\Admin\Services\OperationFactory;

/**
 * Company-scoped Procurement surface — NEW endpoints beyond the shared
 * ProcurementController (COMPANY_DASHBOARD_API_SPEC.md §5.5).
 */
class ProcurementCompanyController extends AsabController
{
    public function __construct(private readonly OperationFactory $factory) {}

    public function storeOrder(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'supplierId' => 'required|string', 'brandId' => 'sometimes|nullable|string', 'branchId' => 'sometimes|nullable|string',
                'items' => 'required|array|min:1', 'description' => 'sometimes|nullable|string',
                'urgency' => 'sometimes|in:normal,urgent', 'deliveryDate' => 'sometimes|nullable|date',
            ]);
            $total = collect($data['items'])->sum(fn ($i) => (int) ($i['totalHalalas'] ?? (($i['qty'] ?? 0) * ($i['unitPriceHalalas'] ?? 0))));
            $op = $this->factory->createFromUpload('purchases', [
                'supplierId' => $data['supplierId'], 'items' => $data['items'], 'description' => $data['description'] ?? null,
                'urgency' => $data['urgency'] ?? 'normal', 'deliveryDate' => $data['deliveryDate'] ?? null, 'origin' => 'procurement',
            ], $request->user(), $data['branchId'] ?? null, $total);

            return $this->created(['id' => $op->id, 'publicId' => $op->public_id, 'status' => $op->status, 'totalHalalas' => $total]);
        });
    }

    public function updateOrder(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $op = $this->order($request, $id);
            $data = $request->validate([
                'supplierId' => 'sometimes|string', 'items' => 'sometimes|array', 'description' => 'sometimes|nullable|string',
                'urgency' => 'sometimes|in:normal,urgent', 'deliveryDate' => 'sometimes|nullable|date',
            ]);
            $payload = array_merge($op->payload ?? [], array_filter([
                'supplierId' => $data['supplierId'] ?? null, 'items' => $data['items'] ?? null,
                'description' => $data['description'] ?? null, 'urgency' => $data['urgency'] ?? null, 'deliveryDate' => $data['deliveryDate'] ?? null,
            ], fn ($v) => $v !== null));
            $op->update(['payload' => $payload]);

            return $this->ok(['id' => $op->id, 'publicId' => $op->public_id, 'status' => $op->status]);
        });
    }

    public function destroyOrder(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $this->order($request, $id)->delete();

            return $this->noContent();
        });
    }

    public function grouped(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $groups = Operation::where('company_id', $request->user()->company_id)->where('module_key', 'purchases')
                ->where('status', Operation::STATUS_PENDING)->get()
                ->groupBy(fn (Operation $o) => $o->payload['supplierId'] ?? 'unknown')
                ->map(function ($ops, $supplierId) {
                    $supplier = AsabSupplier::find($supplierId);

                    return [
                        'groupId' => $supplierId, 'supplierId' => $supplierId, 'supplierName' => $supplier?->name ?? '—',
                        'orderCount' => $ops->count(), 'branches' => $ops->pluck('branch_id')->unique()->values()->all(),
                        'totalHalalas' => (int) $ops->sum('amount'), 'pending' => $ops->count(), 'orderIds' => $ops->pluck('id')->all(),
                    ];
                })->values()->all();

            return $this->listResponse($groups);
        });
    }

    public function sent(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $rows = Operation::where('company_id', $request->user()->company_id)->where('module_key', 'purchases')
                ->whereIn('status', [Operation::STATUS_APPROVED, Operation::STATUS_FINAL])
                ->whereNotNull('payload->sentAt')->orderByDesc('operation_date')->get()
                ->map(fn (Operation $o) => [
                    'id' => $o->id, 'publicId' => $o->public_id, 'supplierId' => $o->payload['supplierId'] ?? null,
                    'sentAt' => $o->payload['sentAt'] ?? null, 'totalHalalas' => $o->amount, 'inTransit' => true,
                ])->all();

            return $this->listResponse($rows);
        });
    }

    public function storeItem(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'name' => 'required|string|max:200', 'unit' => 'required|string|max:16',
                'lastPriceHalalas' => 'sometimes|integer|min:0', 'code' => 'sometimes|nullable|string|max:32',
            ]);
            $item = SupplierItem::create([
                'name' => $data['name'], 'unit' => $data['unit'], 'price' => $data['lastPriceHalalas'] ?? 0,
                'code' => $data['code'] ?? null, 'status' => 'active',
            ]);
            if (! empty($data['lastPriceHalalas'])) {
                ProcurementItemPrice::create(['company_id' => $request->user()->company_id, 'item_id' => $item->id, 'price' => $data['lastPriceHalalas'], 'recorded_at' => now()]);
            }

            return $this->created(['id' => $item->id, 'name' => $item->name, 'unit' => $item->unit, 'lastPriceHalalas' => $item->price]);
        });
    }

    public function updateItem(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $item = SupplierItem::findOrFail($id);
            $data = $request->validate(['name' => 'sometimes|string|max:200', 'unit' => 'sometimes|string|max:16', 'lastPriceHalalas' => 'sometimes|integer|min:0', 'status' => 'sometimes|string|max:16']);
            if (isset($data['lastPriceHalalas']) && $data['lastPriceHalalas'] !== (int) $item->price) {
                ProcurementItemPrice::create(['company_id' => $request->user()->company_id, 'item_id' => $item->id, 'price' => $data['lastPriceHalalas'], 'recorded_at' => now()]);
            }
            $item->update(array_filter([
                'name' => $data['name'] ?? null, 'unit' => $data['unit'] ?? null,
                'price' => $data['lastPriceHalalas'] ?? null, 'status' => $data['status'] ?? null,
            ], fn ($v) => $v !== null));

            return $this->ok(['id' => $item->id, 'name' => $item->name, 'lastPriceHalalas' => $item->price]);
        });
    }

    public function destroyItem(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            SupplierItem::findOrFail($id)->delete();

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
            ]);
            $sup = AsabSupplier::create([
                'company_id' => $request->user()->company_id, 'brand_id' => $data['brandId'] ?? null, 'name' => $data['name'],
                'category' => $data['category'] ?? null, 'contact_name' => $data['contactName'] ?? null,
                'contact_phone' => $data['contactPhone'] ?? null, 'contact_email' => $data['contactEmail'] ?? null,
                'commercial_reg' => $data['commercialReg'] ?? null, 'payment_terms' => $data['paymentTerms'] ?? null, 'status' => 'active',
            ]);

            return $this->created(['id' => $sup->id, 'name' => $sup->name, 'status' => $sup->status]);
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
            $sup->update(array_filter([
                'name' => $data['name'] ?? null, 'category' => $data['category'] ?? null, 'contact_name' => $data['contactName'] ?? null,
                'contact_phone' => $data['contactPhone'] ?? null, 'contact_email' => $data['contactEmail'] ?? null, 'payment_terms' => $data['paymentTerms'] ?? null,
            ], fn ($v) => $v !== null));

            return $this->ok(['id' => $sup->id, 'name' => $sup->name]);
        });
    }

    public function toggleSupplier(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $sup = AsabSupplier::where('company_id', $request->user()->company_id)->findOrFail($id);
            $new = $sup->status === 'active' ? 'inactive' : 'active';
            $sup->update(['status' => $new]);

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

    private function order(Request $request, string $id): Operation
    {
        return Operation::where('company_id', $request->user()->company_id)->where('module_key', 'purchases')
            ->where(fn ($q) => $q->where('id', $id)->orWhere('public_id', $id))->firstOrFail();
    }
}
