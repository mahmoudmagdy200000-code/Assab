<?php

namespace Modules\Supplier\Http\Requests\QualityAssurance;

use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'document_type' => 'required|string|in:certificate,test_report,compliance_doc,batch_info',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'order_id' => 'nullable|uuid|exists:purchase_orders,id',
            'product_id' => 'nullable|uuid|exists:supplier_products,id',
            'issue_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after:issue_date',
            'issuing_authority' => 'nullable|string|max:255',
            'certificate_number' => 'nullable|string|max:100',
            'metadata' => 'nullable|array',
        ];
    }
}
