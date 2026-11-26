<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('vat_rate') && ! $this->has('tax_rate')) {
            $this->merge(['tax_rate' => $this->input('vat_rate')]);
        }
    }

    public function rules(): array
    {
        $receiptId = $this->route('id');

        return [
            'invoice_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('purchase_invoices', 'invoice_number')->ignore($receiptId, 'goods_receipt_id'),
            ],
            'invoice_date' => ['required', 'date'],
            'amount_before_tax' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['required', 'numeric', 'in:0,15,0.0,15.0,0.00,15.00'],
            'vat_rate' => ['nullable', 'numeric', 'in:0,15,0.0,15.0,0.00,15.00'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'vat_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'invoice_number.required' => 'Invoice number is required.',
            'invoice_number.unique' => 'This invoice number already exists.',
            'invoice_date.required' => 'Invoice date is required.',
            'amount_before_tax.required' => 'Amount before tax is required.',
            'file.mimes' => 'Invoice file must be a PDF or image.',
            'file.max' => 'Invoice file must not exceed 10MB.',
        ];
    }
}
