<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $action = $this->input('action');
        
        $rules = [
            'action' => ['required', 'string', 'in:approve_all,partial_approve,reject_all'],
            'ready_time' => ['required_unless:action,reject_all', 'string', 'in:3_minutes,1_hour,2_hours,3_hours,more_than_3_hours'],
        ];

        if ($action === 'partial_approve') {
            $rules = array_merge($rules, [
                'items' => ['required', 'array', 'min:1'],
                'items.*.item_id' => ['required', 'uuid'],
                'items.*.new_quantity' => ['required', 'numeric', 'min:0'],
            ]);
        }

        if ($action === 'reject_all') {
            $rules['reason'] = ['required', 'string', 'max:1000'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'action.required' => 'Please select an action.',
            'ready_time.required_unless' => 'Please specify when the order will be ready.',
            'items.required' => 'Items are required for partial approval.',
            'reason.required' => 'Please provide a reason for rejection.',
        ];
    }
}

