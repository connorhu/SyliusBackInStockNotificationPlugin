<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Account;

use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;

/**
 * One line of the account list: a pre-order and how to present it.
 *
 * @psalm-suppress PossiblyUnusedProperty read by templates
 */
final class AccountRow
{
    /** Waiting in the queue (or, in the fastest mode, just waiting). */
    public const STATE_WAITING = 'waiting';

    /** Notified, with a copy held for this customer. */
    public const STATE_HELD = 'held';

    /** Notified without a hold (fastest mode, or the hold ended): it may still be bought while it is available. */
    public const STATE_ARRIVED = 'arrived';

    /** Bought; the customer can remove the line. */
    public const STATE_FULFILLED = 'fulfilled';

    public function __construct(
        public readonly SubscriptionInterface $subscription,
        public readonly string $state,
        /** Queue position of a waiting pre-order (1 = next), null otherwise. */
        public readonly ?int $position = null,
    ) {
    }

    /**
     * @param array<int, int> $positions queue positions keyed by subscription id
     */
    public static function create(SubscriptionInterface $subscription, \DateTimeImmutable $now, array $positions): self
    {
        $holdUntil = $subscription->getHoldUntil();
        $held = null !== $subscription->getHeldVariant() && null !== $holdUntil && $holdUntil > $now;

        $state = match ($subscription->getStatus()) {
            'fulfilled' => self::STATE_FULFILLED,
            'notified' => $held ? self::STATE_HELD : self::STATE_ARRIVED,
            default => self::STATE_WAITING,
        };

        /** @psalm-suppress MixedAssignment, MixedArrayOffset */
        $position = $positions[$subscription->getId()] ?? null;

        return new self($subscription, $state, self::STATE_WAITING === $state ? $position : null);
    }
}
