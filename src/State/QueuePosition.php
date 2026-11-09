<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\State;

use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionStatus;

/**
 * The queue position rule: 1 + the waiting pre-orders of the same target (channel, product and variant or none) that
 * came earlier (createdAt, then id). Only a waiting pre-order has a position.
 */
final class QueuePosition
{
    /**
     * @param iterable<SubscriptionInterface> $candidates other rows may be among them; they are filtered here
     */
    public static function among(SubscriptionInterface $subscription, iterable $candidates): ?int
    {
        if (SubscriptionStatus::WAITING !== $subscription->getStatus()) {
            return null;
        }

        $key = self::order($subscription);
        $position = 1;
        foreach ($candidates as $candidate) {
            if ($candidate === $subscription || SubscriptionStatus::WAITING !== $candidate->getStatus()) {
                continue;
            }
            if (!self::sameTarget($subscription, $candidate)) {
                continue;
            }
            if (self::order($candidate) < $key) {
                ++$position;
            }
        }

        return $position;
    }

    /** @return array{int, int} */
    private static function order(SubscriptionInterface $subscription): array
    {
        return [(int) $subscription->getCreatedAt()?->getTimestamp(), (int) $subscription->getId()];
    }

    private static function sameTarget(SubscriptionInterface $a, SubscriptionInterface $b): bool
    {
        return self::same($a->getChannel(), $b->getChannel()) &&
            self::same($a->getProduct(), $b->getProduct()) &&
            self::same($a->getProductVariant(), $b->getProductVariant());
    }

    private static function same(?object $a, ?object $b): bool
    {
        if (null === $a || null === $b) {
            return $a === $b;
        }
        if ($a === $b) {
            return true;
        }
        /** @psalm-suppress MixedAssignment */
        $aId = method_exists($a, 'getId') ? $a->getId() : null;
        /** @psalm-suppress MixedAssignment */
        $bId = method_exists($b, 'getId') ? $b->getId() : null;

        return null !== $aId && $aId === $bId;
    }
}
