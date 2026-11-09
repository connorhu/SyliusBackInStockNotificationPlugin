<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\State;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Hold\HoldVisitor;
use Webgriffe\SyliusBackInStockNotificationPlugin\Preorderability\PreorderabilityResolverInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Restock\SellableUnits;
use Webgriffe\SyliusBackInStockNotificationPlugin\Session\GuestPreorderSession;
use Webgriffe\SyliusBackInStockNotificationPlugin\Target\TargetResolverInterface;

/**
 * Resolves what the current visitor sees for a target: held for them, sellable, pre-ordered (their own pre-order, or
 * one this guest session created), held for others, can pre-order or not pre-orderable — in this order.
 *
 * Under the `variant` target a null variant considers every variant of the product and matches no pre-order.
 */
final class PreorderStateResolver
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private TargetResolverInterface $targetResolver,
        private SellableUnits $sellableUnits,
        private HoldVisitor $holdVisitor,
        private GuestPreorderSession $guestPreorderSession,
        private PreorderabilityResolverInterface $preorderabilityResolver,
    ) {
    }

    public function resolve(ProductInterface $product, ?ProductVariantInterface $variant, ChannelInterface $channel): PreorderView
    {
        $now = new \DateTimeImmutable();
        $variantTarget = TargetResolverInterface::TARGET_VARIANT === $this->targetResolver->target();
        if ($variantTarget && null !== $variant) {
            $variants = [$variant];
        } else {
            /** @var list<ProductVariantInterface> $variants */
            $variants = array_values($product->getVariants()->toArray());
        }
        $targetVariant = $variantTarget ? $variant : null;

        $own = $this->ofTarget($this->holdVisitor->subscriptions(), $product, $targetVariant, $variantTarget, $channel);
        foreach ($own as $subscription) {
            foreach ($variants as $candidate) {
                if ($this->holdVisitor->holdsActively($subscription, $candidate, $now)) {
                    return new PreorderView(PreorderState::HeldForYou, $subscription, $subscription->getHoldUntil());
                }
            }
        }

        $holds = [] === $variants ? [] : $this->subscriptionRepository->countActiveHoldsByVariant($variants, $now);
        $unitsExist = false;
        foreach ($variants as $candidate) {
            $units = $this->sellableUnits->of($candidate, $channel);
            if ($units <= 0) {
                continue;
            }
            $unitsExist = true;
            /** @psalm-suppress MixedAssignment */
            $id = $candidate->getId();
            $foreignHolds = is_int($id) ? ($holds[$id] ?? 0) : 0;
            if (!$candidate->isTracked() || $units > $foreignHolds || $this->holdVisitor->mayBuyHeld($candidate, $now)) {
                return new PreorderView(PreorderState::Sellable, $own[0] ?? null);
            }
        }

        $preordered = $own[0] ?? $this->guestPreorder($product, $targetVariant, $variantTarget, $channel);
        if (null !== $preordered) {
            return new PreorderView(PreorderState::Preordered, $preordered, null, $this->subscriptionRepository->queuePosition($preordered));
        }

        if ($unitsExist) {
            return new PreorderView(PreorderState::HeldForOthers);
        }

        return new PreorderView($this->preorderabilityResolver->isPreorderable($product, $channel) ? PreorderState::CanPreorder : PreorderState::NotPreorderable);
    }

    private function guestPreorder(ProductInterface $product, ?ProductVariantInterface $targetVariant, bool $variantTarget, ChannelInterface $channel): ?SubscriptionInterface
    {
        $ids = $this->guestPreorderSession->ids();
        if ([] === $ids) {
            return null;
        }

        return $this->ofTarget($this->subscriptionRepository->findActiveByVisitor(null, null, $ids), $product, $targetVariant, $variantTarget, $channel)[0] ?? null;
    }

    /**
     * @param list<SubscriptionInterface> $subscriptions
     *
     * @return list<SubscriptionInterface>
     */
    private function ofTarget(array $subscriptions, ProductInterface $product, ?ProductVariantInterface $targetVariant, bool $variantTarget, ChannelInterface $channel): array
    {
        return array_values(array_filter($subscriptions, static function (SubscriptionInterface $subscription) use ($product, $targetVariant, $variantTarget, $channel): bool {
            if (!$subscription->isActive() || !self::same($subscription->getChannel(), $channel) || !self::same($subscription->getProduct(), $product)) {
                return false;
            }
            $subscriptionVariant = $subscription->getProductVariant();
            if (!$variantTarget) {
                return null === $subscriptionVariant;
            }

            return null !== $targetVariant && null !== $subscriptionVariant && HoldVisitor::sameVariant($subscriptionVariant, $targetVariant);
        }));
    }

    private static function same(?object $a, object $b): bool
    {
        if (null === $a) {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        /** @psalm-suppress MixedAssignment */
        $aId = method_exists($a, 'getId') ? $a->getId() : null;

        return null !== $aId && method_exists($b, 'getId') && $aId === $b->getId();
    }
}
