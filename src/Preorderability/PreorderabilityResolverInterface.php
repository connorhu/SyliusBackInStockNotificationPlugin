<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Preorderability;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;

interface PreorderabilityResolverInterface
{
    /**
     * The product's own setting wins unless it inherits; then the product's taxons (each its own setting or the
     * nearest ancestor's), where any forbidden beats allowed; if everything inherits, the channel default.
     */
    public function isPreorderable(ProductInterface $product, ChannelInterface $channel): bool;
}
