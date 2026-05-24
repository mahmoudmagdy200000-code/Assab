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
            'items' => ['required', 'array', 'min:1'],
            'items.*.assetId' => ['required', 'string'],
            'items.*.assignedZoneId' => ['required', 'string'],
            'items.*.assetTypeId' => ['required', 'string'],
            'items.*.assetCount' => ['required', 'integer', 'min:0'],
            'items.*.excellentCount' => ['required', 'integer', 'min:0'],
            'items.*.needAttentionCount' => ['required', 'integer', 'min:0'],
            'items.*.problemCount' => ['required', 'integer', 'min:0'],
            'items.*.image' => ['required', 'file', 'image'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $items = (array) $this->input('items', []);
            foreach ($items as $key => $item) {
                $assetCount = (int) ($item['assetCount'] ?? -1);
                $excellent = (int) ($item['excellentCount'] ?? -1);
                $needAttention = (int) ($item['needAttentionCount'] ?? -1);
                $problem = (int) ($item['problemCount'] ?? -1);

                if ($assetCount < 0 || $excellent < 0 || $needAttention < 0 || $problem < 0) {
                    continue;
                }

                if ($excellent + $needAttention + $problem !== $assetCount) {
                    $v->errors()->add(
                        "items.{$key}.assetCount",
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
