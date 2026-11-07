<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Every free-shop email — to the admin about an application, and to the shop
 * about its review. They are all a heading, a few lines and one button, so
 * one mailable carries them; FreeShopApplications writes the words.
 *
 * Queued: the app already runs the database queue every minute, and a slow
 * mail server must never hold up someone's signup on a phone.
 */
class FreeShopMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $subjectLine,
        public string $heading,
        public array $lines,
        public ?string $actionText = null,
        public ?string $actionUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.free-shop');
    }
}
