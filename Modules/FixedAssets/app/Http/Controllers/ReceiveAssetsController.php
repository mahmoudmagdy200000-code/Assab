<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
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

    public function confirm(ReceiveAssetsConfirmRequest $request): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();

        $items = $request->input('items', []);
        $files = $request->file('items', []);

        foreach ($files as $key => $fileSet) {
            if (isset($fileSet['image'])) {
                $items[$key]['image'] = $fileSet['image'];
            }
        }

        $session = $this->service->confirm(
            $request->input('type'),
            $items,
            $manager,
        );

        return $this->createdResponse(
            [
                'session_id' => (string) $session->id,
                'received_count' => $session->items->count(),
            ],
            'Assets received successfully',
        );
    }
}
