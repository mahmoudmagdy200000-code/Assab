<?php

namespace Modules\Inventory\Http\Requests\DailyInventorySchedule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Purchase\Models\BranchItem;

class StoreDailyInventoryScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = $this->input('branch_id');
        $branchItemIds = $branchId
            ? BranchItem::where('branch_id', $branchId)->pluck('item_id')->toArray()
            : [];

        return [
            'branch_id' => ['required', 'uuid', 'exists:branches,id'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'is_active' => ['sometimes', 'boolean'],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => [
                'required',
                'uuid',
                'exists:items,id',
                Rule::in($branchItemIds),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'item_ids.*.in' => 'Each item must be assigned to the selected branch (branch item list).',
        ];
    }
}
