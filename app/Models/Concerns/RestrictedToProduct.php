<?php

namespace App\Models\Concerns;

use App\Enums\TariffProduct;
use App\Support\ProductScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every query of the model is narrowed to one product while a product-restricted
 * user is signed in, route binding and relations included.
 */
trait RestrictedToProduct
{
    protected static function bootRestrictedToProduct(): void
    {
        static::addGlobalScope('product', function (Builder $query) {
            $product = ProductScope::current();

            if ($product !== null) {
                static::restrictToProduct($query, $product);
            }
        });
    }

    /**
     * @param  Builder<static>  $query
     */
    abstract protected static function restrictToProduct(Builder $query, TariffProduct $product): void;
}
