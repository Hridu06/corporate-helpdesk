<?php

namespace App\Http\Controllers\Api;

use App\Enums\TicketMessageType;
use App\Enums\TicketStatus;
use App\Http\Controllers\Api\Concerns\BuildsTicketDetail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\StoreTicketMessageRequest;
use App\Http\Resources\TicketMessageResource;
use App\Models\AuditLog;
use App\Models\Ticket;
use App\Models\TicketEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The conversation on a ticket. Routes are guarded by TicketPolicy (view / reply /
 * addNote); internal notes are additionally filtered out of every read for anyone
 * without `ticket.internal_note` (TicketMessage::scopeVisibleTo).
 */
class TicketMessageController extends Controller
{
    use BuildsTicketDetail;

    /** The latest messages, oldest first. Pass `before` (a message id) to page back. */
    public function index(Request $request, Ticket $ticket): JsonResponse
    {
        $params = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = $params['per_page'] ?? 50;

        $rows = $ticket->messages()
            ->visibleTo($request->user())
            ->with(['author:id,name', 'author.roles'])
            ->when($params['before'] ?? null, fn ($query, int $before) => $query->where('id', '<', $before))
            ->latest('id')
            ->limit($perPage + 1)
            ->get();

        return response()->json([
            'data' => TicketMessageResource::collection($rows->take($perPage)->reverse()->values()),
            'has_more' => $rows->count() > $perPage,
        ]);
    }

    public function reply(StoreTicketMessageRequest $request, Ticket $ticket): JsonResponse
    {
        return $this->store($request, $ticket, TicketMessageType::PublicReply);
    }

    public function note(StoreTicketMessageRequest $request, Ticket $ticket): JsonResponse
    {
        return $this->store($request, $ticket, TicketMessageType::InternalNote);
    }

    private function store(StoreTicketMessageRequest $request, Ticket $ticket, TicketMessageType $type): JsonResponse
    {
        $actor = $request->user();

        $message = DB::transaction(function () use ($request, $ticket, $type, $actor) {
            // Re-read under a lock so a ticket closed a moment ago cannot slip through.
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if ($locked->status === TicketStatus::Closed) {
                return null;
            }

            $message = $locked->messages()->create([
                'user_id' => $actor->id,
                'type' => $type,
                'body' => $request->validated('body'),
            ]);

            // The customer answering a resolved ticket means it is not resolved after all.
            if ($type === TicketMessageType::PublicReply
                && $locked->status === TicketStatus::Resolved
                && $locked->customer_id === $actor->id) {
                $locked->applyStatus(TicketStatus::Open);
                $locked->save();
                TicketEvent::record($locked, $actor, TicketEvent::STATUS_CHANGED, [
                    'from' => TicketStatus::Resolved->value,
                    'to' => TicketStatus::Open->value,
                    'reason' => 'customer_reply',
                ]);
            } else {
                $locked->touch();
            }

            // The audit log records that a message was written, never its text.
            AuditLog::record($actor, 'ticket.'.$type->value, $locked, ['number' => $locked->number], $request->ip());

            return $message;
        });

        if (! $message) {
            return response()->json([
                'message' => 'This ticket is closed. Reopen it to add messages.',
                'code' => 'ticket_closed',
            ], 422);
        }

        $message->load(['author:id,name', 'author.roles']);

        return response()->json([
            'message' => $type === TicketMessageType::InternalNote ? 'Note added.' : 'Reply sent.',
            'entry' => new TicketMessageResource($message),
            ...$this->detail($actor, $ticket->refresh()),
        ], 201);
    }
}
