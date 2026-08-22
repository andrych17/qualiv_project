<?php

namespace App\Modules\Legal\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Shared shape for open()/revoke() — both require a logged note (§3D, never a silent flip). */
class WillNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => 'required|string|max:2000',
        ];
    }
}
