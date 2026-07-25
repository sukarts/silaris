<?php

declare(strict_types=1);

namespace Silaris\Modules\Notifications\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable générique SILARIS : sujet + titre + lignes + code éventuel + CTA.
 * Base des mails de notification, reset et invitation (testable via Mail::fake).
 */
class GenericMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @param list<string> $lines */
    public function __construct(
        public string $mailSubject,
        public string $title,
        public array $lines,
        public ?string $code = null,
        public ?string $ctaUrl = null,
        public ?string $ctaLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.generic', with: [
            'title' => $this->title,
            'lines' => $this->lines,
            'code' => $this->code,
            'ctaUrl' => $this->ctaUrl,
            'ctaLabel' => $this->ctaLabel,
        ]);
    }
}
