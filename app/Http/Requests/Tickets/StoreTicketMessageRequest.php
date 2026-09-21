<?php

namespace App\Http\Requests\Tickets;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicketMessageRequest extends FormRequest
{
    public const MAX_LENGTH = 5000;

    /** Authorisation is declared on the route (TicketPolicy). */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim($this->input('body'))]);
        }
    }

    public function rules(): array
    {
        // A whitespace-only body becomes '' after trim and fails `required`.
        return [
            'body' => ['required', 'string', 'max:'.self::MAX_LENGTH],
            // Only checked for shape here; AttachmentInspector judges the content.
            'attachments' => ['sometimes', 'array', 'max:'.config('helpdesk.attachments.max_files')],
            'attachments.*' => ['file'],
        ];
    }
}
