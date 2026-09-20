<?php

namespace App\Models;

use App\Enums\LoginStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * One row per sign-in attempt against an existing account. Stores no email,
 * password or token value; only who, when, from where, and the outcome.
 */
class LoginActivity extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => LoginStatus::class,
            'login_at' => 'datetime',
            'logout_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(User $user, LoginStatus $status, Request $request, ?int $tokenId = null): self
    {
        return self::create([
            'user_id' => $user->id,
            'token_id' => $tokenId,
            'status' => $status,
            'ip_address' => $request->ip(),
            // Bounded: the header is client-controlled.
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
            'login_at' => now(),
        ]);
    }

    /** Stamp the logout time on the still-open session that used this token. */
    public static function recordLogout(?int $tokenId): void
    {
        if ($tokenId === null) {
            return;
        }

        self::query()->where('token_id', $tokenId)->whereNull('logout_at')->update(['logout_at' => now()]);
    }
}
