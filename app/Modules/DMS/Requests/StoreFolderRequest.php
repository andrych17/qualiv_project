<?php

namespace App\Modules\DMS\Requests;

use App\Modules\DMS\Models\DocType;
use App\Modules\DMS\Models\Folder;
use App\Modules\DMS\Models\RetentionPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            // 'exists:DMS.folders,id' can't be used — Laravel's exists rule parses the dot as
            // connection.table, not schema.table (see DMS\Requests\StoreDocumentRequest).
            'parent_folder_id' => ['nullable', 'integer'],
            'default_doc_type_id' => ['nullable', 'integer'],
            'default_retention_policy_id' => ['nullable', 'integer'],
            'access_flag' => ['required', 'string', 'in:'.implode(',', Folder::ACCESS_FLAGS)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $parentId = $this->input('parent_folder_id');
            if ($parentId && ! Folder::query()->whereKey($parentId)->exists()) {
                $validator->errors()->add('parent_folder_id', 'The selected parent folder is invalid.');
            }

            $docTypeId = $this->input('default_doc_type_id');
            if ($docTypeId && ! DocType::query()->whereKey($docTypeId)->exists()) {
                $validator->errors()->add('default_doc_type_id', 'The selected doc type is invalid.');
            }

            $retentionPolicyId = $this->input('default_retention_policy_id');
            if ($retentionPolicyId && ! RetentionPolicy::query()->whereKey($retentionPolicyId)->exists()) {
                $validator->errors()->add('default_retention_policy_id', 'The selected retention policy is invalid.');
            }
        });
    }
}
