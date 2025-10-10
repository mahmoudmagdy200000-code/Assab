<?php

namespace Modules\Cashier\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Cashier\Services\CashierService;
use Modules\BranchManagers\Traits\ApiResponseTrait;
use Modules\Cashier\Transformers\CashierResource;
use Illuminate\Http\JsonResponse;

class CashierController extends Controller
{
 use ApiResponseTrait;
    protected $cashierService;

    public function __construct(CashierService $cashierService)
    {

        $this->cashierService = $cashierService;
    }


    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $cashiers = $this->cashierService->index($request);

        return $this->successResponse(
            'Cashiers retrieved successfully',
            ['cashiers' => $cashiers]
        );
    }

    /**
     * Create Cashiers account
     */
    public function store(Request $request): JsonResponse
    {
        $cashier = $this->cashierService->store($request);

        return $this->successResponse(
            'Cashier created successfully',
            ['cashier' => new CashierResource($cashier)]
        );
    }
}
