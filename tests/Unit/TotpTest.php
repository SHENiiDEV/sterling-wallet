<?php

namespace Tests\Unit;

use App\Support\Totp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    // RFC 6238 appendix B: the secret "12345678901234567890" in base32.
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_it_matches_the_rfc_6238_test_vectors()
    {
        $this->assertSame('94287082', Totp::code(self::SECRET, 8, 30, 'sha1', 59));
        $this->assertSame('07081804', Totp::code(self::SECRET, 8, 30, 'sha1', 1111111109));
        $this->assertSame('14050471', Totp::code(self::SECRET, 8, 30, 'sha1', 1111111111));
        $this->assertSame('287082', Totp::code(self::SECRET, 6, 30, 'sha1', 59));
    }

    public function test_it_reads_a_secret_as_pasted_or_from_an_otpauth_link()
    {
        $this->assertSame(self::SECRET, Totp::parse('gezd gnbv gy3t qojq gezd gnbv gy3t qojq')['secret']);

        $parsed = Totp::parse('otpauth://totp/Oxen:cosmic?secret=GEZDGNBVGY3TQOJQ&issuer=Oxen&digits=8&period=60&algorithm=SHA256');
        $this->assertSame(['GEZDGNBVGY3TQOJQ', 8, 60, 'sha256', 'Oxen'], [$parsed['secret'], $parsed['digits'], $parsed['period'], $parsed['algorithm'], $parsed['issuer']]);
    }

    public function test_it_rejects_what_is_not_a_secret()
    {
        $this->expectException(InvalidArgumentException::class);

        Totp::parse('this is not a secret!');
    }

    public function test_the_seconds_left_count_down_the_period()
    {
        $this->assertSame(30, Totp::remaining(30, 60));
        $this->assertSame(1, Totp::remaining(30, 89));
    }
}
