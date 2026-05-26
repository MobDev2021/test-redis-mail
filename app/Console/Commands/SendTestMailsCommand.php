<?php

namespace App\Console\Commands;

use App\Jobs\SendFakeMailJob;
use App\Models\MailLog;
use Illuminate\Console\Command;

class SendTestMailsCommand extends Command
{
    protected $signature = 'mail:send-test {--count=100 : キューに投入するメール件数}';

    protected $description = 'テストメールをキューに投入する — Horizon と Mailpit で処理を観察できる';

    public function handle(): void
    {
        $count = max(0, (int) $this->option('count'));

        $this->newLine();
        $this->line("  <info>{$count}</info> 件のテストメールをキューに投入中...");
        $this->newLine();

        // dispatch() はジョブをRedisに積むだけ — この時点でメールはまだ送られない
        $this->withProgressBar($count > 0 ? range(1, $count) : [], function ($i) {
            $mail_log = MailLog::create(['email' => "user{$i}@example.com"]);

            SendFakeMailJob::dispatch($mail_log->id)
                ->onQueue('emails'); // config/horizon.php の監視対象キューに合わせる
        });

        $this->newLine(2);
        $this->info("  {$count} 件のキューへの投入が完了しました。");
        $this->newLine();
        $this->line('  <comment>Horizon ダッシュボード:</comment>  http://localhost:8000/horizon');
        $this->line('  <comment>Mailpit 受信ボックス:</comment>    http://localhost:8025');
        $this->newLine();
    }
}
