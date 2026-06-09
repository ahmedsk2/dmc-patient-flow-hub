<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Tests assert backend behaviour; don't require a built Vite manifest to render pages.
        $this->withoutVite();
    }
}
