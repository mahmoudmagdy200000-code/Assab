<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\FixedAssets\Models\Handover;
use Modules\FixedAssets\Services\HandoverService;
use Modules\FixedAssets\Services\HandoverSummaryService;
use RuntimeException;
use Throwable;

class HandoverSignatureController extends BaseController
{
    public function __construct(
        private readonly HandoverService $handoverService,
        private readonly HandoverSummaryService $summaryService,
    ) {}

    public function state(string $sessionId): JsonResponse
    {
        $handover = Handover::query()->findOrFail($sessionId);

        return $this->successResponse(
            $this->summaryService->signatureStatePayload($handover),
            'Signature state retrieved successfully',
        );
    }

    public function receiver(string $sessionId): JsonResponse
    {
        $handover = Handover::query()->findOrFail($sessionId);

        $actor = auth()->user();

        try {
            $this->handoverService->signReceiver($handover, $actor);
        } catch (RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->handleException($e, 'sign as receiver');
        }

        return $this->successResponse(
            $this->summaryService->signatureStatePayload($handover->fresh()),
            'Receiver signature recorded successfully',
        );
    }

    public function sender(string $sessionId): JsonResponse
    {
        $handover = Handover::query()->findOrFail($sessionId);

        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();

        try {
            $this->handoverService->signSender($handover, $manager);
        } catch (RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->handleException($e, 'sign as sender');
        }

        return $this->successResponse(
            $this->summaryService->signatureStatePayload($handover->fresh()),
            'Sender signature recorded successfully',
        );
    }
}
