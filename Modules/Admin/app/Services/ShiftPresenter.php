<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Collection;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Shift;
use Modules\Admin\Support\ShiftEnums;
use Modules\Branch\Models\Branch;

/**
 * SRS ACC-6 — the one canonical shift shape, used by both the accountant and
 * branch surfaces. Emits the new keys (`cashierName`, `salesHalalas`,
 * `varianceHalalas`, status/type + Arabic labels) while keeping the old
 * (`supervisor`, `salesAmount`, `cashExpected`…) as deprecated aliases so no
 * screen breaks. Branch names are resolved in one query per collection.
 */
class ShiftPresenter
{
    /** Present a set of shifts, resolving branch names + cashier phones once (no N+1). */
    public function collection(Collection $shifts): array
    {
        $branchNames = Branch::whereIn('id', $shifts->pluck('branch_id')->filter()->unique())->pluck('name', 'id');
        $phones = Employee::whereIn('id', $shifts->pluck('cashier_employee_id')->filter()->unique())->pluck('phone', 'id');

        return $shifts->map(fn (Shift $s) => $this->present(
            $s, $branchNames[$s->branch_id] ?? null, $phones[$s->cashier_employee_id] ?? null,
        ))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Shift $s, ?string $branchName = null, ?string $cashierPhone = null): array
    {
        $phone = $cashierPhone;
        $whatsapp = $phone ? $this->whatsapp($phone) : null;

        return [
            'id' => $s->id,
            'branchId' => $s->branch_id,
            'branchName' => $branchName,
            'cashierEmployeeId' => $s->cashier_employee_id,
            'cashierName' => $s->cashier_name,
            'cashierPhone' => $phone,
            'whatsapp' => $whatsapp,
            'supervisor' => $s->supervisor_name,          // deprecated alias
            'supervisorName' => $s->supervisor_name,
            'shiftNo' => $s->shift_no,
            'shiftType' => $s->shift_type,
            'startedAt' => optional($s->started_at)->toIso8601String(),
            'endedAt' => optional($s->ended_at)->toIso8601String(),
            'status' => $s->status,
            'statusLabelAr' => ShiftEnums::statusLabelAr($s->status),
            'isLate' => $s->status === 'late',
            'lateBannerAr' => $s->status === 'late' ? ShiftEnums::LATE_BANNER_AR : null,
            'ordersCount' => $s->orders_count,
            'salesHalalas' => $s->sales_amount,
            'salesAmount' => $s->sales_amount,             // deprecated alias
            'openingFloatHalalas' => $s->opening_float,
            'cashExpectedHalalas' => $s->cash_expected,
            'cashExpected' => $s->cash_expected,           // deprecated alias
            'cashActualHalalas' => $s->cash_actual,
            'cashActual' => $s->cash_actual,               // deprecated alias
            'varianceHalalas' => $s->variance,
            'variance' => $s->variance,                    // deprecated alias
            'notes' => $s->notes,
        ];
    }

    /** Saudi mobile → `wa.me/9665XXXXXXXX` (best-effort normalisation). */
    private function whatsapp(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $digits = preg_replace('/^0+/', '', $digits);
        if (! str_starts_with($digits, '966')) {
            $digits = '966'.$digits;
        }

        return 'wa.me/'.$digits;
    }
}
