<?php

namespace Modules\Supplier\Http\Requests\Fulfillment;

use Illuminate\Foundation\Http\FormRequest;

class StartPreparationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => 'nullable|string|max:1000',
            'quality_documents' => 'nullable|array',
            'quality_documents.*.type' => 'required|string|in:certificate,test_report,compliance_doc,batch_info',
            'quality_documents.*.title' => 'required|string|max:255',
            'quality_documents.*.file_path' => 'required|string',
            'quality_documents.*.file_name' => 'required|string|max:255',
            'quality_documents.*.file_type' => 'nullable|string|max:50',
            'testing_reports' => 'nullable|array',
            'testing_reports.*.type' => 'required|string',
            'testing_reports.*.title' => 'required|string|max:255',
            'testing_reports.*.file_path' => 'required|string',
            'testing_reports.*.file_name' => 'required|string|max:255',
            'compliance_documents' => 'nullable|array',
            'compliance_documents.*.type' => 'required|string',
            'compliance_documents.*.title' => 'required|string|max:255',
            'compliance_documents.*.file_path' => 'required|string',
            'compliance_documents.*.file_name' => 'required|string|max:255',
            'batch_information' => 'nullable|array',
            'batch_information.*.type' => 'required|string',
            'batch_information.*.title' => 'required|string|max:255',
            'batch_information.*.file_path' => 'required|string',
            'batch_information.*.file_name' => 'required|string|max:255',
            'estimated_completion_time' => 'nullable|date|after:now',
        ];
    }
}

