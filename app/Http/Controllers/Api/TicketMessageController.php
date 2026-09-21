<?php

namespace App\Http\Controllers\Api;

use App\Enums\TicketActivity;
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
use App\Models\User;
use App\Support\Attachments\AttachmentStorage;
use App\Support\Notifications\TicketNotifier;
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
            $saved = $this->saveMessage($request, $ticket, $type, $inspected, $written);
        } catch (Throwable $e) {
            // The transaction rolled back; do not leave its files behind.
            $this->storage->deleteFiles($written);
            throw $e;
        }

        if (! $saved) {
            return response()->json([
                'message' => 'This ticket is closed. Reopen it to add messages.',
                'code' => 'ticket_closed',
            ], 422);
        }

        ['message' => $message, 'reopened' => $reopened] = $saved;
        $ticket->refresh();

        // Only reached once the transaction has committed. Exactly one email kind per message: a
        // customer's reply that also reopened the ticket is one "replied and reopened" email.
        TicketNotifier::send($this->activityFor($type, $ticket, $actor, $reopened), $ticket, $actor);

        $message->load(['author:id,name', 'author.roles', 'attachments']);

        return response()->json([
            'message' => $type === TicketMessageType::InternalNote ? 'Note added.' : 'Reply sent.',
            'entry' => new TicketMessageResource($message),
            ...$this->detail($actor, $ticket),
        ], 201);
    }

    private function activityFor(TicketMessageType $type, Ticket $ticket, User $actor, bool $reopened): TicketActivity
    {
        if ($type === TicketMessageType::InternalNote) {
            return TicketActivity::InternalNote;
        }

        if ($actor->id !== $ticket->customer_id) {
            return TicketActivity::StaffReply;
        }

        return $reopened ? TicketActivity::CustomerReplyReopened : TicketActivity::CustomerReply;
    }

    /**
     * @param  list<array>  $inspected
     * @param  list<string>  $written
     * @return array{message: TicketMessage, reopened: bool}|null null when the ticket is closed
     */
    private function saveMessage(StoreTicketMessageRequest $request, Ticket $ticket, TicketMessageType $type, array $inspected, array &$written): ?array
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
            $reopened = $type === TicketMessageType::PublicReply
                && $locked->status === TicketStatus::Resolved
                && $locked->customer_id === $actor->id;

            if ($reopened) {
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

            return ['message' => $message, 'reopened' => $reopened];
        });
    }
}
