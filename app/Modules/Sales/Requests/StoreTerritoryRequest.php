<?php

namespace App\Modules\Sales\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTerritoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('territory')?->id;

        return [
            'code' => ['required', 'string', 'max:30', 'unique:SALES.territories,code,'.$id],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }
}
