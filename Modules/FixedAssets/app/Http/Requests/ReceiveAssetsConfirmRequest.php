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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $items = $this->input('items', []);
            $files = $this->file('items', []);

            if (! is_array($items)) {
                $v->errors()->add('items', 'items must be an array.');

                return;
            }

            foreach ($items as $key => $item) {
                $prefix = "items.{$key}";

                if (empty($item['assetId'] ?? null)) {
                    $v->errors()->add("{$prefix}.assetId", 'assetId is required.');
                }

                if (empty($item['assignedZoneId'] ?? null)) {
                    $v->errors()->add("{$prefix}.assignedZoneId", 'assignedZoneId is required.');
                }

                if (empty($item['assetTypeId'] ?? null)) {
                    $v->errors()->add("{$prefix}.assetTypeId", 'assetTypeId is required.');
                }

                $assetCount = (int) ($item['assetCount'] ?? -1);
                $excellent = (int) ($item['excellentCount'] ?? -1);
                $needAttention = (int) ($item['needAttentionCount'] ?? -1);
                $problem = (int) ($item['problemCount'] ?? -1);

                foreach (['assetCount' => $assetCount, 'excellentCount' => $excellent, 'needAttentionCount' => $needAttention, 'problemCount' => $problem] as $field => $val) {
                    if ($val < 0) {
                        $v->errors()->add("{$prefix}.{$field}", "{$field} is required and must be >= 0.");
                    }
                }

                if ($assetCount >= 0 && $excellent >= 0 && $needAttention >= 0 && $problem >= 0) {
                    if ($excellent + $needAttention + $problem !== $assetCount) {
                        $v->errors()->add(
                            "{$prefix}.assetCount",
                            'Sum of excellentCount + needAttentionCount + problemCount must equal assetCount.'
                        );
                    }
                }

                $imageFile = $files[$key]['image'] ?? null;
                if (! $imageFile) {
                    $v->errors()->add("{$prefix}.image", 'image is required.');
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
