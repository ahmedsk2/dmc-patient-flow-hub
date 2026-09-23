<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Owner decision 2026-09-23 (UAT AUTH-07): the session cookie is a browser-session cookie, so closing
 * the browser on a shared ward computer signs the user out. It used to carry Max-Age=7200 and survive a
 * browser restart until the idle timeout.
 */
class SessionCookieExpiresOnCloseTest extends TestCase
{
    public function test_the_session_cookie_ends_when_the_browser_closes(): void
    {
        $this->assertTrue(config('session.expire_on_close'));

        $cookie = collect($this->get('/login')->assertOk()->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));

        $this->assertNotNull($cookie, 'the login page sets the session cookie');
        $this->assertSame(0, $cookie->getExpiresTime(), 'no Expires / Max-Age: the browser drops it on close');
    }
}
