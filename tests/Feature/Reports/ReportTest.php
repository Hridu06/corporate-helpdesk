<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleName;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Every expected number below was worked out by hand from the data set in dataset().
 * "Today" is fixed at 2026-09-21 12:00 UTC, so the default period is 2026-08-23 .. 2026-09-21.
 */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $agentA;

    private User $agentB;

    private User $customer;

    private Department $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-09-21 12:00:00');
        $this->admin = $this->user(RoleName::Admin);
        $this->agentA = $this->user(RoleName::Agent, ['name' => 'Agent A']);
        $this->agentB = $this->user(RoleName::Agent, ['name' => 'Agent B']);
        $this->customer = $this->user(RoleName::Customer, ['email' => 'private.customer@example.org']);
        $this->billing = Department::create(['name' => 'Billing', 'description' => 'x', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(RoleName $role, array $attributes = []): User
    {
        return User::factory()->create($attributes)->assignRole($role->value);
    }

    private function ticket(array $attributes): Ticket
    {
        return Ticket::factory()->create(array_merge(['customer_id' => $this->customer->id, 'subject' => 'PRIVATE-SUBJECT'], $attributes));
    }

    private function message(Ticket $ticket, User $author, string $type, string $at): void
    {
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $author->id, 'type' => $type, 'body' => 'PRIVATE-BODY', 'created_at' => $at, 'updated_at' => $at]);
    }

    private function event(Ticket $ticket, string $from, string $to, string $at): void
    {
        TicketEvent::create(['ticket_id' => $ticket->id, 'actor_id' => $this->agentA->id, 'type' => 'status_changed', 'data' => ['from' => $from, 'to' => $to], 'created_at' => $at]);
    }

    /**
     * T1 closed, Billing, agent A: 24 h to resolve, first reply after 2 h, later reopened
     * T2 resolved, no dept, agent A: 12 h to resolve; the customer wrote first, agent A replied after 1 h
     * T3 open, Billing, agent B: an internal note at +30 min (must not count), first public reply at +6 h
     * T4 in progress, no dept, unassigned, created today at 06:00, no reply
     * T5 closed, created and resolved before the period
     * T7 open, no dept, agent B, 2 days old      T8 open, no dept, agent B, 16 days old
     */
    private function dataset(): void
    {
        $t1 = $this->ticket(['status' => TicketStatus::Closed, 'priority' => TicketPriority::High, 'department_id' => $this->billing->id, 'assigned_to' => $this->agentA->id, 'created_at' => '2026-09-01 10:00:00', 'resolved_at' => '2026-09-02 10:00:00', 'closed_at' => '2026-09-03 10:00:00']);
        $this->message($t1, $this->agentA, 'public_reply', '2026-09-01 12:00:00');
        $this->event($t1, 'closed', 'in_progress', '2026-09-05 09:00:00');

        $t2 = $this->ticket(['status' => TicketStatus::Resolved, 'priority' => TicketPriority::Low, 'assigned_to' => $this->agentA->id, 'created_at' => '2026-09-10 08:00:00', 'resolved_at' => '2026-09-10 20:00:00']);
        $this->message($t2, $this->customer, 'public_reply', '2026-09-10 08:30:00');
        $this->message($t2, $this->agentA, 'public_reply', '2026-09-10 09:00:00');

        $t3 = $this->ticket(['status' => TicketStatus::Open, 'priority' => TicketPriority::High, 'department_id' => $this->billing->id, 'assigned_to' => $this->agentB->id, 'created_at' => '2026-09-15 09:00:00']);
        $this->message($t3, $this->agentB, 'internal_note', '2026-09-15 09:30:00');
        $this->message($t3, $this->agentB, 'public_reply', '2026-09-15 15:00:00');

        $t4 = $this->ticket(['status' => TicketStatus::InProgress, 'priority' => TicketPriority::Urgent, 'created_at' => '2026-09-21 06:00:00']);
        $this->event($t4, 'open', 'in_progress', '2026-09-21 07:00:00');

        $this->ticket(['status' => TicketStatus::Closed, 'assigned_to' => $this->agentA->id, 'created_at' => '2026-08-01 10:00:00', 'resolved_at' => '2026-08-05 10:00:00', 'closed_at' => '2026-08-05 11:00:00']);
        $this->ticket(['status' => TicketStatus::Open, 'assigned_to' => $this->agentB->id, 'created_at' => '2026-09-19 12:00:00']);
        $this->ticket(['status' => TicketStatus::Open, 'assigned_to' => $this->agentB->id, 'created_at' => '2026-09-05 00:00:00']);
    }

    private function report(string $path, ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->admin);

        return $this->getJson("/api/reports/{$path}");
    }

    // ---- Access -------------------------------------------------------------

    public function test_only_people_with_the_report_permission_get_in(): void
    {
        foreach (['options', 'overview', 'timeline', 'breakdown', 'agents'] as $path) {
            $this->app['auth']->forgetGuards();
            $this->getJson("/api/reports/{$path}")->assertUnauthorized();
            $this->report($path, $this->customer)->assertForbidden();
            $this->report($path, $this->agentA)->assertForbidden();
            $this->report($path, $this->admin)->assertOk();
            $this->report($path, $this->user(RoleName::SuperAdmin))->assertOk();
        }
    }

    public function test_access_follows_the_permission_not_the_role_name(): void
    {
        $this->agentA->givePermissionTo('report.view');
        $this->report('overview', $this->agentA)->assertOk();

        $this->admin->roles->first()->revokePermissionTo('report.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->report('overview', $this->admin->fresh())->assertForbidden();
    }

    public function test_a_deactivated_admin_is_refused(): void
    {
        $this->admin->forceFill(['status' => UserStatus::Inactive])->save();

        $this->report('overview')->assertForbidden();
    }

    public function test_the_answers_contain_only_numbers_and_agent_names(): void
    {
        $this->dataset();

        foreach (['overview', 'timeline', 'breakdown', 'agents'] as $path) {
            $body = $this->report($path)->assertOk()->getContent();
            foreach (['PRIVATE-SUBJECT', 'PRIVATE-BODY', 'private.customer@example.org', $this->customer->name] as $secret) {
                $this->assertStringNotContainsString($secret, $body, "{$path} leaked {$secret}");
            }
        }
    }

    // ---- Overview -----------------------------------------------------------

    public function test_the_overview_matches_the_hand_worked_numbers(): void
    {
        $this->dataset();

        $this->report('overview')->assertOk()
            ->assertJsonPath('range', ['from' => '2026-08-23', 'to' => '2026-09-21', 'timezone' => 'UTC', 'days' => 30, 'granularity' => 'day'])
            ->assertJsonPath('data.created', 6)                       // T1 T2 T3 T4 T7 T8 (T5 is older)
            ->assertJsonPath('data.resolved', 2)                      // T1 T2
            ->assertJsonPath('data.open_now', 4)                      // T3 T4 T7 T8
            ->assertJsonPath('data.unassigned_now', 1)                // T4
            ->assertJsonPath('data.avg_resolution_seconds', 64800)    // (24 h + 12 h) / 2
            ->assertJsonPath('data.answered', 3)                      // T1 T2 T3
            ->assertJsonPath('data.avg_first_response_seconds', 10800) // (2 h + 1 h + 6 h) / 3
            ->assertJsonPath('data.reopened', 1)                      // T1 (T4's open -> in progress is not a reopen)
            ->assertJsonPath('data.reopen_rate', 0.5);                // 1 reopened / 2 resolved
    }

    public function test_the_customers_own_messages_and_internal_notes_are_not_a_first_response(): void
    {
        $this->dataset();

        // If T2's customer message (+30 min) or T3's internal note (+30 min) counted, the average would drop to 5400.
        $this->report('overview')->assertJsonPath('data.avg_first_response_seconds', 10800);
    }

    public function test_an_empty_helpdesk_reports_zeros_and_no_averages(): void
    {
        $this->report('overview')->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.resolved', 0)
            ->assertJsonPath('data.open_now', 0)
            ->assertJsonPath('data.avg_resolution_seconds', null)
            ->assertJsonPath('data.avg_first_response_seconds', null)
            ->assertJsonPath('data.reopen_rate', null);

        $timeline = $this->report('timeline')->json('data');
        $this->assertCount(30, $timeline);
        $this->assertSame(0, array_sum(array_column($timeline, 'created')));
    }

    public function test_a_custom_period_counts_only_that_period_but_open_now_is_a_snapshot(): void
    {
        $this->dataset();

        $this->report('overview?from=2026-09-10&to=2026-09-15')->assertOk()
            ->assertJsonPath('range.days', 6)
            ->assertJsonPath('data.created', 2)          // T2 (09-10) and T3 (09-15 09:00)
            ->assertJsonPath('data.resolved', 1)         // T2, resolved 09-10 20:00
            ->assertJsonPath('data.open_now', 4);        // unaffected by the period
    }

    public function test_the_end_of_the_last_day_is_included(): void
    {
        $this->ticket(['created_at' => '2026-09-15 23:59:59']);
        $this->ticket(['created_at' => '2026-09-16 00:00:00']);

        $this->report('overview?from=2026-09-15&to=2026-09-15')->assertJsonPath('data.created', 1);
    }

    public function test_the_department_filter_narrows_every_figure(): void
    {
        $this->dataset();

        $this->report("overview?department_id={$this->billing->id}")->assertOk()
            ->assertJsonPath('data.created', 2)                        // T1 T3
            ->assertJsonPath('data.resolved', 1)                       // T1
            ->assertJsonPath('data.open_now', 1)                       // T3
            ->assertJsonPath('data.unassigned_now', 0)
            ->assertJsonPath('data.avg_resolution_seconds', 86400)
            ->assertJsonPath('data.avg_first_response_seconds', 14400) // (2 h + 6 h) / 2
            ->assertJsonPath('data.reopened', 1)
            ->assertJsonPath('data.reopen_rate', 1);

        $this->report('overview?department_id=none')->assertOk()
            ->assertJsonPath('data.created', 4)                        // T2 T4 T7 T8
            ->assertJsonPath('data.resolved', 1)                       // T2
            ->assertJsonPath('data.open_now', 3)                       // T4 T7 T8
            ->assertJsonPath('data.unassigned_now', 1)
            ->assertJsonPath('data.avg_resolution_seconds', 43200)
            ->assertJsonPath('data.avg_first_response_seconds', 3600)
            ->assertJsonPath('data.reopened', 0)
            ->assertJsonPath('data.reopen_rate', 0);
    }

    // ---- Timeline -----------------------------------------------------------

    public function test_the_timeline_has_one_bucket_per_day_with_the_right_counts(): void
    {
        $this->dataset();

        $timeline = collect($this->report('timeline')->assertOk()->json('data'));

        $this->assertCount(30, $timeline);
        $this->assertSame('2026-08-23', $timeline->first()['date']);
        $this->assertSame('2026-09-21', $timeline->last()['date']);
        $this->assertSame(
            ['2026-09-01' => 1, '2026-09-05' => 1, '2026-09-10' => 1, '2026-09-15' => 1, '2026-09-19' => 1, '2026-09-21' => 1],
            $timeline->where('created', '>', 0)->pluck('created', 'date')->all(),
        );
        $this->assertSame(['2026-09-02' => 1, '2026-09-10' => 1], $timeline->where('resolved', '>', 0)->pluck('resolved', 'date')->all());
    }

    public function test_long_periods_group_by_week_starting_on_monday(): void
    {
        $this->dataset();

        $response = $this->report('timeline?from=2026-01-01&to=2026-09-21')->assertOk();
        $timeline = collect($response->json('data'));

        $response->assertJsonPath('range.granularity', 'week');
        $this->assertSame('2025-12-29', $timeline->first()['date']); // the Monday on or before 2026-01-01
        $this->assertSame(7, $timeline->sum('created'));            // all seven tickets, none lost or doubled
        $this->assertSame(3, $timeline->sum('resolved'));           // T1 T2 T5
        $this->assertTrue($timeline->every(fn ($b) => Carbon::parse($b['date'])->isMonday()));
    }

    public function test_days_follow_the_reporting_time_zone(): void
    {
        Carbon::setTestNow('2026-09-22 12:00:00'); // 18:00 on the 22nd in Dhaka
        config(['helpdesk.reports_timezone' => 'Asia/Dhaka']);
        $this->ticket(['created_at' => '2026-09-20 17:59:00']); // 23:59 on the 20th in Dhaka: outside
        $this->ticket(['created_at' => '2026-09-20 19:00:00']); // 01:00 on the 21st: inside
        $this->ticket(['created_at' => '2026-09-21 17:59:00']); // 23:59 on the 21st: inside
        $this->ticket(['created_at' => '2026-09-21 18:30:00']); // 00:30 on the 22nd: outside

        $response = $this->report('timeline?from=2026-09-21&to=2026-09-21')->assertOk();

        $response->assertJsonPath('range.timezone', 'Asia/Dhaka');
        $this->assertSame([['date' => '2026-09-21', 'created' => 2, 'resolved' => 0]], $response->json('data'));
        $this->report('overview?from=2026-09-21&to=2026-09-21')->assertJsonPath('data.created', 2);
    }

    // ---- Breakdown ----------------------------------------------------------

    public function test_the_breakdown_counts_status_priority_department_and_ageing(): void
    {
        $this->dataset();
        Department::create(['name' => 'Support', 'description' => 'x', 'is_active' => true]);

        $data = $this->report('breakdown')->assertOk()->json('data');

        $this->assertSame(['open' => 3, 'in_progress' => 1, 'resolved' => 1, 'closed' => 1], collect($data['status'])->pluck('count', 'key')->all());
        $this->assertSame(['low' => 1, 'medium' => 2, 'high' => 2, 'urgent' => 1], collect($data['priority'])->pluck('count', 'key')->all());

        $departments = collect($data['departments'])->keyBy(fn ($d) => (string) $d['id']);
        $this->assertSame(['created' => 2, 'resolved' => 1, 'open_now' => 1, 'avg_resolution_seconds' => 86400], collect($departments[(string) $this->billing->id])->only(['created', 'resolved', 'open_now', 'avg_resolution_seconds'])->all());
        $this->assertSame(['created' => 4, 'resolved' => 1, 'open_now' => 3, 'avg_resolution_seconds' => 43200], collect($departments['none'])->only(['created', 'resolved', 'open_now', 'avg_resolution_seconds'])->all());
        $this->assertSame(0, $departments->first(fn ($d) => $d['name'] === 'Support')['created']); // departments without tickets still listed

        // Open now: T4 6 h old, T7 2 days, T3 6 days, T8 16 days.
        $this->assertSame(
            ['under_1_day' => 1, '1_to_3_days' => 1, '3_to_7_days' => 1, 'over_7_days' => 1],
            collect($data['ageing'])->pluck('count', 'key')->all(),
        );
    }

    public function test_the_breakdown_can_be_limited_to_one_department(): void
    {
        $this->dataset();

        $data = $this->report("breakdown?department_id={$this->billing->id}")->json('data');

        $this->assertCount(1, $data['departments']);
        $this->assertSame($this->billing->id, $data['departments'][0]['id']);
        $this->assertSame(1, collect($data['status'])->firstWhere('key', 'open')['count']); // T3
    }

    // ---- Agents -------------------------------------------------------------

    public function test_the_agent_table_matches_the_hand_worked_numbers(): void
    {
        $this->dataset();
        $this->user(RoleName::Agent, ['name' => 'Agent C']); // an idle agent still appears

        $agents = collect($this->report('agents')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(['Agent A', 'Agent B', 'Agent C'], $agents->keys()->all()); // most resolved first, then by name
        $this->assertSame(
            ['open_now' => 0, 'resolved' => 2, 'avg_resolution_seconds' => 64800, 'avg_first_response_seconds' => 5400], // (2 h + 1 h) / 2
            collect($agents['Agent A'])->only(['open_now', 'resolved', 'avg_resolution_seconds', 'avg_first_response_seconds'])->all(),
        );
        $this->assertSame(
            ['open_now' => 3, 'resolved' => 0, 'avg_resolution_seconds' => null, 'avg_first_response_seconds' => 21600],
            collect($agents['Agent B'])->only(['open_now', 'resolved', 'avg_resolution_seconds', 'avg_first_response_seconds'])->all(),
        );
        $this->assertSame(
            ['open_now' => 0, 'resolved' => 0, 'avg_resolution_seconds' => null, 'avg_first_response_seconds' => null],
            collect($agents['Agent C'])->only(['open_now', 'resolved', 'avg_resolution_seconds', 'avg_first_response_seconds'])->all(),
        );
        $this->assertArrayNotHasKey('email', $agents['Agent A']);
    }

    // ---- Filter options -----------------------------------------------------

    public function test_the_admin_can_list_departments_for_the_filter_without_the_ticket_create_permission(): void
    {
        // The regression a live run found: the "new ticket" list (/tickets/options) refuses an admin.
        $this->assertFalse($this->admin->can('ticket.create'));
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/tickets/options')->assertForbidden();

        $retired = Department::create(['name' => 'Archive', 'description' => 'x', 'is_active' => false]);

        $response = $this->report('options')->assertOk();

        $this->assertSame(
            [['id' => $retired->id, 'name' => 'Archive'], ['id' => $this->billing->id, 'name' => 'Billing']], // by name, retired ones included
            $response->json('departments'),
        );
    }

    // ---- Presets ------------------------------------------------------------

    public function test_a_days_preset_ends_today_in_the_reporting_time_zone(): void
    {
        $this->dataset();

        $this->report('overview?days=7')->assertOk()
            ->assertJsonPath('range.from', '2026-09-15')
            ->assertJsonPath('range.to', '2026-09-21')
            ->assertJsonPath('range.days', 7)
            ->assertJsonPath('data.created', 3); // T3 (09-15), T7 (09-19), T4 (09-21)

        $this->report('overview?days=1')->assertJsonPath('range.from', '2026-09-21')->assertJsonPath('data.created', 1);
        $this->report('overview?days=366')->assertOk()->assertJsonPath('range.days', 366);
    }

    public function test_the_preset_uses_the_reporting_time_zones_today_not_utcs(): void
    {
        Carbon::setTestNow('2026-09-21 20:00:00'); // already 02:00 on the 22nd in Dhaka
        config(['helpdesk.reports_timezone' => 'Asia/Dhaka']);

        $this->report('overview?days=3')->assertOk()->assertJsonPath('range.to', '2026-09-22')->assertJsonPath('range.from', '2026-09-20');
    }

    public function test_an_explicit_period_wins_over_a_preset(): void
    {
        $this->report('overview?days=7&from=2026-09-01&to=2026-09-10')->assertOk()->assertJsonPath('range.days', 10);
        $this->report('overview?days=7&from=2026-09-01')->assertOk()->assertJsonPath('range.from', '2026-09-01');
    }

    public function test_a_bad_preset_is_refused(): void
    {
        foreach (['0', '-3', '367', 'abc', '1.5', '7%20days'] as $days) {
            $this->report("overview?days={$days}")->assertUnprocessable()->assertJsonValidationErrors('days');
        }
    }

    // ---- Validation ---------------------------------------------------------

    public function test_bad_periods_and_filters_are_refused(): void
    {
        $this->report('overview?from=2026-09-10&to=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->report('overview?from=2025-01-01&to=2026-09-21')->assertUnprocessable()->assertJsonValidationErrors('from'); // over 366 days
        $this->report('overview?from=yesterday')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->report('overview?from=2026-13-40')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->report('overview?to=2026-09-22')->assertUnprocessable()->assertJsonValidationErrors('to');                  // tomorrow
        $this->report('overview?department_id=abc')->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->report('overview?department_id=0')->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->report('overview?department_id=1%20OR%201=1')->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->report('overview?department_id[]=1')->assertUnprocessable();
    }

    public function test_the_longest_allowed_period_and_the_none_filter_are_accepted(): void
    {
        $this->report('overview?from=2025-09-21&to=2026-09-21')->assertOk()->assertJsonPath('range.days', 366);
        $this->report('overview?department_id=none')->assertOk();
        $this->report('overview?department_id=99999')->assertOk()->assertJsonPath('data.created', 0);
        $this->report('overview?to=2026-09-21')->assertOk(); // today is fine
    }
}
