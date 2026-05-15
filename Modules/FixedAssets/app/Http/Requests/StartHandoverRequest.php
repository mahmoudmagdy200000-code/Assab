<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\FixedAssets\Enums\HandoverNotificationChannel;

class StartHandoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipientEmployeeId' => ['required', 'string'],
            'note' => ['required', 'string', 'min:1'],
            'includedAssetIds' => ['required', 'array', 'min:1'],
            'includedAssetIds.*' => ['required', 'string', 'distinct'],
            'sendInvitations' => ['required', 'array'],
            'sendInvitations.*' => ['required', 'string', 'in:'.implode(',', HandoverNotificationChannel::values())],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}
