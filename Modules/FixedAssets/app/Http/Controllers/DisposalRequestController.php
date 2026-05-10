<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\FixedAssets\Services\TransferDisposalService;
use Modules\FixedAssets\Transformers\DisposalDetailsResource;
use Modules\FixedAssets\Transformers\DisposalRequestListItemResource;

class DisposalRequestController extends BaseController
{
    public function __construct(
        private readonly TransferDisposalService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $manager = auth()->user();
        $page = (int) $request->query('page', 1);

        $paginator = $this->service->paginateDisposals($manager->branch_id, max($page, 1));

        return response()->json([
            'data' => DisposalRequestListItemResource::collection($paginator->getCollection())->resolve(),
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
        $req = $this->service->disposalDetails($requestId, $manager->branch_id);

        if (! $req) {
            return response()->json([
                'success' => false,
                'message' => 'Disposal request not found.',
            ], 404);
        }

        return response()->json(
            (new DisposalDetailsResource($req))->toArray(request())
        );
    }
}
