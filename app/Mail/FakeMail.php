<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FakeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipient_email, // 宛先メールアドレス
        public readonly int $mail_log_id,        // メール件名の番号表示に使用
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "テストメール #{$this->mail_log_id}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.fake-mail', // resources/views/emails/fake-mail.blade.php
        );
    }

    public function attachments(): array
    {
        return []; // 添付ファイルなし
    }
}
