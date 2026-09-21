<?php

namespace App\Http\Requests\Reports;

use App\Support\Reports\ReportRange;
use App\Support\Reports\TicketReports;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ReportRequest extends FormRequest
{
    /** Authorisation is declared on the routes (`can:report.view`). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            // "The last N days, ending today in the reporting time zone"; ignored when from or to is given.
            'days' => ['nullable', 'integer', 'min:1', 'max:'.ReportRange::MAX_DAYS],
            // A department id, or "none" for tickets that have no department.
            'department_id' => ['nullable', 'regex:/^(none|[1-9][0-9]{0,17})$/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }

            $range = $this->range();
            $today = CarbonImmutable::now($range->timezone)->startOfDay();

            if ($range->from > $range->to) {
                $validator->errors()->add('from', 'The start date must not be after the end date.');
            } elseif ($range->days() > ReportRange::MAX_DAYS) {
                $validator->errors()->add('from', 'A report can cover at most '.ReportRange::MAX_DAYS.' days.');
            } elseif ($range->to->startOfDay() > $today) {
                $validator->errors()->add('to', 'The end date cannot be in the future.');
            }
        }];
    }

    public function range(): ReportRange
    {
        $timezone = config('helpdesk.reports_timezone');

        if ($this->filled('days') && ! $this->filled('from') && ! $this->filled('to')) {
            $today = CarbonImmutable::now($timezone);

            return ReportRange::make($today->subDays((int) $this->input('days') - 1)->format('Y-m-d'), $today->format('Y-m-d'), $timezone);
        }

        return ReportRange::make($this->input('from'), $this->input('to'), $timezone);
    }

    public function reports(): TicketReports
    {
        $department = $this->input('department_id');

        return new TicketReports($this->range(), $department === null || $department === '' ? null : ($department === 'none' ? 'none' : (int) $department));
    }
}
