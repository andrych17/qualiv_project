<?php

namespace App\Modules\Projects\Requests;

use App\Modules\Projects\Services\IssueService;
use Illuminate\Foundation\Http\FormRequest;

class StoreIssueAttachmentRequest extends FormRequest
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
                'max:'.(IssueService::maxAttachmentBytes() / 1024),
            ],
        ];
    }

    public function messages(): array
    {
        $maxMb = IssueService::maxAttachmentBytes() / 1024 / 1024;

        return [
            'file.max' => "The file must not be larger than {$maxMb} MB.",
        ];
    }
}
