<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** One club message, in the club's own colours. */
class ClubMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        // Mailable already owns $subject, so the heading lives under its own
        // name and is handed to the envelope below.
        public readonly string $heading,
        public readonly string $body,
        public readonly ?string $clubName = null,
        public readonly ?string $logoUrl = null,
        public readonly string $brandColor = '#5EF38C',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.club-message');
    }
}
