<?php

namespace App\Modules\DMS\Requests;

use App\Modules\DMS\Models\DocType;
use App\Modules\DMS\Models\Folder;
use App\Modules\DMS\Models\RetentionPolicy;
use App\Modules\DMS\Services\DocumentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDocumentRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // 'exists:DMS.folders,id' can't be used — Laravel's exists rule parses the dot as
            // connection.table, not schema.table, so "DMS" resolves as a DB connection name and
            // 500s (same pitfall CRM/WNE already hit, see StoreContactRequest::withValidator()).
            'folder_id' => ['nullable', 'integer'],
            'doc_type_id' => ['nullable', 'integer'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'integer'],
            'effective_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
            'retention_policy_id' => ['nullable', 'integer'],
            'custom_fields' => ['nullable', 'array'],
            'custom_fields.*' => ['nullable', 'string', 'max:2000'],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $folderId = $this->input('folder_id');
            if ($folderId && ! Folder::query()->whereKey($folderId)->exists()) {
                $validator->errors()->add('folder_id', 'The selected folder is invalid.');
            }

            $docTypeId = $this->input('doc_type_id');
            if ($docTypeId && ! DocType::query()->whereKey($docTypeId)->exists()) {
                $validator->errors()->add('doc_type_id', 'The selected doc type is invalid.');
            }

            $retentionPolicyId = $this->input('retention_policy_id');
            if ($retentionPolicyId && ! RetentionPolicy::query()->whereKey($retentionPolicyId)->exists()) {
                $validator->errors()->add('retention_policy_id', 'The selected retention policy is invalid.');
            }
        });
    }
}
