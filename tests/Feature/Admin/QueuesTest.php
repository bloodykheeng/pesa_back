<?php

namespace Tests\Feature\Admin;

use App\Jobs\SendAdminNotification;
use App\Jobs\SendPush;
use App\Models\AdminNotification;
use App\Support\Push;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/** Settings › Queues: waiting and failed jobs, retry, delete; and pushes that never hold up a request. */
class QueuesTest extends AdminTestCase
{
    private function payload(object $job): string
    {
        return json_encode([
            'uuid' => (string) Str::uuid(),
            'displayName' => $job::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => $job::class, 'command' => serialize($job)],
        ]);
    }

    private function message(): AdminNotification
    {
        return AdminNotification::forceCreate([
            'title' => 'Gas prices drop', 'body' => 'Refill for less this week.', 'audience' => 'all',
            'send_push' => true, 'send_email' => false, 'status' => 'queued', 'created_by' => $this->admin()->id,
        ]);
    }

    public function test_admins_see_waiting_and_failed_jobs_in_words(): void
    {
        $message = $this->message();
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => $this->payload(new SendAdminNotification($message->id)), 'attempts' => 0,
            'reserved_at' => null, 'available_at' => time() - 600, 'created_at' => time() - 600,
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => $this->payload(new SendPush('token-1', 'Hi', 'There')), 'exception' => "RuntimeException: Firebase is down\n#0 trace",
            'failed_at' => '2026-11-14 10:00:00',
        ]);
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/queues/stats')->assertOk()
            ->assertJson(['waiting' => 1, 'running' => 0, 'failed' => 1, 'worker_missing' => true]);
        $this->getJson('/api/admin/queues/jobs')->assertOk()
            ->assertJsonPath('data.0.job', 'Send a message to customers')
            ->assertJsonPath('data.0.about', '"Gas prices drop"')
            ->assertJsonPath('data.0.link', '/dashboard/messages?view='.$message->id)
            ->assertJsonPath('data.0.state', 'waiting');
        $failed = $this->getJson('/api/admin/queues/failed')->assertOk()
            ->assertJsonPath('data.0.job', 'Push message to a phone')
            ->assertJsonPath('data.0.about', '"Hi"')
            ->assertJsonPath('data.0.error', 'RuntimeException: Firebase is down')
            ->assertJsonPath('data.0.failed_at', '2026-11-14T10:00:00.000000Z')
            ->json('data.0.id');
        $this->getJson('/api/admin/queues/failed/'.$failed)->assertOk()->assertJsonPath('data.exception', "RuntimeException: Firebase is down\n#0 trace");
    }

    public function test_a_failed_job_is_retried_or_deleted_and_the_audit_trail_says_so(): void
    {
        $uuid = (string) Str::uuid();
        $id = DB::table('failed_jobs')->insertGetId([
            'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
            'payload' => $this->payload(new SendPush('token-1', 'Hi', 'There')), 'exception' => 'boom', 'failed_at' => now(),
        ]);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/queues/failed/retry', ['ids' => [$id]])->assertOk()->assertJsonPath('message', '1 job put back on the queue.');
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'Queues', 'event' => 'jobs_retried']);

        $job = DB::table('jobs')->value('id');
        $this->postJson('/api/admin/queues/jobs/bulk-delete', ['ids' => [$job]])->assertOk()->assertJsonPath('message', '1 waiting job deleted.');
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_a_queued_email_says_what_it_is(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
            'payload' => $this->payload(new \Illuminate\Mail\SendQueuedMailable(new \App\Mail\ViewMail('forgotPasswordMail', ['data' => []], 'Reset your password'))),
        ]);
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/queues/jobs')->assertJsonPath('data.0.job', 'Email')->assertJsonPath('data.0.about', '"Reset your password"');
    }

    public function test_editors_cannot_open_queues(): void
    {
        Sanctum::actingAs($this->editor());
        $this->getJson('/api/admin/queues/stats')->assertForbidden();
        $this->postJson('/api/admin/queues/failed/retry', ['ids' => [1]])->assertForbidden();
    }

    public function test_pushes_and_emails_go_on_the_queue_instead_of_holding_up_the_request(): void
    {
        Queue::fake();
        $customer = $this->customer(['device_token' => 'phone-1']);
        $order = $this->order($customer);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/orders/{$order->id}/payments", ['amount' => 1000, 'payment_method' => 'cash'])->assertCreated();
        Queue::assertPushed(SendPush::class, fn (SendPush $p) => $p->token === 'phone-1' && $p->title === 'Payment received');
        $this->assertSame([], self::$pushes, 'nothing was sent while the request waited');

        $this->assertFalse(Push::send(null, 'x', 'y'), 'no phone, no job');
    }
}
