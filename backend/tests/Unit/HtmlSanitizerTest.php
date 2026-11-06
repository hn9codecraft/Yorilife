<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\HtmlSanitizer;
use App\Services\UtcTime;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    public function testKeepsFormattingAndRemovesUnsafeCode(): void
    {
        $html = '<p onclick="x()">Hello <b>team</b> <i>now</i></p><script>alert(1)</script><ul><li>One</li></ul>'
            . '<a href="javascript:alert(1)">bad</a> <a href="https://example.com" style="color:red">good</a><img src=x onerror=alert(1)><style>p{}</style>';
        $clean = HtmlSanitizer::clean($html);

        self::assertSame('<p>Hello <strong>team</strong> <em>now</em></p><ul><li>One</li></ul>bad <a href="https://example.com" target="_blank" rel="noopener noreferrer">good</a>', $clean);
        self::assertStringNotContainsString('script', $clean);
        self::assertStringNotContainsString('onclick', $clean);
        self::assertStringNotContainsString('javascript', $clean);
        self::assertStringNotContainsString('<img', $clean);
    }

    public function testTextVersionAndEscaping(): void
    {
        $clean = HtmlSanitizer::clean('<p>A &amp; B &lt;x&gt;</p><p>Line 2</p>');
        self::assertSame('<p>A &amp; B &lt;x&gt;</p><p>Line 2</p>', $clean);
        self::assertSame("A & B <x>\nLine 2", HtmlSanitizer::text($clean));
        self::assertSame('', HtmlSanitizer::clean('   '));
        self::assertSame('', HtmlSanitizer::text(HtmlSanitizer::clean('<p><br></p>')));
    }

    public function testIndiaTimeInputIsStoredAsUtc(): void
    {
        self::assertSame('2026-11-05 04:30:00', UtcTime::parse('2026-11-05T10:00'));
        self::assertSame('2026-11-05 10:00:00', UtcTime::parse('2026-11-05T10:00:00Z'));
        self::assertNull(UtcTime::parse('5 Nov'));
        self::assertSame('2026-11-05T04:30:00Z', UtcTime::iso('2026-11-05 04:30:00'));
        self::assertSame('2027', UtcTime::indiaYear('2026-12-31 20:00:00'), 'reference year follows India time');
    }
}