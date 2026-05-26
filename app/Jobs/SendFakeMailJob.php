<?php

namespace App\Jobs;

use App\Mail\FakeMail;
use App\Models\MailLog;
use Illuminate\Contracts\Queue\ShouldQueue; // このインターフェースを実装するだけでキュー経由になる
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendFakeMailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3; // 失敗しても最大3回まで試行する（初回 + リトライ2回）

    public function __construct(
        public int $mail_log_id // Redisに保存されるため、モデルではなくIDだけを渡す
    ) {}

    // Horizonの Failed Jobs・Completed Jobs 一覧に "Tags: mail_log:10" のように表示される
    // どのレコードのジョブかを一覧画面で即座に特定できる
    public function tags(): array
    {
        return ['mail_log:' . $this->mail_log_id];
    }

    public function handle(): void
    {
        // pending のレコードだけを sending に変更する
        // 複数ワーカーが同時に同じジョブを処理しようとしても、
        // DBのUPDATE文は先着1件だけが updated=1 を受け取る（重複防止の核心）
        $updated = MailLog::where('id', $this->mail_log_id)
            ->where('status', 'pending')
            ->update(['status' => 'sending']);

        if ($updated === 0) {
            return; // 別のワーカーがすでに処理中 or 送信済み → スキップ
        }

        $mail_log = MailLog::find($this->mail_log_id);

        if (! $mail_log) {
            return; // レコードが削除済みの場合のガード（通常は発生しない）
        }

        sleep(1); // 実際のメール送信の遅さをシミュレート（Horizonで処理の流れを観察しやすくする）

        if ($this->shouldFail($mail_log->id)) {
            $mail_log->update(['status' => 'pending']); // リトライ時に冒頭のUPDATE条件を通過できるよう pending に戻す
            throw new \Exception("Mail #{$mail_log->id}: simulated failure");
        }

        // Mailpit（http://localhost:8025）にメールを送信
        Mail::to($mail_log->email)->send(new FakeMail(
            recipient_email: $mail_log->email,
            mail_log_id: $mail_log->id,
        ));

        // 送信完了後すぐに sent に更新する
        // クラッシュ後に再キューされたとき「送信済み」と判別するために必要
        $mail_log->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    // id=10, 20, 30... を意図的に失敗させる（Horizonのリトライ動作を観察するため）
    protected function shouldFail(int $id): bool
    {
        return $id % 10 === 0;
    }

    // $tries 回すべて失敗したときに Horizon が自動で呼び出す
    public function failed(\Throwable $e): void
    {
        MailLog::where('id', $this->mail_log_id)
            ->update(['status' => 'failed']);
    }
}
