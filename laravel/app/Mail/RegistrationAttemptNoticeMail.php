<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * S2 (role walkthrough 2026-09-25): sent instead of a real verification code whenever the public
 * registration form's first step is blocked, i.e. for BOTH blocked reasons — an ALREADY-REGISTERED
 * staff email, or a collision with someone else's in-flight `pending_registrations` row — so the
 * HTTP response to whoever submitted the form stays byte-identical (and same-latency, since mail
 * sends synchronously under QUEUE_CONNECTION=sync) to a normal "code sent" reply
 * (RegisterController::sendEmailCode). $hasAccount only changes the WORDING sent to the real
 * inbox — never invent "already has an account" for a collision-only address that doesn't have
 * one yet. No PHI — just a notice and, when there is an account, pointers to the two
 * account-recovery flows. Mirrors RegistrationCodeMail / UsernameReminderMail's shape.
 */
class RegistrationAttemptNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly bool $hasAccount = true) {}

    public function build(): self
    {
        return $this->subject($this->hasAccount
                ? 'Someone tried to register with your DMC email'
                : 'Someone tried to register with your email')
            ->view('mail.registration-attempt-notice', ['hasAccount' => $this->hasAccount]);
    }
}
