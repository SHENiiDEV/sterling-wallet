<?php

namespace Tests\Unit\Reports;

use App\Reports\Parsers\Values;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValuesTest extends TestCase
{
    /**
     * @return array<string, array{mixed, string|null}>
     */
    public static function decimals(): array
    {
        return [
            'plain' => ['100.5', '100.5'],
            'comma decimal' => ['100,50', '100.50'],
            'thousands comma' => ['1,234.56', '1234.56'],
            'thousands dot' => ['1.234,56', '1234.56'],
            'thousands space' => ["1\u{00A0}234,56", '1234.56'],
            'ambiguous thousands' => ['1,234', '1234'],
            'negative' => ['-40.00', '-40.00'],
            'parentheses' => ['(12.00)', '-12.00'],
            'currency sign' => ['€ 9.99', '9.99'],
            'empty' => ['', null],
            'text' => ['Total', null],
        ];
    }

    #[DataProvider('decimals')]
    public function test_it_parses_amounts(mixed $input, ?string $expected)
    {
        $this->assertSame($expected, Values::decimal($input));
    }

    public function test_it_splits_card_masks()
    {
        $this->assertSame(['411111******1111', '411111', '1111'], Values::card('411111******1111'));
        $this->assertSame(['4111 11XX XXXX 1234', '411111', '1234'], Values::card('4111 11XX XXXX 1234'));
        $this->assertSame([null, '555555', '4444'], Values::card(null, '555555', '4444'));
    }

    public function test_scheme_falls_back_to_bin_range()
    {
        $this->assertSame('visa', Values::ips('VISA CLASSIC'));
        $this->assertSame('mastercard', Values::ips('MC'));
        $this->assertSame('mastercard', Values::ips('card', '222100'));
        $this->assertSame('visa', Values::ips(null, '411111'));
        $this->assertNull(Values::ips('card', null));
    }

    public function test_region_comes_from_column_or_issuer_country()
    {
        $this->assertSame('eu', Values::region('EU'));
        $this->assertSame('non_eu', Values::region('Non-EU'));
        $this->assertSame('eu', Values::region(null, 'LV'));
        $this->assertSame('non_eu', Values::region(null, 'US'));
        $this->assertNull(Values::region(null, null));
    }

    public function test_times_are_converted_from_provider_zone_to_utc()
    {
        // Riga is UTC+3 in September (summer time).
        [$at, $on] = Values::datetime('2026-09-15 10:00:00', 'Europe/Riga');
        $this->assertSame('2026-09-15 07:00:00', $at->toDateTimeString());
        $this->assertSame('2026-09-15', $on->toDateString());

        // Just after midnight local is still the previous day in UTC, but the local day wins for dates.
        [$at, $on] = Values::datetime('16.09.2026 01:30', 'Europe/Riga');
        $this->assertSame('2026-09-15 22:30:00', $at->toDateTimeString());
        $this->assertSame('2026-09-16', $on->toDateString());

        // Explicit offsets are respected.
        [$at] = Values::datetime('2026-09-15T10:00:00+00:00', 'Europe/Riga');
        $this->assertSame('2026-09-15 10:00:00', $at->toDateTimeString());

        // Date only: no instant, just the day.
        [$at, $on] = Values::datetime('2026-09-15', 'Europe/Riga');
        $this->assertNull($at);
        $this->assertSame('2026-09-15', $on->toDateString());
    }

    public function test_it_finds_report_dates_in_text()
    {
        [$from, $to] = Values::dateRange('Processing date(s) 2026.08.07-2026.08.09');
        $this->assertSame(['2026-08-07', '2026-08-09'], [$from->toDateString(), $to->toDateString()]);

        [$from, $to] = Values::dateRange('Payout period 11.08.2026');
        $this->assertSame(['2026-08-11', '2026-08-11'], [$from->toDateString(), $to->toDateString()]);

        $this->assertNull(Values::dateRange('no dates here'));
    }
}
