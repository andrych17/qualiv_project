<?php

namespace App\Modules\Legal\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckInFieldVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'gps_lat' => 'required|numeric|between:-90,90',
            'gps_lng' => 'required|numeric|between:-180,180',
        ];
    }
}
