<?php

namespace App\Support\Reports;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate figures about tickets. Only counts and durations leave this class: never a
 * subject, a message or a customer detail.
 *
 * Definitions used throughout (the web app repeats them next to the numbers):
 *  - "created" / "resolved": tickets whose created_at / resolved_at falls in the period.
 *    A reopened ticket loses its resolved_at, so it counts as resolved again only when it is
 *    resolved again.
 *  - "open now", "unassigned", ageing: a snapshot of today, whatever the period.
 *  - resolution time: created_at to resolved_at.
 *  - first response: from creation to the first public reply written by someone other than
 *    the ticket's customer (internal notes and the customer's own messages do not count).
 *  - agent figures follow the ticket's current assignee; assignment history is not replayed.
 */
final class TicketReports
{
    /**
     * @param  int|string|null  $department  a department id, "none" (tickets without one) or null (all)
     */
    public function __construct(private readonly ReportRange $range, private readonly int|string|null $department = null) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $bounds = [$this->range->fromDb(), $this->range->toDb()];
        $resolved = fn () => $this->tickets()->whereNotNull('resolved_at')->whereBetween('resolved_at', $bounds);
        $resolvedCount = $resolved()->count();
        $reopened = $this->reopenedTickets();
        $first = $this->firstResponseQuery()->whereBetween('t.created_at', $bounds);
        $firstStats = DB::query()->fromSub($first, 'r')
            ->selectRaw('count(*) as answered, avg('.$this->seconds('r.ticket_created_at', 'r.first_at').') as avg_seconds')
            ->first();
        $open = fn () => $this->tickets()->whereIn('status', [TicketStatus::Open->value, TicketStatus::InProgress->value]);

        return [
            'created' => $this->tickets()->whereBetween('created_at', $bounds)->count(),
            'resolved' => $resolvedCount,
            'open_now' => $open()->count(),
            'unassigned_now' => $open()->whereNull('assigned_to')->count(),
            'avg_resolution_seconds' => $this->whole($resolved()->avg(DB::raw($this->seconds('created_at', 'resolved_at')))),
            'avg_first_response_seconds' => $this->whole($firstStats->avg_seconds),
            'answered' => (int) $firstStats->answered,
            'reopened' => $reopened,
            'reopen_rate' => $resolvedCount > 0 ? round($reopened / $resolvedCount, 4) : null,
        ];
    }

    /**
     * Tickets opened and resolved per day (per week for long periods), empty days included.
     *
     * @return list<array{date: string, created: int, resolved: int}>
     */
    public function timeline(): array
    {
        $bounds = [$this->range->fromDb(), $this->range->toDb()];
        $weekly = $this->range->granularity() === 'week';
        $buckets = [];

        // Empty buckets first, so a quiet day still shows up as zero.
        for ($day = $this->range->from->startOfDay(); $day <= $this->range->to; $day = $day->addDay()) {
            $buckets[$this->bucket($day, $weekly)] ??= ['date' => $this->bucket($day, $weekly), 'created' => 0, 'resolved' => 0];
        }

        // Grouped in PHP rather than SQL: time zones with daylight saving cannot be grouped
        // correctly with a fixed offset, and a helpdesk's volume is small.
        foreach ($this->tickets()->whereBetween('created_at', $bounds)->pluck('created_at') as $at) {
            $buckets[$this->bucketOf($at, $weekly)]['created']++;
        }
        foreach ($this->tickets()->whereNotNull('resolved_at')->whereBetween('resolved_at', $bounds)->pluck('resolved_at') as $at) {
            $buckets[$this->bucketOf($at, $weekly)]['resolved']++;
        }

        return array_values($buckets);
    }

    /**
     * Where tickets stand: by status and priority (tickets created in the period), by department,
     * and how long the still-open ones have waited.
     *
     * @return array<string, mixed>
     */
    public function breakdown(): array
    {
        $bounds = [$this->range->fromDb(), $this->range->toDb()];
        $cohort = fn () => $this->tickets()->whereBetween('created_at', $bounds);

        $byStatus = $cohort()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $byPriority = $cohort()->selectRaw('priority, count(*) as n')->groupBy('priority')->pluck('n', 'priority');

        return [
            'status' => collect(TicketStatus::cases())->map(fn ($s) => ['key' => $s->value, 'count' => (int) ($byStatus[$s->value] ?? 0)])->all(),
            'priority' => collect(TicketPriority::cases())->map(fn ($p) => ['key' => $p->value, 'count' => (int) ($byPriority[$p->value] ?? 0)])->all(),
            'departments' => $this->departments(),
            'ageing' => $this->ageing(),
        ];
    }

    /**
     * One row per agent (and anyone else who currently holds tickets).
     *
     * @return list<array<string, mixed>>
     */
    public function agents(): array
    {
        $bounds = [$this->range->fromDb(), $this->range->toDb()];

        $open = $this->tickets()->whereNotNull('assigned_to')
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::InProgress->value])
            ->selectRaw('assigned_to, count(*) as n')->groupBy('assigned_to')->pluck('n', 'assigned_to');

        $resolved = $this->tickets()->whereNotNull('assigned_to')->whereNotNull('resolved_at')->whereBetween('resolved_at', $bounds)
            ->selectRaw('assigned_to, count(*) as n, avg('.$this->seconds('created_at', 'resolved_at').') as avg_seconds')
            ->groupBy('assigned_to')->get()->keyBy('assigned_to');

        $first = DB::query()->fromSub($this->firstResponseQuery()->whereNotNull('t.assigned_to')->whereBetween('t.created_at', $bounds), 'r')
            ->selectRaw('assigned_to, avg('.$this->seconds('r.ticket_created_at', 'r.first_at').') as avg_seconds')
            ->groupBy('assigned_to')->get()->keyBy('assigned_to');

        $ids = collect($open->keys())->merge($resolved->keys())->merge($first->keys())->all();
        $people = User::query()
            ->where(fn ($q) => $q->whereIn('id', $ids)->orWhere(fn ($agents) => $agents->role('agent')->where('status', 'active')))
            ->orderBy('name')->get(['id', 'name']);

        return $people->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'open_now' => (int) ($open[$user->id] ?? 0),
            'resolved' => (int) ($resolved[$user->id]->n ?? 0),
            'avg_resolution_seconds' => $this->whole($resolved[$user->id]->avg_seconds ?? null),
            'avg_first_response_seconds' => $this->whole($first[$user->id]->avg_seconds ?? null),
        ])->sortByDesc('resolved')->values()->all();
    }

    // ---- Building blocks ----------------------------------------------------

    private function tickets(string $alias = 'tickets'): Builder
    {
        $query = DB::table($alias === 'tickets' ? 'tickets' : "tickets as {$alias}");
        $column = $alias === 'tickets' ? 'department_id' : "{$alias}.department_id";

        return match (true) {
            $this->department === 'none' => $query->whereNull($column),
            $this->department !== null => $query->where($column, (int) $this->department),
            default => $query,
        };
    }

    /** Each ticket with the time of its first public reply from someone other than its customer. */
    private function firstResponseQuery(): Builder
    {
        return $this->tickets('t')
            ->join('ticket_messages as m', 'm.ticket_id', '=', 't.id')
            ->where('m.type', 'public_reply')
            ->whereColumn('m.user_id', '<>', 't.customer_id')
            ->groupBy('t.id', 't.created_at', 't.assigned_to')
            ->selectRaw('t.id as ticket_id, t.assigned_to as assigned_to, t.created_at as ticket_created_at, min(m.created_at) as first_at');
    }

    /** Tickets reopened (resolved or closed, then worked on again) during the period. */
    private function reopenedTickets(): int
    {
        $rows = $this->tickets('t')
            ->join('ticket_events as e', 'e.ticket_id', '=', 't.id')
            ->where('e.type', 'status_changed')
            ->whereBetween('e.created_at', [$this->range->fromDb(), $this->range->toDb()])
            ->get(['e.ticket_id', 'e.data']);

        return $rows->filter(function ($row) {
            $data = json_decode((string) $row->data, true) ?: [];

            return in_array($data['from'] ?? null, [TicketStatus::Resolved->value, TicketStatus::Closed->value], true)
                && in_array($data['to'] ?? null, [TicketStatus::Open->value, TicketStatus::InProgress->value], true);
        })->pluck('ticket_id')->unique()->count();
    }

    /** @return list<array<string, mixed>> */
    private function departments(): array
    {
        $bounds = [$this->range->fromDb(), $this->range->toDb()];
        $key = fn ($row) => $row->department_id === null ? 'none' : (int) $row->department_id;

        $created = $this->tickets()->whereBetween('created_at', $bounds)->selectRaw('department_id, count(*) as n')->groupBy('department_id')->get()->keyBy($key);
        $open = $this->tickets()->whereIn('status', [TicketStatus::Open->value, TicketStatus::InProgress->value])->selectRaw('department_id, count(*) as n')->groupBy('department_id')->get()->keyBy($key);
        $resolved = $this->tickets()->whereNotNull('resolved_at')->whereBetween('resolved_at', $bounds)
            ->selectRaw('department_id, count(*) as n, avg('.$this->seconds('created_at', 'resolved_at').') as avg_seconds')->groupBy('department_id')->get()->keyBy($key);

        $rows = Department::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name])
            ->push(['id' => 'none', 'name' => null]);

        return $rows
            ->filter(fn ($row) => $this->department === null || (string) $this->department === (string) $row['id'])
            ->map(fn ($row) => [
                'id' => $row['id'],
                'name' => $row['name'],
                'created' => (int) ($created[$row['id']]->n ?? 0),
                'resolved' => (int) ($resolved[$row['id']]->n ?? 0),
                'open_now' => (int) ($open[$row['id']]->n ?? 0),
                'avg_resolution_seconds' => $this->whole($resolved[$row['id']]->avg_seconds ?? null),
            ])->values()->all();
    }

    /** @return list<array{key: string, count: int}> */
    private function ageing(): array
    {
        $open = fn () => $this->tickets()->whereIn('status', [TicketStatus::Open->value, TicketStatus::InProgress->value]);
        $ago = fn (int $days) => now()->subDays($days)->format('Y-m-d H:i:s');

        return [
            ['key' => 'under_1_day', 'count' => $open()->where('created_at', '>', $ago(1))->count()],
            ['key' => '1_to_3_days', 'count' => $open()->where('created_at', '<=', $ago(1))->where('created_at', '>', $ago(3))->count()],
            ['key' => '3_to_7_days', 'count' => $open()->where('created_at', '<=', $ago(3))->where('created_at', '>', $ago(7))->count()],
            ['key' => 'over_7_days', 'count' => $open()->where('created_at', '<=', $ago(7))->count()],
        ];
    }

    /** Seconds between two timestamp columns, written for the database in use. */
    private function seconds(string $from, string $to): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "((julianday({$to}) - julianday({$from})) * 86400.0)"
            : "TIMESTAMPDIFF(SECOND, {$from}, {$to})";
    }

    private function whole(mixed $value): ?int
    {
        return $value === null ? null : (int) round((float) $value);
    }

    private function bucket(CarbonImmutable $localDay, bool $weekly): string
    {
        return ($weekly ? $localDay->startOfWeek() : $localDay)->format('Y-m-d');
    }

    /** The bucket a stored (UTC) timestamp falls into, by the reporting time zone's calendar. */
    private function bucketOf(string $storedAt, bool $weekly): string
    {
        return $this->bucket(
            CarbonImmutable::parse($storedAt, config('app.timezone'))->setTimezone($this->range->timezone),
            $weekly,
        );
    }
}
