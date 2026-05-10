<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\FixedAssets\Http\Requests\ModifyAssetStatusRequest;
use Modules\FixedAssets\Services\ModificationRequestService;
use Modules\FixedAssets\Transformers\ModificationDetailsResource;
use Modules\FixedAssets\Transformers\ModificationRequestListItemResource;

class ModificationRequestController extends BaseController
{
    public function __construct(
        private readonly ModificationRequestService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $page = (int) $request->query('page', 1);

        $paginator = $this->service->paginate($manager->branch_id, max($page, 1));

        return $this->successResponse(
            [
                'data' => ModificationRequestListItemResource::collection($paginator->getCollection())->resolve(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'Modification requests retrieved successfully',
        );
    }

    public function store(ModifyAssetStatusRequest $request): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();

        $payload = $request->validated();
        $attachment = $request->file('attachment');

        $modification = $this->service->create($payload, $attachment, $manager);

        return $this->createdResponse(
            [
                'id' => (string) $modification->id,
                'status' => $modification->status?->value ?? '',
            ],
            'Modification request submitted',
        );
    }

    public function show(string $requestId): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $modification = $this->service->findForBranch($requestId, $manager->branch_id);

        if (! $modification) {
            return $this->notFoundResponse('Modification request not found');
        }

        return $this->successResponse(
            (new ModificationDetailsResource($modification))->toArray(request()),
            'Modification details retrieved successfully',
        );
    }
}
