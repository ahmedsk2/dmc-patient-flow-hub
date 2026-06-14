<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The full suite renders several dompdf booklets + openspout XLSX exports (Phase 3) in one
        // long-lived process; their peak allocations cumulatively exceed PHP's 128M CLI default and
        // crash the run ("Premature end of PHP process"). Raise the cap for the test process only —
        // each individual render is well within this; this just stops the whole-run accumulation OOM.
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit !== '-1' && (int) $limit < 512 && stripos($limit, 'G') === false) {
            @ini_set('memory_limit', '512M');
        }
        // Tests assert backend behaviour; don't require a built Vite manifest to render pages.
        $this->withoutVite();
    }
}
