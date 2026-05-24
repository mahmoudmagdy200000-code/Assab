<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\BrandOwner\Services\BrandOwnerControlPanelService;

class BrandOwnerControlPanelController extends BaseController
{
    public function __construct(
        private readonly BrandOwnerControlPanelService $service,
    ) {}

    public function index(): JsonResponse
    {
        return $this->successResponse(
            $this->service->controlPanel(),
            'Control panel retrieved successfully',
        );
    }
}
