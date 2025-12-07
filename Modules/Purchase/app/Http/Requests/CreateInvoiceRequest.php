<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_number' => ['required', 'string', 'max:100', 'unique:purchase_invoices,invoice_number'],
            'invoice_date' => ['required', 'date'],
            'amount_before_tax' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
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

