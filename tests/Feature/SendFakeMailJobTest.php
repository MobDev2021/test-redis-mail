<?php

namespace Tests\Feature;

use App\Jobs\SendFakeMailJob;
use App\Mail\FakeMail;
use App\Models\MailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class SendFakeMailJobTest extends TestCase
{
    use RefreshDatabase;

    // ── ディスパッチテスト ──────────────────────────────────────

    #[TestDox('ジョブが emails キューに投入される')]
    public function test_job_is_dispatched_to_emails_queue(): void
    {
        Queue::fake();

        $mail_log = MailLog::create(['email' => 'test@example.com']);

        SendFakeMailJob::dispatch($mail_log->id)->onQueue('emails');

        Queue::assertPushedOn('emails', SendFakeMailJob::class);
    }

    #[TestDox('mail:send-test コマンドで 100 件のジョブが投入される')]
    public function test_command_queues_100_jobs(): void
    {
        Queue::fake();

        $this->artisan('mail:send-test')->assertSuccessful();

        Queue::assertPushed(SendFakeMailJob::class, 100);
        $this->assertDatabaseCount('mail_logs', 100);
    }

    // ── アトミックUPDATE（重複防止）テスト ───────────────────────

    #[TestDox('アトミック UPDATE が pending → sending に遷移させる')]
    public function test_atomic_update_transitions_pending_to_sending(): void
    {
        $mail_log = MailLog::create(['email' => 'atomic@example.com']);

        $updated = MailLog::where('id', $mail_log->id)
            ->where('status', 'pending')
            ->update(['status' => 'sending']);

        $this->assertEquals(1, $updated);
        $this->assertEquals('sending', $mail_log->fresh()->status);
    }

    #[TestDox('すでに sending の場合、アトミック UPDATE は 0 行を返す（重複防止）')]
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

    #[TestDox('別ワーカーがすでに取得済みの場合、ジョブをスキップしメールを送信しない')]
    public function test_second_worker_skips_when_already_sending(): void
    {
        Mail::fake();

        $mail_log = MailLog::create([
            'email' => 'skip@example.com',
            'status' => 'sending',
        ]);

        (new SendFakeMailJob($mail_log->id))->handle();

        $this->assertEquals('sending', $mail_log->fresh()->status);
        Mail::assertNothingSent();
    }

    #[TestDox('すでに送信済みのレコードは再処理されない')]
    public function test_job_skips_when_already_sent(): void
    {
        Mail::fake();

        $mail_log = MailLog::create([
            'email' => 'done@example.com',
            'status' => 'sent',
        ]);

        (new SendFakeMailJob($mail_log->id))->handle();

        $this->assertEquals('sent', $mail_log->fresh()->status);
        Mail::assertNothingSent();
    }

    // ── 正常送信テスト ────────────────────────────────────────

    #[TestDox('正常送信時にメールが送られ status=sent・sent_at が設定される')]
    public function test_job_sends_mail_and_marks_sent(): void
    {
        Mail::fake();

        $mail_log = MailLog::create(['email' => 'success@example.com']);

        // shouldFail を常に false に上書き → 必ず正常送信されるジョブで実行
        $job = new class($mail_log->id) extends SendFakeMailJob
        {
            protected function shouldFail(int $id): bool
            {
                return false;
            }
        };
        $job->handle();

        $this->assertEquals('sent', $mail_log->fresh()->status);
        $this->assertNotNull($mail_log->fresh()->sent_at);
        Mail::assertSent(FakeMail::class, function (FakeMail $mail) use ($mail_log) {
            return $mail->recipient_email === $mail_log->email
                && $mail->mail_log_id === $mail_log->id;
        });
    }

    // ── 失敗・リセットテスト ──────────────────────────────────

    #[TestDox('送信失敗時に status が pending にリセットされ例外がスローされる')]
    public function test_job_resets_to_pending_on_simulated_failure(): void
    {
        Mail::fake();

        $mail_log = MailLog::create(['email' => 'fail@example.com']);

        // shouldFail を常に true に上書き → 必ず失敗するジョブで実行
        $job = new class($mail_log->id) extends SendFakeMailJob
        {
            protected function shouldFail(int $id): bool
            {
                return true;
            }
        };

        try {
            $job->handle();
            $this->fail('例外が発生するはずでした');
        } catch (\Exception $e) {
            $this->assertStringContainsString('simulated failure', $e->getMessage());
            $this->assertEquals('pending', $mail_log->fresh()->status);
            Mail::assertNothingSent();
        }
    }

    // ── failed() テスト ───────────────────────────────────────

    #[TestDox('リトライ上限到達後に failed() が status=failed に更新する')]
    public function test_failed_method_marks_status_as_failed(): void
    {
        $mail_log = MailLog::create([
            'email' => 'fail@example.com',
            'status' => 'pending',
        ]);

        (new SendFakeMailJob($mail_log->id))->failed(new \Exception('exhausted'));

        $this->assertEquals('failed', $mail_log->fresh()->status);
    }
}
