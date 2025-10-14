<?php

namespace Modules\BranchManagers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => 'required|image|mimes:jpeg,png,jpg|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'Profile image is required',
            'image.image' => 'File must be an image',
            'image.mimes' => 'Image must be jpeg, png, or jpg format',
            'image.max' => 'Image size cannot exceed 2MB',
        ];
    }
}
