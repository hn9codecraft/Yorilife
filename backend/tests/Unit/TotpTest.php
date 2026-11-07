<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RecoveryCodes;
use App\Services\Totp;
use PHPUnit\Framework\TestCase;

/** RFC 6238 SHA-1 vectors. The secret is the Base32 form of "12345678901234567890". */
final class TotpTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testRfc6238Vectors(): void
    {
        $cases = [
            59 => '287082',
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
            20000000000 => '353130',
        ];
        foreach ($cases as $timestamp => $code) {
            self::assertSame($code, Totp::code(self::SECRET, $timestamp), "time {$timestamp}");
            self::assertSame(intdiv($timestamp, 30), Totp::matchStep(self::SECRET, $code, $timestamp, 0));
        }
    }

    public function testWindowRejectsADistantCode(): void
    {
        $code = Totp::code(self::SECRET, 59);
        self::assertNull(Totp::matchStep(self::SECRET, $code, 59 + 120, 1));
    }

    public function testRecoveryCodeHashIgnoresCaseAndHyphen(): void
    {
        self::assertSame(RecoveryCodes::hash('abcde-fghij'), RecoveryCodes::hash('ABCDE FGHIJ'));
        self::assertTrue(RecoveryCodes::looksLike('abcde-fghij'));
        self::assertFalse(RecoveryCodes::looksLike('123456'));
    }
}
