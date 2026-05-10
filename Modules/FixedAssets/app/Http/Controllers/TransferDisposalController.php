<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\FixedAssets\Http\Requests\TransferOrDisposalRequest;
use Modules\FixedAssets\Services\TransferDisposalService;

class TransferDisposalController extends BaseController
{
    public function __construct(
        private readonly TransferDisposalService $service,
    ) {}

    public function store(TransferOrDisposalRequest $request): JsonResponse
    {
        $manager = auth()->user();

        $payload = $request->validated();

        // Merge file uploads into per-asset arrays.
        $files = $request->file('assets', []);
        foreach ($payload['assets'] ?? [] as $i => $asset) {
            if (isset($files[$i]['documentationPhotos'])) {
                $payload['assets'][$i]['documentationPhotos'] = $files[$i]['documentationPhotos'];
            }
            if (isset($files[$i]['visualEvidence'])) {
                $payload['assets'][$i]['visualEvidence'] = $files[$i]['visualEvidence'];
            }
        }

        $req = $this->service->create($payload, $manager);

        return response()->json([
            'success' => true,
            'message' => 'Request submitted successfully',
            'data' => [
                'id' => (string) $req->id,
                'kind' => $req->kind?->value ?? '',
                'status' => $req->status?->value ?? '',
            ],
        ], 201);
    }
}
