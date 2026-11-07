<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** RFC 6238 SHA-1 secret "12345678901234567890", base32-encoded. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testRfc6238Vectors(): void
    {
        self::assertSame('287082', Totp::code(self::RFC_SECRET, 59));
        self::assertSame('081804', Totp::code(self::RFC_SECRET, 1111111109));
        self::assertSame('050471', Totp::code(self::RFC_SECRET, 1111111111));
        self::assertSame('005924', Totp::code(self::RFC_SECRET, 1234567890));
        self::assertTrue(Totp::verify(self::RFC_SECRET, '287082', 59));
        self::assertFalse(Totp::verify(self::RFC_SECRET, '287082', 59 + 90));
    }

    public function testGeneratedSecretVerifiesAndRecoveryCodesAreHashed(): void
    {
        $secret = Totp::generateSecret();
        $code = Totp::code($secret);
        self::assertTrue(Totp::verify($secret, $code));
        self::assertFalse(Totp::verify($secret, '000000') && $code !== '000000');
        self::assertStringContainsString('otpauth://totp/', Totp::uri($secret, 'person@example.test'));

        $recovery = Totp::recoveryCode();
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $recovery);
        self::assertSame(Totp::hashRecovery($recovery), Totp::hashRecovery(strtolower(str_replace('-', '', $recovery))));
    }
}
