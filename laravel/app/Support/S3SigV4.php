<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Dependency-free AWS SigV4 client for S3-compatible object storage (path-style URLs), built on
 * Laravel's HTTP client (Guzzle underneath — fakeable via Http::fake() in tests) only. No
 * aws-sdk-php / league/flysystem-aws-s3-v3: neither is installed and this box has no Composer
 * access, so Storage::disk('s3') is not an option. Endpoint-agnostic: the #230 shipping target is
 * OCI Object Storage (me-riyadh-1), but any S3-compatible path-style endpoint works identically.
 *
 * The signing math (canonical request -> string to sign -> derived signing key -> HMAC-SHA256
 * signature) is isolated in the static `signature()` method, which takes no config/network
 * dependency, so it can be exercised directly against the published AWS SigV4 test vector to
 * prove the crypto with no live endpoint — see tests/Unit/S3SigV4Test.php.
 */
final class S3SigV4
{
    private ?string $endpoint;
    private ?string $bucket;
    private string $region;
    private ?string $accessKey;
    private ?string $secret;

    /**
     * @param array{endpoint?:?string,bucket?:?string,region?:?string,access_key?:?string,secret?:?string}|null $config
     *        Defaults to config('services.audit_archive'); pass explicitly in tests to avoid touching global config.
     */
    public function __construct(?array $config = null)
    {
        $config ??= (array) config('services.audit_archive', []);

        $this->endpoint = $config['endpoint'] ?? null;
        $this->bucket = $config['bucket'] ?? null;
        $this->region = $config['region'] ?? 'me-riyadh-1';
        $this->accessKey = $config['access_key'] ?? null;
        $this->secret = $config['secret'] ?? null;
    }

    /** @return int the HTTP status code of the upload */
    public function putObject(string $key, string $body, string $contentType = 'application/x-ndjson'): int
    {
        $this->assertConfigured();

        $payloadHash = hash('sha256', $body);
        [$url, $headers] = $this->signedRequestHeaders('PUT', $key, $payloadHash);

        $response = Http::withHeaders($headers)
            ->timeout(30)
            ->withBody($body, $contentType)
            ->put($url);

        return $response->status();
    }

    /** @return int the HTTP status code of the HEAD check */
    public function headObject(string $key): int
    {
        $this->assertConfigured();

        $payloadHash = hash('sha256', '');
        [$url, $headers] = $this->signedRequestHeaders('HEAD', $key, $payloadHash);

        $response = Http::withHeaders($headers)->timeout(30)->head($url);

        return $response->status();
    }

    /**
     * DATA-02: read one object (used by backup:verify to fetch the backup heartbeat). Same URL and
     * the same three signed headers as putObject()/headObject(), empty-payload hash.
     *
     * @return string|null the body on 2xx; null ONLY when the object does not exist (404)
     * @throws RuntimeException on any other non-2xx status (403, 5xx, …) — a caller must never be
     *         able to mistake "could not read the bucket" for "the object is not there"
     */
    public function get(string $key): ?string
    {
        $this->assertConfigured();

        $payloadHash = hash('sha256', '');
        [$url, $headers] = $this->signedRequestHeaders('GET', $key, $payloadHash);

        $response = Http::withHeaders($headers)->timeout(30)->get($url);

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new RuntimeException("S3 GET {$key} failed with HTTP {$response->status()}");
        }

        return $response->body();
    }

    /** True when endpoint, bucket and both credentials are present (what assertConfigured() checks). */
    public function isConfigured(): bool
    {
        return (bool) ($this->endpoint && $this->bucket && $this->accessKey && $this->secret);
    }

    /**
     * Builds the request URL (path-style: {endpoint}/{bucket}/{key}) and the SigV4-signed
     * request headers (x-amz-content-sha256, x-amz-date, Authorization) for one call.
     *
     * @return array{0:string,1:array<string,string>}
     */
    private function signedRequestHeaders(string $method, string $key, string $payloadHash): array
    {
        $host = parse_url($this->endpoint, PHP_URL_HOST) ?: $this->endpoint;
        $canonicalUri = '/' . rawurlencode($this->bucket) . '/'
            . implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
        $url = rtrim($this->endpoint, '/') . $canonicalUri;

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = substr($amzDate, 0, 8);

        // These three are the ONLY headers our requests send that need signing — no query string,
        // no Range (we never do partial GETs).
        $signedHeaders = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];

        $signature = self::signature(
            $method,
            $canonicalUri,
            '',
            $signedHeaders,
            $this->secret,
            $this->region,
            's3',
            $dateStamp,
            $amzDate,
            $payloadHash
        );

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s/%s/s3/aws4_request, SignedHeaders=%s, Signature=%s',
            $this->accessKey,
            $dateStamp,
            $this->region,
            implode(';', array_keys($signedHeaders)),
            $signature
        );

        return [$url, [
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
            'Authorization' => $authorization,
        ]];
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('audit archive not configured');
        }
    }

    /**
     * Pure SigV4 signature computation: canonical request -> string to sign -> derived signing key
     * -> HMAC-SHA256 signature (lowercase hex). No I/O, no config — every input is a parameter, so
     * this can be (and is, in tests/Unit/S3SigV4Test.php) exercised directly against the published
     * AWS SigV4 GET-object test vector with no live endpoint involved.
     *
     * @param array<string,string> $headers lowercase header name => raw value, containing EVERY
     *        header that must be signed (host, x-amz-date, x-amz-content-sha256, and — for the
     *        test vector only — range). Canonical headers and the signed-headers list are both
     *        derived from this array, sorted by header name, exactly as SigV4 requires.
     */
    public static function signature(
        string $method,
        string $canonicalUri,
        string $canonicalQueryString,
        array $headers,
        string $secretKey,
        string $region,
        string $service,
        string $dateStamp,
        string $amzDate,
        ?string $payloadHash = null,
    ): string {
        ksort($headers);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= strtolower($name) . ':' . trim((string) $value) . "\n";
        }
        $signedHeaderNames = implode(';', array_map('strtolower', array_keys($headers)));

        $payloadHash ??= $headers['x-amz-content-sha256'] ?? hash('sha256', '');

        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            $canonicalQueryString,
            $canonicalHeaders,
            $signedHeaderNames,
            $payloadHash,
        ]);

        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        return hash_hmac('sha256', $stringToSign, $kSigning);
    }
}
