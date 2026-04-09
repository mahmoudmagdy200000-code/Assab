<?php

namespace Modules\Cashier\Http\Controllers;



use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Cashier\Services\ProfileService;
use Modules\Cashier\Http\Requests\UpdateProfileRequest;
use Modules\Cashier\Http\Requests\UploadImageRequest;
use Modules\Cashier\Transformers\CashierDetailResource;
use App\Http\Controllers\BaseController;
use Modules\Cashier\Models\Cashier;

class ProfileController extends BaseController
{
    public function __construct(
        private ProfileService $profileService
    ) {
        $this->middleware('auth:sanctum');
        $this->middleware('cashier');
    }

    /**
     * Display cashier profile
     */
    public function show(): JsonResponse
    {
        $cashier = auth()->user();

        $profileData = $this->profileService->getProfile($cashier->id);

        return  $this->successResponse(
            new CashierDetailResource($profileData),
            'Profile retrieved successfully'
        );
    }

    /**
     * Update cashier profile
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $cashier = auth()->user();

        $data = $request->validated();
        $updatedCashier = $this->profileService->updateProfile($cashier, $data);

        return  $this->successResponse(
            new CashierDetailResource($updatedCashier),
            'Profile updated successfully'
        );
    }

    /**
     * Upload profile image
     */
    public function uploadImage(UploadImageRequest $request): JsonResponse
    {
        $cashier = auth()->user();

        $imagePath = $this->profileService->uploadProfileImage(
            cashier: $cashier,
            image: $request->file('image')
        );

        return  $this->successResponse([
            'image_url' => asset('storage/' . $imagePath),
        ], 'Profile image uploaded successfully');
    }

    /**
     * Get cashier statistics
     */
    public function statistics()
    {
        $cashier = auth()->user();

        $stats = $this->profileService->getCashierStatistics($cashier->id);

        return $this->successResponse($stats, 'Statistics retrieved successfully');
    }

    /**
     * Get branch info for the authenticated cashier.
     */
    public function branchInfo(): JsonResponse
    {
        /** @var Cashier $cashier */
        $cashier = auth()->user();
        $cashier->load(['branch.branchManager']);

        $branch = $cashier->branch;
        if (!$branch) {
            return $this->errorResponse('Cashier is not assigned to any branch', 400);
        }

        $manager = $branch->branchManager;

        $googleMapsUrl = null;
        if ($branch->lat && $branch->lng) {
            $googleMapsUrl = 'https://www.google.com/maps?q=' . (float) $branch->lat . ',' . (float) $branch->lng;
        }

        return $this->successResponse([
            'id' => $branch->id,
            'name' => $branch->name,
            'image' => $branch->image ? asset('storage/' . $branch->image) : null,
            'location' => $branch->location ?? null,
            'lat' => $branch->lat ? (float) $branch->lat : null,
            'lng' => $branch->lng ? (float) $branch->lng : null,
            'opening_hours' => $branch->opening_hours?->format('h:i A'),
            'closing_hours' => $branch->closing_hours?->format('h:i A'),
            'google_maps_url' => $googleMapsUrl,
            'branch_manager' => $manager ? [
                'id' => $manager->id,
                'name' => $manager->name,
                'image' => $manager->image ? asset('storage/' . $manager->image) : null,
            ] : null,
        ], 'Branch info retrieved successfully');
    }

    /**
     * Get the authenticated cashier's own info with current shift details.
     */
    public function myInfo(): JsonResponse
    {
        /** @var Cashier $cashier */
        $cashier = auth()->user();
        $cashier->load(['branch', 'creator']);

        $currentShift = $cashier->getCurrentShift();
        $currentShift?->load('shift');
        $nextShift = $cashier->getNextShift();
        $nextShift?->load('shift');

        $shiftDetails = null;
        $activeShift = $currentShift ?? $nextShift;
        if ($activeShift && $activeShift->shift) {
            $shift = $activeShift->shift;
            $shiftDetails = [
                'id' => $activeShift->id,
                'store_branch' => $cashier->branch?->name,
                'shift_name' => $shift->name,
                'start_time' => $shift->start_time,
                'end_time' => $shift->end_time,
                'shift_date' => $activeShift->shift_date?->format('Y-m-d'),
                'status' => $activeShift->status->value,
                'status_label' => $activeShift->status->label(),
                'hands_over_to' => $activeShift->next_cashier_id ? [
                    'id' => $activeShift->nextCashier?->id,
                    'name' => $activeShift->nextCashier?->name,
                ] : ($cashier->creator ? [
                    'id' => $cashier->creator->id,
                    'name' => $cashier->creator->name,
                    'role' => 'Branch Manager',
                ] : null),
            ];
        }

        return $this->successResponse([
            'id' => $cashier->id,
            'name' => $cashier->name,
            'email' => $cashier->email,
            'phone' => $cashier->phone,
            'image' => $cashier->image_url,
            'role' => 'Cashier',
            'status' => $cashier->status,
            'status_label' => $cashier->status_label,
            'activity' => $currentShift ? 'In Progress' : 'Not Started',
            'shift_details' => $shiftDetails,
            'statistics' => [
                'total_shifts' => $cashier->getTotalShiftsCount(),
                'completed_shifts' => $cashier->getCompletedShiftsCount(),
                'total_sales' => (float) $cashier->getTotalSales(),
            ],
        ], 'Cashier info retrieved successfully');
    }
}
