<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\FixedAssets\Enums\RecipientInspectionResult;

class ApproveZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sessionId' => ['required', 'string'],
            'zoneId' => ['required', 'string'],
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

            $inspectionValues = RecipientInspectionResult::values();

            foreach ($items as $key => $item) {
                $prefix = "items.{$key}";

                if (empty($item['assetId'] ?? null)) {
                    $v->errors()->add("{$prefix}.assetId", 'assetId is required.');
                }

                $inspection = $item['recipientInspection'] ?? null;
                if (! $inspection || ! in_array($inspection, $inspectionValues, true)) {
                    $v->errors()->add(
                        "{$prefix}.recipientInspection",
                        'recipientInspection is required and must be one of: '.implode(', ', $inspectionValues),
                    );
                }

                $note = trim((string) ($item['recipientNote'] ?? ''));
                if (in_array($inspection, [
                    RecipientInspectionResult::NEED_ATTENTION->value,
                    RecipientInspectionResult::PROBLEM->value,
                ], true) && $note === '') {
                    $v->errors()->add(
                        "{$prefix}.recipientNote",
                        'recipientNote is required when recipientInspection is need_attention or problem.',
                    );
                }

                $photo = $files[$key]['photo'] ?? null;
                if ($inspection === RecipientInspectionResult::PROBLEM->value && ! $photo) {
                    $v->errors()->add(
                        "{$prefix}.photo",
                        'photo is required when recipientInspection is problem.',
                    );
                }

                if (array_key_exists('newQty', $item) && $item['newQty'] !== null && $item['newQty'] !== '') {
                    if (! is_numeric($item['newQty']) || (int) $item['newQty'] < 0) {
                        $v->errors()->add("{$prefix}.newQty", 'newQty must be a non-negative integer.');
                    }
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
