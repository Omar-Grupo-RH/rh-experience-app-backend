<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Auth\JwtService;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use PHPUnit\Framework\TestCase;

final class JwtServiceTest extends TestCase
{
    public function testIssueAndDecodeRoundTrip(): void
    {
        $token = JwtService::issueAccessToken(42, ['admin' => 1], 3600);
        $claims = JwtService::decode($token);

        $this->assertSame('42', $claims['sub']);
        $this->assertSame(1, $claims['admin']);
        $this->assertSame($claims['iat'] + 3600, $claims['exp']);
        $this->assertArrayHasKey('jti', $claims);
        $this->assertArrayHasKey('iss', $claims);
        $this->assertArrayHasKey('aud', $claims);
    }

    public function testEachTokenHasUniqueJti(): void
    {
        $a = JwtService::decode(JwtService::issueAccessToken(1));
        $b = JwtService::decode(JwtService::issueAccessToken(1));

        $this->assertNotSame($a['jti'], $b['jti']);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = JwtService::issueAccessToken(42, [], -10);

        $this->expectException(ExpiredException::class);
        JwtService::decode($token);
    }

    public function testTamperedTokenIsRejected(): void
    {
        $token = JwtService::issueAccessToken(42);

        // Alterar el payload invalida la firma
        [$header, $payload, $signature] = explode('.', $token);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $claims['sub'] = '999';
        $tamperedPayload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
        $tampered = "$header.$tamperedPayload.$signature";

        $this->expectException(SignatureInvalidException::class);
        JwtService::decode($tampered);
    }

    public function testGarbageTokenIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        JwtService::decode('no-es-un-jwt');
    }
}
