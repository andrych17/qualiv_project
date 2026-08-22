<?php

namespace App\Modules\DMS\Requests;

use App\Modules\DMS\Models\DocType;
use App\Modules\DMS\Models\Folder;
use App\Modules\DMS\Models\RetentionPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'parent_folder_id' => ['nullable', 'integer'],
            'default_doc_type_id' => ['nullable', 'integer'],
            'default_retention_policy_id' => ['nullable', 'integer'],
            'access_flag' => ['required', 'string', 'in:'.implode(',', Folder::ACCESS_FLAGS)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Folder $folder */
            $folder = $this->route('folder');

            $docTypeId = $this->input('default_doc_type_id');
            if ($docTypeId && ! DocType::query()->whereKey($docTypeId)->exists()) {
                $validator->errors()->add('default_doc_type_id', 'The selected doc type is invalid.');
            }

            $retentionPolicyId = $this->input('default_retention_policy_id');
            if ($retentionPolicyId && ! RetentionPolicy::query()->whereKey($retentionPolicyId)->exists()) {
                $validator->errors()->add('default_retention_policy_id', 'The selected retention policy is invalid.');
            }

            $parentId = $this->input('parent_folder_id');
            if (! $parentId) {
                return;
            }

            if ((int) $parentId === $folder->id) {
                $validator->errors()->add('parent_folder_id', 'A folder cannot be its own parent.');

                return;
            }

            if (! Folder::query()->whereKey($parentId)->exists()) {
                $validator->errors()->add('parent_folder_id', 'The selected parent folder is invalid.');

                return;
            }

            // Walk the proposed parent's ancestor chain — moving a folder under one of its own
            // descendants would create a cycle that infinite-loops the tree/select rendering.
            $ancestorId = (int) $parentId;
            $depth = 0;
            while ($ancestorId && $depth < 100) {
                if ($ancestorId === $folder->id) {
                    $validator->errors()->add('parent_folder_id', 'Cannot move a folder under its own descendant.');

                    return;
                }
                $ancestorId = Folder::query()->whereKey($ancestorId)->value('parent_folder_id');
                $depth++;
            }
        });
    }
}
