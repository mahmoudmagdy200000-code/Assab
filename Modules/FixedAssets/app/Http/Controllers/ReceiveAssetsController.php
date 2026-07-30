<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
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
            $incoming = $this->service->findIncoming($requestId, $manager->branch_id, recipientOnly: false);
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
            } catch (\Illuminate\Database\QueryException $e) {
                // QueryException extends RuntimeException — without this arm the
                // raw SQL (DB name, full INSERT with values) leaked to the client.
                report($e);
                $errors[] = ['assetId' => $assetId, 'message' => 'Could not save the receipt — check that the selected zone and asset type exist.'];
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
            'type' => $row['type'],
            'fromBranchId' => $row['from_branch_id'],
            'fromBranchName' => $row['from_branch_name'],
            'toBranchId' => $row['to_branch_id'],
            'assetName' => $row['asset_name'],
            'assetCode' => $row['asset_code'],
            'assetImage' => $row['asset_image'] ? asset('storage/'.$row['asset_image']) : '',
        ];
    }

    private function formatIncoming(array $incoming): array
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $viewerBranchId = (string) ($manager?->branch_id ?? '');

        if ($incoming['type'] === 'pending') {
            /** @var PendingReceipt $p */
            $p = $incoming['model'];

            return [
                'id' => (string) $p->id,
                'sourceType' => 'pending',
                'type' => 'from_finance',
                'viewerRole' => 'recipient',
                'fromBranchId' => '',
                'fromBranchName' => '',
                'toBranchId' => $viewerBranchId,
                'toBranchName' => '',
                'assetName' => (string) $p->asset_name,
                'assetCode' => (string) $p->asset_code,
                'assetImage' => $p->asset_image ? asset('storage/'.$p->asset_image) : '',
            ];
        }

        /** @var TransferDisposalItem $i */
        $i = $incoming['model'];
        $asset = $i->asset;
        $req = $i->request;

        $senderBranchId = (string) ($req?->branch_id ?? '');
        $recipientBranchId = (string) ($req?->recipient_branch_id ?? '');

        if ($viewerBranchId !== '' && $viewerBranchId === $recipientBranchId) {
            $role = 'recipient';
            $type = 'from_branch';
        } elseif ($viewerBranchId !== '' && $viewerBranchId === $senderBranchId) {
            $role = 'sender';
            $type = 'to_branch';
        } else {
            $role = $incoming['viewer_role'] ?? 'recipient';
            $type = $role === 'recipient' ? 'from_branch' : 'to_branch';
        }

        Log::info('ReceiveAssets formatIncoming', [
            'item_id' => (string) $i->id,
            'viewer_branch_id' => $viewerBranchId,
            'sender_branch_id' => $senderBranchId,
            'recipient_branch_id' => $recipientBranchId,
            'computed_role' => $role,
            'computed_type' => $type,
        ]);

        return [
            'id' => (string) $i->id,
            'sourceType' => 'transfer',
            'type' => $type,
            'viewerRole' => $role,
            'fromBranchId' => $senderBranchId,
            'fromBranchName' => (string) ($req?->branch?->name ?? ''),
            'toBranchId' => $recipientBranchId,
            'toBranchName' => (string) ($req?->recipientBranch?->name ?? ''),
            'assetName' => (string) ($asset?->name ?? ''),
            'assetCode' => (string) ($asset?->code ?? ''),
            'assetImage' => $asset?->image ? asset('storage/'.$asset->image) : '',
        ];
    }
}
