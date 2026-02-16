<?php

namespace Modules\Inventory\Http\Requests\DailyInventorySchedule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\DailyInventorySchedule;
use Modules\Purchase\Models\BranchItem;

class UpdateDailyInventoryScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $schedule = $this->route('schedule') ?? $this->route('id');
        if ($schedule instanceof DailyInventorySchedule) {
            $branchId = $schedule->branch_id;
        } else {
            $branchId = is_string($schedule) ? (DailyInventorySchedule::find($schedule)?->branch_id) : null;
        }
        $branchItemIds = $branchId
            ? BranchItem::where('branch_id', $branchId)->pluck('item_id')->toArray()
            : [];

        return [
            'start_date' => ['sometimes', 'date'],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'is_active' => ['sometimes', 'boolean'],
            'item_ids' => ['sometimes', 'array', 'min:1'],
            'item_ids.*' => [
                'required_with:item_ids',
                'uuid',
                'exists:items,id',
                $branchItemIds ? Rule::in($branchItemIds) : 'uuid',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'item_ids.*.in' => 'Each item must be assigned to the branch (branch item list).',
        ];
    }
}
