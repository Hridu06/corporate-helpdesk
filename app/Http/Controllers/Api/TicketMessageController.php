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
use App\Models\TicketMessage;
use App\Support\Attachments\AttachmentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The conversation on a ticket. Routes are guarded by TicketPolicy (view / reply /
 * addNote); internal notes are additionally filtered out of every read for anyone
 * without `ticket.internal_note` (TicketMessage::scopeVisibleTo).
 */
class TicketMessageController extends Controller
{
    use BuildsTicketDetail;

    public function __construct(private readonly AttachmentStorage $storage) {}

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
            ->with(['author:id,name', 'author.roles', 'attachments'])
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

        // Every file is judged before anything is written; a bad file rejects the whole message.
        $inspected = $this->storage->inspectAll($request->file('attachments', []));
        $written = [];

        try {
            $message = $this->saveMessage($request, $ticket, $type, $inspected, $written);
        } catch (Throwable $e) {
            // The transaction rolled back; do not leave its files behind.
            $this->storage->deleteFiles($written);
            throw $e;
        }

        if (! $message) {
            return response()->json([
                'message' => 'This ticket is closed. Reopen it to add messages.',
                'code' => 'ticket_closed',
            ], 422);
        }

        $message->load(['author:id,name', 'author.roles', 'attachments']);

        return response()->json([
            'message' => $type === TicketMessageType::InternalNote ? 'Note added.' : 'Reply sent.',
            'entry' => new TicketMessageResource($message),
            ...$this->detail($actor, $ticket->refresh()),
        ], 201);
    }

    /** @param list<array> $inspected @param list<string> $written */
    private function saveMessage(StoreTicketMessageRequest $request, Ticket $ticket, TicketMessageType $type, array $inspected, array &$written): ?TicketMessage
    {
        $actor = $request->user();

        return DB::transaction(function () use ($request, $ticket, $type, $actor, $inspected, &$written) {
            // Re-read under a lock so a ticket closed a moment ago cannot slip through.
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if ($locked->status === TicketStatus::Closed) {
                return null;
            }

            $this->storage->assertTicketCapacity($locked, $inspected);

            $message = $locked->messages()->create([
                'user_id' => $actor->id,
                'type' => $type,
                'body' => $request->validated('body'),
            ]);

            $this->storage->store($locked, $message, $actor, $inspected, $written);

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

            // The audit log records that a message was written (and how many files), never its text.
            AuditLog::record($actor, 'ticket.'.$type->value, $locked, ['number' => $locked->number, 'attachments' => count($inspected)], $request->ip());

            return $message;
        });
    }
}
