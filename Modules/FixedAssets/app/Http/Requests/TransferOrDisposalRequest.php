<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\FixedAssets\Enums\DisposalMethod;
use Modules\FixedAssets\Enums\TransferDisposalKind;

class TransferOrDisposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('autoApprove')) {
            $this->merge([
                'autoApprove' => filter_var($this->input('autoApprove'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:'.implode(',', TransferDisposalKind::values())],

            // Transfer to branch fields
            'branchId' => ['required_if:type,transfer_to_branch', 'nullable', 'uuid', 'exists:branches,id'],
            'autoApprove' => ['nullable', 'boolean'],

            // Disposal fields
            'disposalDate' => ['required_if:type,disposal', 'nullable', 'string'],
            'disposalTime' => ['required_if:type,disposal', 'nullable', 'string'],
            'disposalMethod' => ['nullable', 'string', 'in:'.implode(',', DisposalMethod::values())],

            // Assets common
            'assets' => ['required', 'array', 'min:1'],
            'assets.*.assetId' => ['required', 'uuid', 'exists:fixed_assets,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $type = $this->input('type');
            $assets = $this->input('assets', []);
            $files = $this->file('assets', []);

            foreach ($assets as $i => $asset) {
                $prefix = "assets.{$i}";

                if ($type === TransferDisposalKind::TRANSFER_TO_BRANCH->value) {
                    if (empty($asset['transferReason'] ?? null)) {
                        $v->errors()->add("{$prefix}.transferReason", 'transferReason is required.');
                    }
                    $doc = $files[$i]['documentationPhotos'] ?? null;
                    if (! $doc) {
                        $v->errors()->add("{$prefix}.documentationPhotos", 'documentationPhotos is required.');
                    }
                }

                if ($type === TransferDisposalKind::DISPOSAL->value) {
                    if (empty($asset['disposalReason'] ?? null)) {
                        $v->errors()->add("{$prefix}.disposalReason", 'disposalReason is required.');
                    }
                    if (empty($asset['conditionDescription'] ?? null)) {
                        $v->errors()->add("{$prefix}.conditionDescription", 'conditionDescription is required.');
                    }
                    $evidence = $files[$i]['visualEvidence'] ?? null;
                    if (! $evidence) {
                        $v->errors()->add("{$prefix}.visualEvidence", 'visualEvidence is required.');
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
