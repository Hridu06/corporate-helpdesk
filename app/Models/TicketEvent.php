<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only history entry for a ticket. */
class TicketEvent extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_CHANGED = 'status_changed';

    public const PRIORITY_CHANGED = 'priority_changed';

    public const ASSIGNED = 'assigned';

    public const UNASSIGNED = 'unassigned';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(Ticket $ticket, User $actor, string $type, array $data = []): self
    {
        return self::create([
            'ticket_id' => $ticket->id,
            'actor_id' => $actor->id,
            'type' => $type,
            'data' => $data ?: null,
        ]);
    }
}
