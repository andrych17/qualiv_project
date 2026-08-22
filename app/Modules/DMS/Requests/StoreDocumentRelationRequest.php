<?php

namespace App\Modules\DMS\Requests;

use App\Modules\DMS\Models\Document;
use App\Modules\DMS\Models\DocumentRelation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** §3H — link the route's {document} (as source) to another document. */
class StoreDocumentRelationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'exists:DMS.documents,id' can't be used — see StoreDocumentRequest::rules() for why.
            'target_document_id' => ['required', 'integer'],
            'relation_type' => ['required', 'string', 'in:'.implode(',', [
                DocumentRelation::TYPE_AMENDMENT_OF,
                DocumentRelation::TYPE_SUPERSEDES,
                DocumentRelation::TYPE_ATTACHMENT_OF,
                DocumentRelation::TYPE_RELATED_TO,
            ])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Document $document */
            $document = $this->route('document');
            $targetId = (int) $this->input('target_document_id');

            if ($targetId === $document->id) {
                $validator->errors()->add('target_document_id', 'A document cannot be related to itself.');

                return;
            }

            if (! Document::query()->whereKey($targetId)->exists()) {
                $validator->errors()->add('target_document_id', 'The selected document is invalid.');

                return;
            }

            $duplicate = DocumentRelation::query()
                ->where('source_document_id', $document->id)
                ->where('target_document_id', $targetId)
                ->where('relation_type', $this->input('relation_type'))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('target_document_id', 'This relation already exists.');
            }
        });
    }
}
