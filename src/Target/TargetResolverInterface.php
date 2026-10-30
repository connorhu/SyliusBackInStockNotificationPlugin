<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Target;

use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;

interface TargetResolverInterface
{
    public const TARGET_PRODUCT = 'product';

    public const TARGET_VARIANT = 'variant';

    /** The configured target: `product` or `variant`. */
    public function target(): string;

    /**
     * The variants whose availability satisfies the subscription.
     *
     * @return list<ProductVariantInterface>
     */
    public function variantsOf(SubscriptionInterface $subscription): array;

    public function findActive(string $emailCanonical, ProductInterface $product, ?ProductVariantInterface $variant, ChannelInterface $channel): ?SubscriptionInterface;
}
