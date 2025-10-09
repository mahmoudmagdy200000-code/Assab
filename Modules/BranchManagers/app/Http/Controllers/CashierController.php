<?php

namespace Modules\BranchManagers\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\BranchManagers\Services\CashierService;
use Modules\BranchManagers\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
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

        // نحول الـ resource collection إلى array صافي
        $formatted = CashierResource::collection($cashiers)->response()->getData(true);

        return $this->successResponse(
            'Cashiers retrieved successfully',
            ['cashiers' => $formatted['data']] // ناخد الـ data فقط
        );
    }
}
