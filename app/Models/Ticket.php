<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Only what a requester supplies is mass-assignable. Status, priority, customer and
// assignee are always set by backend code (never taken from a request body).
#[Fillable(['subject', 'description', 'department_id'])]
class Ticket extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** Human-friendly reference such as TCK-000123, derived from the id. */
    protected function number(): Attribute
    {
        return Attribute::get(fn () => self::formatNumber($this->id));
    }

    public static function formatNumber(int $id): string
    {
        return 'TCK-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /** Set the status and keep resolved_at / closed_at consistent with it. */
    public function applyStatus(TicketStatus $to): void
    {
        $this->status = $to;
        $this->resolved_at = $to === TicketStatus::Resolved ? now() : ($to === TicketStatus::Closed ? $this->resolved_at : null);
        $this->closed_at = $to === TicketStatus::Closed ? now() : null;
    }
}
