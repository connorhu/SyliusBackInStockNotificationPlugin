<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Restock;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;

/**
 * How many units of a variant anybody could buy in a channel, holds ignored.
 *
 * The availability checker must be Sylius' plain one, never a hold-aware decorator.
 */
final class SellableUnits
{
    public function __construct(
        private AvailabilityCheckerInterface $availabilityChecker,
    ) {
    }

    public function of(ProductVariantInterface $variant, ChannelInterface $channel): int
    {
        $product = $variant->getProduct();
        if (!$product instanceof ProductInterface || !$product->isEnabled() || !$variant->isEnabled()) {
            return 0;
        }
        if (!$product->hasChannel($channel)) {
            return 0;
        }
        if (null === $variant->getChannelPricingForChannel($channel)?->getPrice()) {
            return 0;
        }
        if (!$this->availabilityChecker->isStockAvailable($variant)) {
            return 0;
        }
        if (!$variant->isTracked()) {
            return \PHP_INT_MAX;
        }

        return max(0, (int) $variant->getOnHand() - (int) $variant->getOnHold());
    }
}
