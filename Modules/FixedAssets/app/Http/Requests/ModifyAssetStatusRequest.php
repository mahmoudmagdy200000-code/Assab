<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Enums\ModificationDoneAction;
use Modules\FixedAssets\Enums\ModificationNextAction;

class ModifyAssetStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'string', 'uuid', 'exists:fixed_assets,id'],
            'new_status' => ['required', 'string', 'in:'.implode(',', AssetStatus::values())],
            'reason' => ['required', 'string', 'min:1'],
            'attachment' => ['required', 'file', 'image', 'max:8192'],
            'done_actions' => ['required', 'array', 'min:1'],
            'done_actions.*' => ['string', 'in:'.implode(',', ModificationDoneAction::values())],
            'next_action' => ['required', 'string', 'in:'.implode(',', ModificationNextAction::values())],
            'approval_request_owner_note' => ['required', 'string', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'asset_id.required' => 'asset_id is required and must not be empty.',
            'asset_id.uuid' => 'asset_id must be a valid UUID.',
            'asset_id.exists' => 'asset_id does not match any existing asset.',
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
