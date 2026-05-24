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
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $page = (int) $request->query('page', 1);

        $paginator = $this->service->paginateDisposals($manager->branch_id, max($page, 1));

        return $this->successResponse(
            [
                'data' => DisposalRequestListItemResource::collection($paginator->getCollection())->resolve(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'Disposal requests retrieved successfully',
        );
    }

    public function show(string $requestId): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $item = $this->service->disposalDetails($requestId, $manager->branch_id);

        if (! $item) {
            return $this->notFoundResponse('Disposal request not found');
        }

        return $this->successResponse(
            (new DisposalDetailsResource($item))->toArray(request()),
            'Disposal request details retrieved successfully',
        );
    }
}
