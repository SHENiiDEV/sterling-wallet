<?php

namespace Tests\Feature\Admin;

use App\Models\FxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FxFetchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_days_rates_are_stored_with_their_cross_rates_and_a_hand_typed_rate_is_kept()
    {
        Http::fake(['api.frankfurter.dev/*' => Http::response(['base' => 'EUR', 'date' => '2026-11-24', 'rates' => ['GBP' => 0.8, 'USD' => 1.1]])]);
        FxRate::query()->create(['rate_date' => '2026-11-24', 'base' => 'EUR', 'quote' => 'USD', 'rate' => '1.2', 'source' => 'manual']);

        $this->artisan('fx:fetch')->assertSuccessful();

        $rate = fn (string $base, string $quote) => FxRate::query()->where(['rate_date' => '2026-11-24', 'base' => $base, 'quote' => $quote])->first();
        $this->assertEqualsWithDelta(0.8, (float) $rate('EUR', 'GBP')->rate, 0.000001);
        $this->assertEqualsWithDelta(1.375, (float) $rate('GBP', 'USD')->rate, 0.000001); // 1.1 / 0.8
        $this->assertSame('frankfurter', $rate('EUR', 'GBP')->source);
        // The rate typed by hand for that day stays.
        $this->assertEqualsWithDelta(1.2, (float) $rate('EUR', 'USD')->rate, 0.000001);
        $this->assertSame('manual', $rate('EUR', 'USD')->source);

        // Run again: nothing is repeated.
        $this->artisan('fx:fetch')->assertSuccessful();
        $this->assertSame(3, FxRate::query()->count());
    }

    public function test_a_range_fills_every_day_it_returns()
    {
        Http::fake(['api.frankfurter.dev/*' => Http::response(['base' => 'EUR', 'rates' => [
            '2026-11-20' => ['GBP' => 0.88, 'USD' => 1.05], '2026-11-23' => ['GBP' => 0.87, 'USD' => 1.06],
        ]])]);

        $this->artisan('fx:fetch', ['--from' => '2026-11-20', '--to' => '2026-11-24'])->assertSuccessful();

        $this->assertSame(6, FxRate::query()->count());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/2026-11-20..2026-11-24'));
    }

    public function test_an_unreachable_api_fails_without_storing_anything()
    {
        Http::fake(['api.frankfurter.dev/*' => Http::response('down', 503)]);

        $this->artisan('fx:fetch')->assertFailed();
        $this->assertSame(0, FxRate::query()->count());
    }

    public function test_the_rates_page_shows_the_daily_change_filters_by_pair_and_keeps_the_newest_day_as_latest()
    {
        $admin = \App\Models\User::factory()->create(['role' => \App\Enums\UserRole::SuperAdmin]);
        // The newer day is stored first, the older one (a back-fill) after it: "latest" is still the newer day.
        FxRate::query()->create(['rate_date' => '2026-11-24', 'base' => 'EUR', 'quote' => 'GBP', 'rate' => '0.88', 'source' => 'frankfurter']);
        FxRate::query()->create(['rate_date' => '2026-11-23', 'base' => 'EUR', 'quote' => 'GBP', 'rate' => '0.80', 'source' => 'frankfurter']);
        FxRate::query()->create(['rate_date' => '2026-11-24', 'base' => 'EUR', 'quote' => 'USD', 'rate' => '1.10', 'source' => 'manual']);

        $this->actingAs($admin)->get(route('admin.fx-rates.index', ['pair' => 'EUR-GBP']))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('latest', fn ($latest) => collect($latest)->firstWhere('quote', 'GBP')['rate_date'] === '2026-11-24')
                ->has('rates.data', 2)
                ->where('rates.data.0.change_percent', 10)
                ->where('rates.data.1.change_percent', null)
                ->where('pairs', ['EUR-GBP', 'EUR-USD']));
    }
}
