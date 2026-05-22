<?php

use App\Jobs\SendFakeMailJob;
use App\Models\MailLog;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/send-test-mails', function () {
    for ($i = 1; $i <= 100; $i++) {
        $mail_log = MailLog::create([
            'email' => "user{$i}@example.com",
        ]);

        SendFakeMailJob::dispatch($mail_log->id)
            ->onQueue('emails');
    }

    return '100 mails queued';
});
