<?php

namespace Tests\Feature;

use App\Support\S3SigV4;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * DATA-02: `S3SigV4::get()` — the read half needed by `backup:verify` to fetch the backup
 * heartbeat (LATEST.json). Mirrors putObject()/headObject(): same path-style URL, same three
 * signed headers, same Authorization shape. Exercised against Http::fake() so the real signing
 * code runs and only the network hop is intercepted (same approach as AuditShipTest).
 *
 * Contract: body on 2xx, null on 404 ("does not exist"), RuntimeException on anything else —
 * a caller must never be able to mistake "could not read" for "is not there".
 */
class S3SigV4GetTest extends TestCase
{
    private function client(): S3SigV4
    {
        return new S3SigV4([
            'endpoint' => 'https://fake-s3.example.test',
            'bucket' => 'dmc-db-backups',
            'region' => 'me-riyadh-1',
            'access_key' => 'AKIATESTKEY',
            'secret' => 'test-secret',
        ]);
    }

    public function test_get_sends_a_sigv4_signed_path_style_get_and_returns_the_body(): void
    {
        Http::fake(['fake-s3.example.test/*' => Http::response('{"object":"x"}', 200)]);

        $body = $this->client()->get('db-backups/dmc_demo/LATEST.json');

        $this->assertSame('{"object":"x"}', $body);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://fake-s3.example.test/dmc-db-backups/db-backups/dmc_demo/LATEST.json'
                && $request->hasHeader('x-amz-date')
                && $request->header('x-amz-content-sha256')[0] === hash('sha256', '')
                && str_starts_with($request->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=AKIATESTKEY/')
                && str_contains($request->header('Authorization')[0], '/me-riyadh-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=');
        });
    }

    public function test_get_returns_null_when_the_object_does_not_exist(): void
    {
        Http::fake(['fake-s3.example.test/*' => Http::response('<Error>NoSuchKey</Error>', 404)]);

        $this->assertNull($this->client()->get('db-backups/dmc_demo/LATEST.json'));
    }

    public function test_get_throws_on_a_non_404_error_so_a_read_failure_is_never_mistaken_for_absence(): void
    {
        Http::fake(['fake-s3.example.test/*' => Http::response('<Error>AccessDenied</Error>', 403)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('403');

        $this->client()->get('db-backups/dmc_demo/LATEST.json');
    }

    public function test_get_refuses_to_run_unconfigured(): void
    {
        Http::fake();
        $client = new S3SigV4(['endpoint' => null, 'bucket' => null, 'access_key' => null, 'secret' => null]);

        $this->assertFalse($client->isConfigured());
        $this->expectException(RuntimeException::class);

        $client->get('anything');
    }

    public function test_is_configured_reports_a_complete_config(): void
    {
        $this->assertTrue($this->client()->isConfigured());
    }
}
