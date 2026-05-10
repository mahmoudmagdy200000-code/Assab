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
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();

        $payload = $request->validated();

        $files = (array) $request->file('assets', []);
        foreach (array_keys($payload['assets'] ?? []) as $i) {
            $bag = $files[$i] ?? null;
            if (! is_array($bag)) {
                continue;
            }
            if (isset($bag['documentationPhotos'])) {
                $payload['assets'][$i]['documentationPhotos'] = $bag['documentationPhotos'];
            }
            if (isset($bag['visualEvidence'])) {
                $payload['assets'][$i]['visualEvidence'] = $bag['visualEvidence'];
            }
        }

        $req = $this->service->create($payload, $manager);

        return $this->createdResponse(
            [
                'id' => (string) $req->id,
                'kind' => $req->kind?->value ?? '',
                'status' => $req->status?->value ?? '',
            ],
            'Request submitted successfully',
        );
    }
}
