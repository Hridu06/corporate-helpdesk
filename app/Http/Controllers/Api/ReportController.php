<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use App\Models\Department;
use Illuminate\Http\JsonResponse;

/**
 * Ticket statistics. Every route needs `report.view` (declared in routes/api.php). The answers
 * are aggregate numbers only; see TicketReports for what each figure means.
 */
class ReportController extends Controller
{
    /**
     * Departments for the report filter. Every department, active or not, since old tickets
     * still belong to retired ones. (The "new ticket" form's list needs `ticket.create`, which
     * the people allowed to read reports do not necessarily have.)
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function overview(ReportRequest $request): JsonResponse
    {
        return $this->answer($request, $request->reports()->overview());
    }

    public function timeline(ReportRequest $request): JsonResponse
    {
        return $this->answer($request, $request->reports()->timeline());
    }

    public function breakdown(ReportRequest $request): JsonResponse
    {
        return $this->answer($request, $request->reports()->breakdown());
    }

    public function agents(ReportRequest $request): JsonResponse
    {
        return $this->answer($request, $request->reports()->agents());
    }

    private function answer(ReportRequest $request, array $data): JsonResponse
    {
        return response()->json([
            'range' => $request->range()->toArray(),
            'department_id' => $request->input('department_id'),
            'data' => $data,
        ]);
    }
}
