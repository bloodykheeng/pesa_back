<?php

namespace Tests\Feature\Admin;

use App\Models\Ad;
use App\Support\DateBounds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * The timezone rules (docs/timezones.md): UTC stored and queried, a day is the Kampala day, a time is used exactly. * Kampala is UTC+3: its day runs 21:00:00 to 20:59:59 UTC.
 */
class TimezonesTest extends AdminTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_database_session_is_utc_whatever_the_server_zone(): void
    {
        // Contabo's MySQL runs on Europe/Berlin and the laptop's on Kampala: the connection must be UTC on both.
        $this->assertSame('+00:00', DB::selectOne('select @@session.time_zone as z')->z);
    }

    public function test_a_bare_day_is_the_whole_kampala_day_and_a_time_is_used_exactly(): void
    {
        $this->assertSame('2026-11-03 21:00:00', DateBounds::startInstant('2026-11-04')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-04 20:59:59', DateBounds::endInstant('2026-11-04')->format('Y-m-d H:i:s'));
        // An instant keeps its moment; a naive time is Kampala wall-clock.
        $this->assertSame('2026-11-04 10:00:00', DateBounds::endInstant('2026-11-04T13:00:00+03:00')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-03 21:00:00', DateBounds::startInstant('2026-11-03T21:00:00.000Z')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-04 10:00:00', DateBounds::startInstant('2026-11-04 13:00:00')->format('Y-m-d H:i:s'));
        // 22:30 UTC on the 3rd is 01:30 on the 4th in Kampala.
        $this->assertSame('2026-11-04', DateBounds::businessDayOf(Carbon::parse('2026-11-03 22:30:00', 'UTC')));
        $this->assertSame('1990-01-01', DateBounds::businessDayOf('1990-01-01'));
    }

    public function test_lists_and_the_dashboard_honour_a_picked_time_and_refuse_a_bad_date(): void
    {
        $c = $this->customer();
        $this->order($c, ['created_at' => '2026-01-05 09:30:00']); // 12:30 in Kampala
        $this->order($c, ['created_at' => '2026-01-05 13:30:00']); // 16:30 in Kampala
        Sanctum::actingAs($this->admin());

        // 1 pm to 3 pm stays 1 pm to 3 pm, never the whole day.
        $from = urlencode('2026-01-05T12:00:00+03:00');
        $to = urlencode('2026-01-05T15:00:00+03:00');
        $this->getJson("/api/admin/orders?filter[from]={$from}&filter[to]={$to}")->assertJsonPath('meta.total', 1);
        $this->getJson("/api/admin/dashboard?from={$from}&to={$to}")->assertJsonPath('orders.count', 1);
        $this->getJson('/api/admin/dashboard?from=2026-01-05&to=2026-01-05')->assertJsonPath('orders.count', 2);

        $this->getJson('/api/admin/orders?filter[from]=not-a-date')->assertStatus(422);
    }

    public function test_the_dashboard_puts_an_order_in_its_kampala_month(): void
    {
        $c = $this->customer();
        // 22:30 UTC on 28 Feb is 01:30 on 1 March in Kampala.
        $this->order($c, ['created_at' => '2026-02-28 22:30:00']);
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/dashboard')->assertJsonPath('monthly.0.month', '2026-03');
        $this->getJson('/api/admin/dashboard?from=2026-03-01&to=2026-03-31')->assertJsonPath('orders.count', 1);
        $this->getJson('/api/admin/dashboard?from=2026-02-01&to=2026-02-28')->assertJsonPath('orders.count', 0);
    }

    public function test_the_old_admin_api_counts_kampala_days_too(): void
    {
        $c = $this->customer();
        $this->order($c, ['created_at' => '2025-12-31 22:30:00']); // 1 Jan, 01:30 in Kampala
        $this->order($c, ['created_at' => '2025-12-31 20:00:00']); // 31 Dec, 23:00 in Kampala
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/order-statistics?startDate=2026-01-01&endDate=2026-01-01')->assertJsonPath('data.total_orders', 1);
        $this->getJson('/api/order-statistics?startDate=2025-12-31&endDate=2025-12-31')->assertJsonPath('data.total_orders', 1);
    }

    public function test_an_ad_day_is_stored_as_its_first_kampala_moment_and_runs_all_of_its_last_day(): void
    {
        $admin = $this->admin();
        $ad = Ad::forceCreate(['title' => 'Gas week', 'status' => 'active', 'start_date' => '2026-11-24', 'end_date' => '2026-11-30', 'created_by' => $admin->id, 'updated_by' => $admin->id]);

        // The raw stored values: the first moment of each Kampala day, in UTC (never UTC midnight).
        $raw = DB::table('ads')->where('id', $ad->id)->first();
        $this->assertSame('2026-11-23 21:00:00', $raw->start_date);
        $this->assertSame('2026-11-29 21:00:00', $raw->end_date);
        $this->assertSame('2026-11-23T21:00:00.000000Z', $ad->fresh()->toArray()['start_date']);

        // A time with an offset is kept exactly (Laravel's datetime cast would store 09:30 as UTC).
        $ad->update(['start_date' => '2026-11-24T09:30:00+03:00']);
        $this->assertSame('2026-11-24 06:30:00', DB::table('ads')->where('id', $ad->id)->value('start_date'));

        Sanctum::actingAs($this->customer());
        Carbon::setTestNow('2026-11-30 20:30:00'); // 23:30 on 30 Nov in Kampala: still running
        $this->getJson('/api/app-get-ads')->assertJsonCount(1);
        Carbon::setTestNow('2026-11-30 21:30:00'); // 00:30 on 1 Dec: over
        $this->getJson('/api/app-get-ads')->assertJsonCount(0);
    }
}
