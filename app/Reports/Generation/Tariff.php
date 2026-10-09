<?php

namespace App\Reports\Generation;

use App\Models\MerchantOperation;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads a percent / fixed tariff from a merchant (`fee_*`) or a provider
 * (`cost_*`) — both use the same column layout under a different prefix.
 */
final readonly class Tariff
{
    public function __construct(private Model $source, private string $prefix) {}

    public static function merchant(Model $merchant): self
    {
        return new self($merchant, 'fee_');
    }

    public static function provider(Model $provider): self
    {
        return new self($provider, 'cost_');
    }

    /**
     * Percent for a sale: Visa / Mastercard × EU / non-EU, falling back to
     * the acquiring (card-agnostic) rate when the scheme rate isn't set.
     * An unknown region is priced as non-EU. Apple Pay / Google Pay add
     * the wallet surcharge.
     */
    public function percentFor(MerchantOperation $op): BigDecimal
    {
        $region = $op->region === 'eu' ? 'eu' : 'non_eu';
        $scheme = match ($op->ips) {
            'visa' => 'visa',
            'mastercard' => 'mastercard',
            default => null,
        };

        $value = $scheme ? $this->source->getAttribute("{$this->prefix}{$scheme}_{$region}_percent") : null;
        $value ??= $this->source->getAttribute("{$this->prefix}acq_{$region}_percent");
        $percent = BigDecimal::of($value ?? 0);

        // Apple Pay / Google Pay come on top of the card rate.
        return $op->wallet ? $percent->plus($this->percent('wallet')) : $percent;
    }

    public function fixed(string $name): BigDecimal
    {
        return BigDecimal::of($this->source->getAttribute("{$this->prefix}{$name}_fixed") ?? 0);
    }

    public function percent(string $name): BigDecimal
    {
        return BigDecimal::of($this->source->getAttribute("{$this->prefix}{$name}_percent") ?? 0);
    }
}
