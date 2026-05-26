<x-mail::message>
# テストメール

**宛先:** {{ $recipient_email }}
**Mail Log ID:** {{ $mail_log_id }}

Redisキューから非同期で送信されたテストメールです。

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
