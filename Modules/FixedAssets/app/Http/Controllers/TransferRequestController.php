<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\FixedAssets\Services\TransferDisposalService;
use Modules\FixedAssets\Transformers\TransferDetailsResource;
use Modules\FixedAssets\Transformers\TransferRequestListItemResource;

class TransferRequestController extends BaseController
{
    public function __construct(
        private readonly TransferDisposalService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $page = (int) $request->query('page', 1);

        $paginator = $this->service->paginateTransfers($manager->branch_id, max($page, 1));

        return $this->successResponse(
            [
                'data' => TransferRequestListItemResource::collection($paginator->getCollection())->resolve(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'Transfer requests retrieved successfully',
        );
    }

    public function show(string $requestId): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $req = $this->service->transferDetails($requestId, $manager->branch_id);

        if (! $req) {
            return $this->notFoundResponse('Transfer request not found');
        }

        return $this->successResponse(
            (new TransferDetailsResource($req))->toArray(request()),
            'Transfer request details retrieved successfully',
        );
    }

    public function approve(string $requestId): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $req = $this->service->approveTransfer($requestId, $manager->branch_id, $manager);

        return $this->successResponse(
            [
                'id' => (string) $req->id,
                'status' => $req->status?->value ?? '',
            ],
            'Transfer approved successfully',
        );
    }

    public function approveItemDest(string $itemId): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();

        try {
            $item = $this->service->approveTransferItemDest($itemId, $manager);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse(
            ['id' => (string) $item->id, 'status' => $item->status?->value ?? ''],
            'Transfer item approved by destination',
        );
    }

    public function rejectItemDest(Request $request, string $itemId): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $reason = (string) $request->input('reason', '');
        if (strlen($reason) < 3) {
            return $this->validationErrorResponse(['reason' => ['Reason is required (min 3 characters).']]);
        }

        try {
            $item = $this->service->rejectTransferItemDest($itemId, $manager, $reason);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse(
            ['id' => (string) $item->id, 'status' => $item->status?->value ?? ''],
            'Transfer item rejected by destination',
        );
    }
}
