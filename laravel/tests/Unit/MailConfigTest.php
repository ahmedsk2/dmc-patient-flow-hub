<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * RES-01: QUEUE_CONNECTION=sync makes every OTP / reset / report send a synchronous SMTP call from
 * inside the web request. Laravel's default `timeout => null` is unbounded, so a relay brownout
 * would pin PHP-FPM workers indefinitely. The smtp mailer must carry a small, positive timeout.
 */
class MailConfigTest extends TestCase
{
    public function test_smtp_transport_has_a_bounded_positive_timeout(): void
    {
        $timeout = config('mail.mailers.smtp.timeout');

        $this->assertIsInt($timeout, 'smtp timeout must be an integer number of seconds, not null');
        $this->assertGreaterThan(0, $timeout);
        $this->assertLessThanOrEqual(30, $timeout, 'a request-blocking mail send must fail fast');
    }
}
