<?php

namespace App\Modules\Legal\Requests;

use App\Modules\Legal\Models\ProtocolBook;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProtocolBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'book_type' => ['required', Rule::in(ProtocolBook::TYPES)],
            'year' => 'required|integer|min:2000|max:2100',
            'volume' => 'nullable|integer|min:1',
            'notary_user_id' => 'required|integer|exists:users,id',
            'opened_at' => 'nullable|date',
        ];
    }
}
