<?php

namespace App\Http\Controllers\Api;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authorisation is declared on the routes (`can:` -> TicketPolicy). The list is
 * additionally scoped here with the same rule as TicketPolicy::view.
 */
class TicketController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:'.implode(',', array_column(TicketStatus::cases(), 'value'))],
            'priority' => ['nullable', 'in:'.implode(',', array_column(TicketPriority::cases(), 'value'))],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'assigned' => ['nullable', 'in:me,unassigned'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $actor = $request->user();

        $tickets = Ticket::query()
            // The description can be long and is not shown in the list.
            ->select(['id', 'subject', 'status', 'priority', 'department_id', 'customer_id', 'assigned_to', 'created_at', 'updated_at', 'resolved_at', 'closed_at'])
            ->with(['department:id,name', 'customer:id,name,email', 'assignee:id,name'])
            ->unless($actor->can('ticket.view_all'), fn ($query) => $query->where(
                fn ($q) => $q->where('customer_id', $actor->id)->orWhere('assigned_to', $actor->id),
            ))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($query, string $priority) => $query->where('priority', $priority))
            ->when($filters['department_id'] ?? null, fn ($query, int $id) => $query->where('department_id', $id))
            ->when(($filters['assigned'] ?? null) === 'me', fn ($query) => $query->where('assigned_to', $actor->id))
            ->when(($filters['assigned'] ?? null) === 'unassigned', fn ($query) => $query->whereNull('assigned_to'))
            ->when($filters['search'] ?? null, function ($query, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(function ($q) use ($like, $term) {
                    $q->where('subject', 'like', $like);
                    // "TCK-000123", "tck-123" and "123" all find ticket 123.
                    if (preg_match('/^(?:TCK-)?0*(\d+)$/i', trim($term), $match)) {
                        $q->orWhere('id', (int) $match[1]);
                    }
                });
            })
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15);

        return TicketResource::collection($tickets);
    }

    public function show(Ticket $ticket): JsonResponse
    {
        $ticket->load(['department:id,name', 'customer:id,name,email', 'assignee:id,name']);

        return response()->json(['ticket' => new TicketResource($ticket)]);
    }

    public function store(StoreTicketRequest $request): JsonResponse
    {
        $ticket = new Ticket($request->validated());
        // Set by the backend only, never from the request.
        $ticket->customer_id = $request->user()->id;
        $ticket->status = TicketStatus::Open;
        $ticket->priority = TicketPriority::Medium;
        $ticket->save();

        AuditLog::record($request->user(), 'ticket.created', $ticket, ['number' => $ticket->number], $request->ip());

        return response()->json([
            'message' => 'Ticket created.',
            'ticket' => new TicketResource($ticket->load(['department:id,name', 'customer:id,name,email', 'assignee:id,name'])),
        ], 201);
    }

    /** Choices for the "new ticket" form: only active departments. */
    public function options(): JsonResponse
    {
        return response()->json([
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
