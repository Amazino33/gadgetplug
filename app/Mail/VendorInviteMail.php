<?php

namespace App\Mail;

use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VendorInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Vendor $vendor,
        public string $token,
        public string $email,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been invited to join {$this->vendor->name} on GadgetPlug",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.vendor-invite',
            with: [
                'vendorName' => $this->vendor->name,
                'inviteUrl'  => route('vendor.invite.accept', ['token' => $this->token]),
            ],
        );
    }
}