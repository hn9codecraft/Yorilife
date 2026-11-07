<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AuditChain;
use App\Services\PiiMask;
use PHPUnit\Framework\TestCase;

final class PiiMaskTest extends TestCase
{
    public function testEmailAndPhoneMasks(): void
    {
        self::assertSame('n***@example.com', PiiMask::email('nirav@example.com'));
        self::assertSame('******3210', PiiMask::phone('9876543210'));
        self::assertSame('+** ***** *3210', PiiMask::phone('+91 98765 43210'));
        self::assertSame('****', PiiMask::phone('1234'));
        self::assertSame('n***@example.com', PiiMask::contact('nirav@example.com'));
        self::assertSame('******3210', PiiMask::contact('9876543210'));
        self::assertSame('Call n***@example.com or ******3210', PiiMask::text('Call nirav@example.com or 9876543210'));
        self::assertSame('380001', PiiMask::text('380001'));
        self::assertSame('n***@example.com', PiiMask::field('Email', 'nirav@example.com'));
        self::assertSame('Ring road', PiiMask::field('Address', 'Ring road'));
    }

    public function testHashIsStableAndDependsOnThePreviousHash(): void
    {
        $row = [
            'id' => 4,
            'occurred_at' => '2026-11-07 03:00:00',
            'actor_id' => 2,
            'actor_label' => 'Admin',
            'module' => 'auth',
            'action' => 'login',
            'result' => 'ok',
            'target_type' => 'user',
            'target_id' => 2,
            'target_label' => 'Admin',
            'ip_address' => '127.0.0.1',
            'user_agent' => null,
            'request_id' => '11111111-1111-4111-8111-111111111111',
            'details' => null,
        ];
        $first = AuditChain::hash($row, AuditChain::GENESIS);
        self::assertSame(64, strlen($first));
        self::assertSame($first, AuditChain::hash($row, AuditChain::GENESIS));
        self::assertNotSame($first, AuditChain::hash($row, str_repeat('ab', 32)));
        $changed = $row;
        $changed['action'] = 'tampered';
        self::assertNotSame($first, AuditChain::hash($changed, AuditChain::GENESIS));
    }
}
