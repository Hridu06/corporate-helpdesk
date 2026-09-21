<?php

namespace App\Http\Controllers\Api;

use App\Enums\RoleName;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Api\Concerns\BuildsTicketDetail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Support\Attachments\AttachmentStorage;
use App\Support\TicketWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Authorisation is declared on the routes (`can:` -> TicketPolicy). The list is
 * additionally scoped here with the same rule as TicketPolicy::view.
 */
class TicketController extends Controller
{
    use BuildsTicketDetail;

    public function __construct(private readonly AttachmentStorage $storage) {}

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

    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        return response()->json($this->detail($request->user(), $ticket));
    }

    public function store(StoreTicketRequest $request): JsonResponse
    {
        // Files are judged before the ticket exists; a bad file rejects the whole request.
        $inspected = $this->storage->inspectAll($request->file('attachments', []));
        $written = [];

        try {
            $ticket = DB::transaction(function () use ($request, $inspected, &$written) {
                $ticket = new Ticket($request->safe()->except('attachments'));
                // Set by the backend only, never from the request.
                $ticket->customer_id = $request->user()->id;
                $ticket->status = TicketStatus::Open;
                $ticket->priority = TicketPriority::Medium;
                $ticket->save();

                $this->storage->store($ticket, null, $request->user(), $inspected, $written);
                AuditLog::record($request->user(), 'ticket.created', $ticket, ['number' => $ticket->number, 'attachments' => count($inspected)], $request->ip());

                return $ticket;
            });
        } catch (Throwable $e) {
            $this->storage->deleteFiles($written);
            throw $e;
        }

        return response()->json(['message' => 'Ticket created.', ...$this->detail($request->user(), $ticket)], 201);
    }

    /** Move the ticket to another status if TicketWorkflow allows it for this user. */
    public function updateStatus(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(TicketStatus::class)]]);
        $to = TicketStatus::from($data['status']);
        $allowed = TicketWorkflow::allowedStatuses($request->user(), $ticket);

        if (! in_array($to->value, $allowed, true)) {
            return response()->json([
                'message' => 'This ticket cannot be moved to that status.',
                'code' => 'invalid_transition',
                'allowed' => $allowed,
            ], 422);
        }

        $from = $ticket->status;
        $ticket->applyStatus($to);
        $ticket->save();

        $this->log($request, $ticket, TicketEvent::STATUS_CHANGED, ['from' => $from->value, 'to' => $to->value]);

        return response()->json(['message' => 'Status updated.', ...$this->detail($request->user(), $ticket)]);
    }

    public function updatePriority(Request $request, Ticket $ticket): JsonResponse
    {
        if ($closed = $this->refuseIfClosed($ticket)) {
            return $closed;
        }

        $data = $request->validate(['priority' => ['required', Rule::enum(TicketPriority::class)]]);
        $to = TicketPriority::from($data['priority']);

        if ($ticket->priority !== $to) {
            $from = $ticket->priority;
            $ticket->priority = $to;
            $ticket->save();

            $this->log($request, $ticket, TicketEvent::PRIORITY_CHANGED, ['from' => $from->value, 'to' => $to->value]);
        }

        return response()->json(['message' => 'Priority updated.', ...$this->detail($request->user(), $ticket)]);
    }

    /** Assign to an active agent (a member of the ticket's department, if it has one), or clear with null. */
    public function updateAssignee(Request $request, Ticket $ticket): JsonResponse
    {
        if ($closed = $this->refuseIfClosed($ticket)) {
            return $closed;
        }

        $data = $request->validate(['assignee_id' => ['present', 'nullable', 'integer']]);
        $assigneeId = $data['assignee_id'];

        if ($assigneeId === null) {
            if ($ticket->assigned_to !== null) {
                $previous = $ticket->assignee?->name;
                $ticket->assigned_to = null;
                $ticket->save();
                $this->log($request, $ticket, TicketEvent::UNASSIGNED, ['from' => $previous]);
            }

            return response()->json(['message' => 'Ticket unassigned.', ...$this->detail($request->user(), $ticket)]);
        }

        $agent = $this->assignableAgentsQuery($ticket)->whereKey($assigneeId)->first();

        if (! $agent) {
            throw ValidationException::withMessages([
                'assignee_id' => $ticket->department_id
                    ? "Choose an active agent who belongs to this ticket's department."
                    : 'Choose an active agent.',
            ]);
        }

        if ($ticket->assigned_to !== $agent->id) {
            $ticket->assigned_to = $agent->id;
            $ticket->save();
            $this->log($request, $ticket, TicketEvent::ASSIGNED, ['assignee_name' => $agent->name]);
        }

        return response()->json(['message' => 'Ticket assigned.', ...$this->detail($request->user(), $ticket)]);
    }

    /** Agents this ticket can be assigned to (drives the assignee picker). */
    public function assignableAgents(Ticket $ticket): JsonResponse
    {
        return response()->json([
            'agents' => $this->assignableAgentsQuery($ticket)->orderBy('name')->get(['users.id', 'users.name', 'users.email']),
        ]);
    }

    /** Choices for the "new ticket" form: only active departments. */
    public function options(): JsonResponse
    {
        return response()->json([
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function assignableAgentsQuery(Ticket $ticket)
    {
        return User::query()
            ->role(RoleName::Agent->value)
            ->where('status', UserStatus::Active->value)
            ->when($ticket->department_id, fn ($query, int $departmentId) => $query->whereHas(
                'departments',
                fn ($q) => $q->whereKey($departmentId),
            ));
    }

    /** A closed ticket is read-only apart from being reopened. */
    private function refuseIfClosed(Ticket $ticket): ?JsonResponse
    {
        return $ticket->status === TicketStatus::Closed
            ? response()->json(['message' => 'This ticket is closed. Reopen it to make changes.', 'code' => 'ticket_closed'], 422)
            : null;
    }

    /** Write the change to the ticket's history and to the global audit log. */
    private function log(Request $request, Ticket $ticket, string $type, array $data): void
    {
        TicketEvent::record($ticket, $request->user(), $type, $data);
        AuditLog::record($request->user(), 'ticket.'.$type, $ticket, ['number' => $ticket->number] + $data, $request->ip());
    }
}
