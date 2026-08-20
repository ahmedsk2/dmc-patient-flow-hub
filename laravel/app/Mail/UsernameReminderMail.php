<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The "forgot username" reminder — mirrors RegistrationCodeMail's shape. No PHI, just the
 * account's username and a link back to sign in.
 */
class UsernameReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $username) {}

    public function build(): self
    {
        return $this->subject('Your DMC username')
            ->view('mail.username-reminder')
            ->with(['username' => $this->username]);
    }
}
