<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Customer;

use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionStatus;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;

/**
 * Undo of a cancel, shared by the manage page, the account page and the product page. A cancelled pre-order is
 * restored only while the same person (its canonical e-mail address, or its customer) has no other active pre-order
 * for the same target in the same channel: two active rows of one person would let a restock run hold two units for
 * them. A refused pre-order stays cancelled.
 */
final class SubscriptionRestorer
{
    public const REFUSED_FLASH = 'webgriffe_bisn.restore_refused';

    public function __construct(private SubscriptionRepositoryInterface $subscriptionRepository)
    {
    }

    /**
     * @return bool whether the pre-order was restored
     */
    public function restore(SubscriptionInterface $subscription): bool
    {
        if (SubscriptionStatus::CANCELLED !== $subscription->getStatus() || $this->hasActiveDuplicate($subscription)) {
            return false;
        }

        $subscription->restore();
        $this->subscriptionRepository->add($subscription);

        return true;
    }

    private function hasActiveDuplicate(SubscriptionInterface $subscription): bool
    {
        $product = $subscription->getProduct();
        $channel = $subscription->getChannel();
        if (null === $product || null === $channel) {
            return false;
        }
        $variant = $subscription->getProductVariant();

        $emailCanonical = (string) $subscription->getEmailCanonical();
        if ('' !== $emailCanonical && null !== $this->subscriptionRepository->findActiveOne($emailCanonical, $product, $variant, $channel)) {
            return true;
        }

        $customer = $subscription->getCustomer();
        if (null === $customer) {
            return false;
        }
        foreach ($this->subscriptionRepository->findActiveByVisitor($customer, null, []) as $other) {
            if ($other !== $subscription && $other->isActive() && self::same($other->getChannel(), $channel) && self::same($other->getProduct(), $product) && self::sameVariant($other, $variant)) {
                return true;
            }
        }

        return false;
    }

    private static function sameVariant(SubscriptionInterface $other, ?object $variant): bool
    {
        $otherVariant = $other->getProductVariant();
        if (null === $otherVariant || null === $variant) {
            return null === $otherVariant && null === $variant;
        }

        return self::same($otherVariant, $variant);
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
