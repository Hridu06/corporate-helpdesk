<?php

namespace App\Models;

use App\Enums\TicketMessageType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketMessage extends Model
{
    // Never mass-assigned from a request: the controller sets every field.
    protected $guarded = [];

    protected function casts(): array
    {
        return ['type' => TicketMessageType::class];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The only way messages should be read for a viewer: internal notes are
     * filtered out in the query itself unless they hold `ticket.internal_note`.
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        return $viewer->can('ticket.internal_note')
            ? $query
            : $query->where('type', TicketMessageType::PublicReply->value);
    }
}
