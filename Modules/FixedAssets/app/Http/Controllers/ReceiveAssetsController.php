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
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse("Incoming asset not found: {$requestId}");
        }

        return $this->successResponse(
            $this->formatIncoming($incoming),
            'Incoming asset retrieved successfully',
        );
    }

    public function confirm(ReceiveAssetsConfirmRequest $request, ?string $requestId = null): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();

        $type = $request->input('type');
        $items = (array) $request->input('items', []);
        $files = (array) $request->file('items', []);

        $results = [];
        $errors = [];

        foreach ($items as $key => $item) {
            $assetId = (string) ($item['assetId'] ?? $key);
            $payload = [
                'assignedZoneId' => $item['assignedZoneId'] ?? null,
                'assetTypeId' => $item['assetTypeId'] ?? null,
                'assetCount' => (int) ($item['assetCount'] ?? 0),
                'excellentCount' => (int) ($item['excellentCount'] ?? 0),
                'needAttentionCount' => (int) ($item['needAttentionCount'] ?? 0),
                'problemCount' => (int) ($item['problemCount'] ?? 0),
                'image' => $files[$key]['image'] ?? null,
            ];

            try {
                $results[] = [
                    'assetId' => $assetId,
                ] + $this->service->confirmSingle($assetId, $type, $payload, $manager);
            } catch (ModelNotFoundException $e) {
                $errors[] = ['assetId' => $assetId, 'message' => "Incoming asset not found: {$assetId}"];
            } catch (\RuntimeException $e) {
                $errors[] = ['assetId' => $assetId, 'message' => $e->getMessage()];
            }
        }

        if ($errors !== [] && $results === []) {
            return $this->errorResponse('Failed to receive assets', 422, $errors);
        }

        return $this->createdResponse([
            'received' => $results,
            'failed' => $errors,
        ], 'Assets received successfully');
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
