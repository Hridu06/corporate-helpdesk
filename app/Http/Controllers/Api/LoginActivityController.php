<?php

namespace App\Http\Controllers\Api;

use App\Enums\LoginStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LoginActivityResource;
use App\Models\LoginActivity;
use Illuminate\Http\Request;

class LoginActivityController extends Controller
{
    /** Authorisation (`activity.view`) is declared on the route via LoginActivityPolicy. */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:'.implode(',', array_column(LoginStatus::cases(), 'value'))],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $activities = LoginActivity::query()
            ->with('user:id,name,email')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['user_id'] ?? null, fn ($query, int $userId) => $query->where('user_id', $userId))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->whereDate('login_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->whereDate('login_at', '<=', $to))
            ->when($filters['search'] ?? null, function ($query, string $term) {
                // Escape LIKE wildcards so the term is matched literally.
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->whereHas('user', fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->latest('login_at')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15);

        return LoginActivityResource::collection($activities);
    }
}
