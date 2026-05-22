<?php

namespace App\Jobs;

use App\Models\MailLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendFakeMailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $mailLogId
    ) {}

    public function handle(): void
    {
        // Duplicate prevention: atomic pending → sending update
        // If 0 rows updated, another worker already claimed this job — skip safely.
        // This prevents duplicate sends when multiple workers pick up the same job
        // (e.g. after a crash retry or concurrent processing).
        $updated = MailLog::where('id', $this->mailLogId)
            ->where('status', 'pending')
            ->update(['status' => 'sending']);

        if ($updated === 0) {
            return;
        }

        $mailLog = MailLog::find($this->mailLogId);

        if (! $mailLog) {
            return;
        }

        // Simulate slow email sending
        sleep(2);

        // Random failure simulation
        if (random_int(1, 10) === 1) {
            // Reset to pending so the next Horizon retry attempt passes the atomic check
            $mailLog->update(['status' => 'pending']);
            throw new \Exception('Random send failure');
        }

        Log::info("Mail sent to {$mailLog->email}");

        $mailLog->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    public function failed(): void
    {
        MailLog::where('id', $this->mailLogId)
            ->update(['status' => 'failed']);
    }
}
