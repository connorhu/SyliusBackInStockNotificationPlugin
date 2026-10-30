<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Target;

use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;

final class TargetResolver implements TargetResolverInterface
{
    public function __construct(
        private string $target,
        private SubscriptionRepositoryInterface $subscriptionRepository,
    ) {
    }

    public function target(): string
    {
        return $this->target;
    }

    public function variantsOf(SubscriptionInterface $subscription): array
    {
        if (self::TARGET_VARIANT === $this->target) {
            $variant = $subscription->getProductVariant();

            return null === $variant ? [] : [$variant];
        }

        $product = $subscription->getProduct();
        if (null === $product) {
            return [];
        }

        /** @var list<ProductVariantInterface> $variants */
        $variants = array_values($product->getVariants()->toArray());

        return $variants;
    }

    public function findActive(string $emailCanonical, ProductInterface $product, ?ProductVariantInterface $variant, ChannelInterface $channel): ?SubscriptionInterface
    {
        return $this->subscriptionRepository->findActiveOne($emailCanonical, $product, $variant, $channel);
    }
}
