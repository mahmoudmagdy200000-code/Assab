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
        $manager = auth()->user();
        $page = (int) $request->query('page', 1);

        $paginator = $this->service->paginateTransfers($manager->branch_id, max($page, 1));

        return response()->json([
            'data' => TransferRequestListItemResource::collection($paginator->getCollection())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(string $requestId): JsonResponse
    {
        $manager = auth()->user();
        $req = $this->service->transferDetails($requestId, $manager->branch_id);

        if (! $req) {
            return response()->json([
                'success' => false,
                'message' => 'Transfer request not found.',
            ], 404);
        }

        return response()->json(
            (new TransferDetailsResource($req))->toArray(request())
        );
    }

    public function approve(string $requestId): JsonResponse
    {
        $manager = auth()->user();
        $req = $this->service->approveTransfer($requestId, $manager->branch_id, $manager);

        return response()->json([
            'success' => true,
            'message' => 'Transfer approved successfully',
            'data' => [
                'id' => (string) $req->id,
                'status' => $req->status?->value ?? '',
            ],
        ]);
    }
}
