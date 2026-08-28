<?php

namespace App\Modules\Performance\Requests;

use App\Modules\Performance\Models\Perspective;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePerspectiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $perspective = $this->route('perspective');

            if (Perspective::query()->where('name', $this->input('name'))->where('id', '!=', $perspective?->id)->exists()) {
                $validator->errors()->add('name', 'This perspective already exists.');
            }
        });
    }
}
