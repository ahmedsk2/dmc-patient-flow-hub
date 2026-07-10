<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The 6-digit verification code shared by both email-verify flows: the mid-registration email
 * check (RegisterController) and the existing-user on-file-email gate (EmailVerificationController).
 * No PHI — just the code and a 10-minute expiry line.
 */
class RegistrationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $code) {}

    public function build(): self
    {
        return $this->subject('Your DMC verification code')
            ->view('mail.registration-code')
            ->with(['code' => $this->code]);
    }
}
