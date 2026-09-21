<?php

namespace App\Models;

use App\Enums\TicketMessageType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only: attachments are never edited, and are removed only with their ticket. */
class TicketAttachment extends Model
{
    public const UPDATED_AT = null;

    // Set by the storage service only, never from a request.
    protected $guarded = [];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getIsImageAttribute(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Attachments this viewer may see: those on the ticket itself and on public
     * replies, plus those on internal notes only for holders of `ticket.internal_note`.
     * Same rule as TicketMessage::scopeVisibleTo, applied through the parent message.
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        if ($viewer->can('ticket.internal_note')) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereNull('ticket_message_id')
            ->orWhereHas('message', fn (Builder $m) => $m->where('type', TicketMessageType::PublicReply->value)));
    }
}
