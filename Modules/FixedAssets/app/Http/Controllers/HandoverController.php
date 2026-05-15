<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\FixedAssets\Http\Requests\StartHandoverRequest;
use Modules\FixedAssets\Models\Handover;
use Modules\FixedAssets\Services\HandoverDetailsService;
use Modules\FixedAssets\Services\HandoverService;
use Modules\FixedAssets\Transformers\HandoverRequestListItemResource;
use RuntimeException;
use Throwable;

class HandoverController extends BaseController
{
    public function __construct(
        private readonly HandoverService $handoverService,
        private readonly HandoverDetailsService $detailsService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();

        $page = max((int) $request->query('page', 1), 1);
        $perPage = (int) $request->query('per_page', 15);
        $perPage = $perPage > 0 ? min($perPage, 100) : 15;

        $paginator = $this->handoverService->paginateRequestsForManager($manager, $page, $perPage);

        return $this->successResponse(
            [
                'data' => HandoverRequestListItemResource::collection($paginator->getCollection())->resolve($request),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'Handover requests retrieved successfully',
        );
    }

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
