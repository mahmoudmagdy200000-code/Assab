<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Modules\FixedAssets\Http\Requests\ReceiveAssetsConfirmRequest;
use Modules\FixedAssets\Models\PendingReceipt;
use Modules\FixedAssets\Models\TransferDisposalItem;
use Modules\FixedAssets\Services\ReceiveAssetsService;

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
            ['data' => $list->map(fn ($row) => $this->formatRow($row))->all()],
            'Pending assets retrieved successfully',
        );
    }

    public function show(string $requestId): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();

        try {
            $incoming = $this->service->findIncoming($requestId, $manager->branch_id);
        } catch (ModelNotFoundException $e) {
            return $this->notFoundResponse("Incoming asset not found: {$requestId}");
        }

        return $this->successResponse(
            $this->formatIncoming($incoming),
            'Incoming asset retrieved successfully',
        );
    }

    public function confirm(ReceiveAssetsConfirmRequest $request, string $requestId): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();

        try {
            $result = $this->service->confirmSingle(
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
            return $this->notFoundResponse("Incoming asset not found: {$requestId}");
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->createdResponse($result, 'Asset received successfully');
    }

    private function formatRow(array $row): array
    {
        return [
            'id' => $row['id'],
            'sourceType' => $row['source_type'],
            'assetName' => $row['asset_name'],
            'assetCode' => $row['asset_code'],
            'assetImage' => $row['asset_image'] ? asset('storage/'.$row['asset_image']) : '',
        ];
    }

    private function formatIncoming(array $incoming): array
    {
        if ($incoming['type'] === 'pending') {
            /** @var PendingReceipt $p */
            $p = $incoming['model'];

            return [
                'id' => (string) $p->id,
                'sourceType' => 'pending',
                'assetName' => (string) $p->asset_name,
                'assetCode' => (string) $p->asset_code,
                'assetImage' => $p->asset_image ? asset('storage/'.$p->asset_image) : '',
            ];
        }

        /** @var TransferDisposalItem $i */
        $i = $incoming['model'];
        $asset = $i->asset;

        return [
            'id' => (string) $i->id,
            'sourceType' => 'transfer',
            'assetName' => (string) ($asset?->name ?? ''),
            'assetCode' => (string) ($asset?->code ?? ''),
            'assetImage' => $asset?->image ? asset('storage/'.$asset->image) : '',
        ];
    }
}
