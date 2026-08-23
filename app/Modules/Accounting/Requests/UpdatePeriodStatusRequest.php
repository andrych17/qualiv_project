<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Foundation\Http\FormRequest;

/** §3O period locking action — one field, on a list row, not a full CRUD resource. */
class UpdatePeriodStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:'.implode(',', FiscalPeriod::STATUSES)],
        ];
    }
}
