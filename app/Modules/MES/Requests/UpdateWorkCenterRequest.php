<?php

namespace App\Modules\MES\Requests;

use App\Modules\MES\Models\WorkCenter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateWorkCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:150',
            'area_line' => 'nullable|string|max:100',
            'type' => 'required|in:discrete,process',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $workCenter = $this->route('work_center');

            if (WorkCenter::query()->where('code', $this->input('code'))->where('id', '!=', $workCenter?->id)->exists()) {
                $validator->errors()->add('code', 'This code is already in use.');
            }
        });
    }
}
