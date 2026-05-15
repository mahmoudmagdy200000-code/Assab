<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\FixedAssets\Http\Requests\StartHandoverRequest;
use Modules\FixedAssets\Models\Handover;
use Modules\FixedAssets\Services\HandoverDetailsService;
use Modules\FixedAssets\Services\HandoverService;
use RuntimeException;
use Throwable;

class HandoverController extends BaseController
{
    public function __construct(
        private readonly HandoverService $handoverService,
        private readonly HandoverDetailsService $detailsService,
    ) {}

    public function start(StartHandoverRequest $request): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();

        try {
            $handover = $this->handoverService->start(
                $manager,
                (string) $request->input('recipientEmployeeId'),
                (string) $request->input('note'),
                (array) $request->input('includedAssetIds', []),
                (array) $request->input('sendInvitations', []),
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->handleException($e, 'start handover');
        }

        return $this->createdResponse(
            [
                'sessionId' => (string) $handover->id,
                'sessionCode' => (string) $handover->session_code,
            ],
            'Handover started successfully',
        );
    }

    public function show(string $handoverId): JsonResponse
    {
        /** @var Handover $handover */
        $handover = Handover::query()->findOrFail($handoverId);

        return $this->successResponse(
            $this->detailsService->payload($handover),
            'Handover details retrieved successfully',
        );
    }
}
