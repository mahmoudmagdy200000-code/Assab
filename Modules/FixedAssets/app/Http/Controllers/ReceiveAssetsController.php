<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Modules\FixedAssets\Http\Requests\ReceiveAssetsConfirmRequest;
use Modules\FixedAssets\Services\ReceiveAssetsService;
use Modules\FixedAssets\Transformers\ReceiveAssetsItemResource;

class ReceiveAssetsController extends BaseController
{
    public function __construct(
        private readonly ReceiveAssetsService $service,
    ) {}

    public function index(): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $list = $this->service->pendingList($manager->branch_id);

        return $this->successResponse(
            ['data' => ReceiveAssetsItemResource::collection($list)->resolve()],
            'Pending assets retrieved successfully',
        );
    }

    public function show(string $requestId): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();

        try {
            $pending = $this->service->findPending($requestId, $manager->branch_id);
        } catch (ModelNotFoundException $e) {
            return $this->notFoundResponse("Pending receipt not found: {$requestId}");
        }

        return $this->successResponse(
            (new ReceiveAssetsItemResource($pending))->resolve(),
            'Pending asset retrieved successfully',
        );
    }

    public function confirm(ReceiveAssetsConfirmRequest $request, string $requestId): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();

        try {
            $session = $this->service->confirmSingle(
                $requestId,
                $request->input('type'),
                [
                    'assignedZoneId' => $request->input('assignedZoneId'),
                    'assetTypeId' => $request->input('assetTypeId'),
                    'assetCount' => (int) $request->input('assetCount'),
                    'excellentCount' => (int) $request->input('excellentCount'),
                    'needAttentionCount' => (int) $request->input('needAttentionCount'),
                    'problemCount' => (int) $request->input('problemCount'),
                    'image' => $request->file('image'),
                ],
                $manager,
            );
        } catch (ModelNotFoundException $e) {
            return $this->notFoundResponse("Pending receipt not found: {$requestId}");
        }

        return $this->createdResponse(
            [
                'session_id' => (string) $session->id,
                'received_count' => $session->items->count(),
            ],
            'Asset received successfully',
        );
    }
}
