<?php

namespace App\Http\Requests\Tickets;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Enforced by the route's `can:create` middleware.
    }

    protected function prepareForValidation(): void
    {
        foreach (['subject', 'description'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    /**
     * Status, priority, customer and assignee are deliberately not accepted:
     * validated() only ever returns these fields.
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:5000'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
        ];
    }
}
