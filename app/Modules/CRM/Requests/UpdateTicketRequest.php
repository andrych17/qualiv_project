<?php

namespace App\Modules\CRM\Requests;

use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\Ticket;
use App\Modules\CRM\Models\TicketCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_id' => 'nullable|integer',
            'requester_name' => 'nullable|string|max:255',
            'requester_contact' => 'nullable|string|max:255',
            'subject' => 'required|string|max:255',
            'category_id' => 'nullable|integer',
            'priority' => ['required', Rule::in(Ticket::PRIORITIES)],
            'assigned_to' => 'nullable|integer|exists:users,id',
            'sla_due_at' => 'nullable|date',
            'channel' => ['required', Rule::in(Ticket::CHANNELS)],
        ];
    }

    /** exists:CRM.partners,id / exists:CRM.ticket_categories,id can't be used — see StoreContactRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $partnerId = $this->input('partner_id');
            if ($partnerId && ! Partner::query()->whereKey($partnerId)->exists()) {
                $validator->errors()->add('partner_id', 'The selected partner is invalid.');
            }

            $categoryId = $this->input('category_id');
            if ($categoryId && ! TicketCategory::query()->whereKey($categoryId)->exists()) {
                $validator->errors()->add('category_id', 'The selected category is invalid.');
            }

            if (! $partnerId && ! $this->filled('requester_name')) {
                $validator->errors()->add('requester_name', 'Select a partner or enter a requester name.');
            }
        });
    }
}
