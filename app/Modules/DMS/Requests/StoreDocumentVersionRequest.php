<?php

namespace App\Modules\DMS\Requests;

use App\Modules\DMS\Services\DocumentService;
use Illuminate\Foundation\Http\FormRequest;

/** §3B re-upload — a new immutable version of an existing document, not a metadata edit. */
class StoreDocumentVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.(DocumentService::maxUploadBytes() / 1024),
                'mimes:'.implode(',', DocumentService::allowedExtensions()),
            ],
            'version_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        $maxMb = DocumentService::maxUploadBytes() / 1024 / 1024;

        return [
            'file.max' => "The file must not be larger than {$maxMb} MB.",
            'file.mimes' => 'File type not allowed. Allowed: '.implode(', ', DocumentService::allowedExtensions()).'.',
        ];
    }
}
