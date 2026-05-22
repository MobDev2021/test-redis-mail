<?php

namespace Tests\Feature;

use App\Jobs\SendFakeMailJob;
use App\Models\MailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendFakeMailJobTest extends TestCase
{
    use RefreshDatabase;

    // ── ディスパッチテスト ──────────────────────────────────────

    public function test_job_is_dispatched_to_emails_queue(): void
    {
        Queue::fake();

        $mail_log = MailLog::create(['email' => 'test@example.com']);

        SendFakeMailJob::dispatch($mail_log->id)->onQueue('emails');

        Queue::assertPushedOn('emails', SendFakeMailJob::class);
    }

    public function test_dispatch_route_queues_100_jobs(): void
    {
        Queue::fake();

        $response = $this->get('/send-test-mails');

        $response->assertSee('100 mails queued');
        Queue::assertPushed(SendFakeMailJob::class, 100);
        $this->assertDatabaseCount('mail_logs', 100);
    }

    // ── アトミックUPDATE（重複防止）テスト ───────────────────────

    public function test_atomic_update_transitions_pending_to_sending(): void
    {
        $mail_log = MailLog::create(['email' => 'atomic@example.com']);

        $updated = MailLog::where('id', $mail_log->id)
            ->where('status', 'pending')
            ->update(['status' => 'sending']);

        $this->assertEquals(1, $updated);
        $this->assertEquals('sending', $mail_log->fresh()->status);
    }

    public function test_atomic_update_returns_zero_when_already_sending(): void
    {
        $mail_log = MailLog::create([
            'email' => 'skip@example.com',
            'status' => 'sending',
        ]);

        $updated = MailLog::where('id', $mail_log->id)
            ->where('status', 'pending')
            ->update(['status' => 'sending']);

        $this->assertEquals(0, $updated);
        $this->assertEquals('sending', $mail_log->fresh()->status);
    }

    public function test_second_worker_skips_when_already_sending(): void
    {
        $mail_log = MailLog::create([
            'email' => 'skip@example.com',
            'status' => 'sending',
        ]);

        $job = new SendFakeMailJob($mail_log->id);
        $job->handle();

        $this->assertEquals('sending', $mail_log->fresh()->status);
    }

    public function test_job_skips_when_already_sent(): void
    {
        $mail_log = MailLog::create([
            'email' => 'done@example.com',
            'status' => 'sent',
        ]);

        $job = new SendFakeMailJob($mail_log->id);
        $job->handle();

        $this->assertEquals('sent', $mail_log->fresh()->status);
    }

    // ── failed() テスト ───────────────────────────────────────

    public function test_failed_method_marks_status_as_failed(): void
    {
        $mail_log = MailLog::create([
            'email' => 'fail@example.com',
            'status' => 'pending',
        ]);

        $job = new SendFakeMailJob($mail_log->id);
        $job->failed(new \Exception('exhausted'));

        $this->assertEquals('failed', $mail_log->fresh()->status);
    }
}
