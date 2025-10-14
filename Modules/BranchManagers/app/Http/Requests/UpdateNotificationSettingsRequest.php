<?php

namespace Modules\BranchManagers\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shift_variance_alerts' => 'sometimes|boolean',
            'daily_inventory_reminders' => 'sometimes|boolean',
            'approved_aggregators_only' => 'sometimes|boolean',
            'asset_transfers_only' => 'sometimes|boolean',
            'allow_split_shift_handover' => 'sometimes|boolean',
        ];
    }
}
