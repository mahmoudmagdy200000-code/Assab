<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\FixedAssets\Http\Requests\ApproveZoneRequest;
use Modules\FixedAssets\Models\Handover;
use Modules\FixedAssets\Services\HandoverDetailsService;
use Modules\FixedAssets\Services\HandoverIncludedZonesService;
use Modules\FixedAssets\Services\HandoverPreviewService;
use Modules\FixedAssets\Services\HandoverService;
use Modules\FixedAssets\Services\HandoverSummaryService;
use RuntimeException;
use Throwable;

class HandoverSessionController extends BaseController
{
    public function __construct(
        private readonly HandoverService $handoverService,
        private readonly HandoverIncludedZonesService $zonesService,
        private readonly HandoverPreviewService $previewService,
        private readonly HandoverSummaryService $summaryService,
        private readonly HandoverDetailsService $detailsService,
    ) {}

    public function joinDetails(string $sessionId): JsonResponse
    {
        $handover = Handover::query()->findOrFail($sessionId);

        return $this->successResponse(
            $this->detailsService->joinPayload($handover),
            'Join session details retrieved successfully',
        );
    }

    public function join(string $sessionId): JsonResponse
    {
        $handover = Handover::query()->findOrFail($sessionId);

        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();
        $this->handoverService->recordJoin($handover, $manager);

        return $this->successResponse(
            ['sessionId' => (string) $handover->id],
            'Joined handover session successfully',
        );
    }

    public function details(string $sessionId): JsonResponse
    {
        $handover = Handover::query()->findOrFail($sessionId);

        return $this->successResponse(
            $this->zonesService->sessionDetails($handover),
            'Session details retrieved successfully',
        );
    }

    public function approveZone(ApproveZoneRequest $request, string $sessionId): JsonResponse
    {
        $handover = Handover::query()->findOrFail($sessionId);

        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();

        $items = (array) $request->input('items', []);
        $files = (array) $request->file('items', []);

        try {
            $this->handoverService->approveZone(
                $handover,
                (string) $request->input('zoneId'),
                $items,
                $files,
                $manager,
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->handleException($e, 'approve zone');
        }

        return $this->successResponse(
            $this->zonesService->activeSessionZones($handover->fresh(['items'])),
            'Zone approved successfully',
        );
    }

    public function approveAll(string $sessionId): JsonResponse
    {
        $handover = Handover::query()->findOrFail($sessionId);

        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();

        try {
            $this->handoverService->approveAll($handover, $manager);
        } catch (RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->handleException($e, 'approve all zones');
        }

        return $this->successResponse(
            $this->zonesService->activeSessionZones($handover->fresh(['items'])),
            'All zones approved successfully',
        );
    }

    public function preview(string $sessionId): JsonResponse
    {
        $handover = Handover::query()->findOrFail($sessionId);

        $viewer = auth()->user();
        $viewerId = $viewer ? (string) $viewer->getKey() : null;

        return $this->successResponse(
            $this->previewService->payload($handover, $viewerId),
            'Session preview retrieved successfully',
        );
    }

    public function summary(string $sessionId): JsonResponse
    {
        $handover = Handover::query()->findOrFail($sessionId);

        return $this->successResponse(
            $this->summaryService->payload($handover),
            'Session summary retrieved successfully',
        );
    }

    public function complete(string $sessionId): JsonResponse
    {
        $handover = Handover::query()->findOrFail($sessionId);

        /** @var \Modules\BranchManagers\Models\BranchManager $actor */
        $actor = auth()->user();

        try {
            $this->handoverService->complete($handover, $actor);
        } catch (RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->handleException($e, 'complete handover');
        }

        return $this->successResponse(
            $this->summaryService->payload($handover->fresh()),
            'Handover completed successfully',
        );
    }
}
