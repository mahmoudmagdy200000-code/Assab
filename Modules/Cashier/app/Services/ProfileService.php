<?php

namespace Modules\Cashier\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Cashier\Models\Cashier;

class ProfileService
{
    /**
     * Get cashier profile
     */
    public function getProfile(string $cashierId): Cashier
    {
        return Cashier::with([
            'branch',
            'creator',
            'shifts' => function ($query) {
                $query->whereDate('shift_date', '>=', now()->subDays(7))
                    ->orderBy('shift_date', 'desc');
            },
        ])->findOrFail($cashierId);
    }

    /**
     * Update cashier profile
     */
    public function updateProfile(Cashier $cashier, array $data): Cashier
    {
        $updateData = [
            'name' => $data['name'] ?? $cashier->name,
            'phone' => $data['phone'] ?? $cashier->phone,
        ];

        // Update password if provided
        if (! empty($data['new_password'])) {
            // Verify current password
            if (! Hash::check($data['current_password'], $cashier->password)) {
                throw new \Exception('Current password is incorrect');
            }

            $updateData['password'] = Hash::make($data['new_password']);
        }

        $cashier->update($updateData);

        return $cashier->fresh(['branch']);
    }

    /**
     * Upload profile image
     */
    public function uploadProfileImage(Cashier $cashier, UploadedFile $image): string
    {
        // Delete old image if exists
        if ($cashier->image) {
            Storage::disk('public')->delete($cashier->image);
        }

        // Store new image
        $filename = 'cashier_'.$cashier->id.'_'.time().'.'.$image->getClientOriginalExtension();
        $path = $image->storeAs('profiles/cashiers', $filename, 'public');

        // Update cashier record
        $cashier->update(['image' => $path]);

        return $path;
    }

    /**
     * Get cashier statistics
     */
    public function getCashierStatistics(string $cashierId): array
    {
        $cashier = Cashier::with('shifts')->findOrFail($cashierId);

        $completedShifts = $cashier->shifts()
            ->where('status', 'completed')
            ->get();

        return [
            'total_shifts' => $cashier->getTotalShiftsCount(),
            'completed_shifts' => $cashier->getCompletedShiftsCount(),
            'total_sales' => (float) $cashier->getTotalSales(),
            'total_variance' => (float) $cashier->getTotalVariance(),
            'average_sales_per_shift' => $completedShifts->count() > 0
                ? (float) $completedShifts->avg('total_sales')
                : 0,
            'shifts_this_month' => $cashier->shifts()
                ->whereMonth('shift_date', now()->month)
                ->whereYear('shift_date', now()->year)
                ->count(),
            'current_shift' => $cashier->getCurrentShift() ? [
                'id' => $cashier->getCurrentShift()->id,
                'shift_name' => $cashier->getCurrentShift()->shift->name,
                'status' => $cashier->getCurrentShift()->status->label(),
            ] : null,
        ];
    }
}
