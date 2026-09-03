<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The full suite renders many dompdf booklets + openspout XLSX exports (Phase 3) in one
        // long-lived sequential process; PHP does not fully reclaim that memory between tests, so the
        // peak accumulates across the whole run and crashes it ("Premature end of PHP process") well
        // before any single render is large. Give the test process generous headroom — 512M was
        // borderline at this suite size and tipped over intermittently. Test-process only; production
        // never runs hundreds of renders in one process.
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit !== '-1' && (int) $limit < 1024 && stripos($limit, 'G') === false) {
            @ini_set('memory_limit', '1024M');
        }
        // Align the MySQL session timezone with PHP's so CURDATE()/NOW() (used in DATEDIFF-based
        // LOS / delay / boarding / readmission math) match Carbon::today()/now(). Without this, a
        // DB server tz trailing the app tz makes date-boundary tests flake just after midnight
        // (e.g. a 7-day delay reads as 6). Test-process only — never touches production.
        DB::statement("SET time_zone = '".now()->format('P')."'");
        // Tests assert backend behaviour; don't require a built Vite manifest to render pages.
        $this->withoutVite();
    }
}
