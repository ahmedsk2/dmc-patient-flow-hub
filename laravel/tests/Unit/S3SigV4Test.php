<?php

namespace Tests\Unit;

use App\Support\S3SigV4;
use PHPUnit\Framework\TestCase;

/**
 * Task #230 — proves the hand-rolled SigV4 math against the published AWS "GET Object" canonical
 * signing example (AWS docs: "Examples of the Complete Version 4 Signing Process", access key
 * AKIAIOSFODNN7EXAMPLE / secret wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY, region us-east-1, date
 * 20130524T000000Z). This is a pure function with no config/network involved, so it proves the
 * crypto is correct without a live endpoint.
 */
class S3SigV4Test extends TestCase
{
    public function test_signature_matches_the_aws_documented_sigv4_get_object_vector(): void
    {
        $signature = S3SigV4::signature(
            method: 'GET',
            canonicalUri: '/test.txt',
            canonicalQueryString: '',
            headers: [
                'host' => 'examplebucket.s3.amazonaws.com',
                'range' => 'bytes=0-9',
                'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                'x-amz-date' => '20130524T000000Z',
            ],
            secretKey: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            region: 'us-east-1',
            service: 's3',
            dateStamp: '20130524',
            amzDate: '20130524T000000Z',
        );

        $this->assertSame(
            'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $signature
        );
    }

    public function test_header_order_in_the_input_array_does_not_affect_the_signature(): void
    {
        // SigV4 requires headers sorted by name in the canonical request — feeding them in a
        // different order must not change the result.
        $signature = S3SigV4::signature(
            method: 'GET',
            canonicalUri: '/test.txt',
            canonicalQueryString: '',
            headers: [
                'x-amz-date' => '20130524T000000Z',
                'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                'host' => 'examplebucket.s3.amazonaws.com',
                'range' => 'bytes=0-9',
            ],
            secretKey: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            region: 'us-east-1',
            service: 's3',
            dateStamp: '20130524',
            amzDate: '20130524T000000Z',
        );

        $this->assertSame(
            'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $signature
        );
    }

    /**
     * DATA-02: `S3SigV4::get()` (used by backup:verify to read the backup heartbeat) signs exactly
     * three headers — host, x-amz-content-sha256, x-amz-date — with no Range. That is the shape of
     * the AWS-documented "GET Bucket Lifecycle" example (same key/date/region as the GET Object
     * vector above; empty-payload hash; query string `lifecycle=`), whose published signature is
     * fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543. Proves the no-Range,
     * three-header GET path — the one `get()` actually sends — against a published vector.
     */
    public function test_signature_matches_the_aws_documented_three_header_get_vector_without_range(): void
    {
        $signature = S3SigV4::signature(
            method: 'GET',
            canonicalUri: '/',
            canonicalQueryString: 'lifecycle=',
            headers: [
                'host' => 'examplebucket.s3.amazonaws.com',
                'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                'x-amz-date' => '20130524T000000Z',
            ],
            secretKey: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            region: 'us-east-1',
            service: 's3',
            dateStamp: '20130524',
            amzDate: '20130524T000000Z',
        );

        $this->assertSame(
            'fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543',
            $signature
        );
    }

    public function test_a_different_secret_produces_a_different_signature(): void
    {
        $signature = S3SigV4::signature(
            method: 'GET',
            canonicalUri: '/test.txt',
            canonicalQueryString: '',
            headers: [
                'host' => 'examplebucket.s3.amazonaws.com',
                'range' => 'bytes=0-9',
                'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                'x-amz-date' => '20130524T000000Z',
            ],
            secretKey: 'a-completely-different-secret',
            region: 'us-east-1',
            service: 's3',
            dateStamp: '20130524',
            amzDate: '20130524T000000Z',
        );

        $this->assertNotSame(
            'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $signature
        );
        $this->assertSame(64, strlen($signature));
    }
}
