<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only record of sensitive administrative actions. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Never put secrets (passwords, tokens) in $metadata. */
    public static function record(?User $actor, string $action, Model $subject, array $metadata = [], ?string $ip = null): self
    {
        return self::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => class_basename($subject),
            'subject_id' => $subject->getKey(),
            'metadata' => $metadata ?: null,
            'ip_address' => $ip,
        ]);
    }
}
