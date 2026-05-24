<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\FixedAssets\Enums\ReceiveType;

class ReceiveAssetsConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:'.implode(',', ReceiveType::values())],
            'assignedZoneId' => ['required', 'string'],
            'assetTypeId' => ['required', 'string'],
            'assetCount' => ['required', 'integer', 'min:0'],
            'excellentCount' => ['required', 'integer', 'min:0'],
            'needAttentionCount' => ['required', 'integer', 'min:0'],
            'problemCount' => ['required', 'integer', 'min:0'],
            'image' => ['required', 'file', 'image'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $assetCount = (int) $this->input('assetCount', -1);
            $excellent = (int) $this->input('excellentCount', -1);
            $needAttention = (int) $this->input('needAttentionCount', -1);
            $problem = (int) $this->input('problemCount', -1);

            if ($assetCount >= 0 && $excellent >= 0 && $needAttention >= 0 && $problem >= 0) {
                if ($excellent + $needAttention + $problem !== $assetCount) {
                    $v->errors()->add(
                        'assetCount',
                        'Sum of excellentCount + needAttentionCount + problemCount must equal assetCount.'
                    );
                }
            }
        });
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
