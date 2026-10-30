<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\State;

use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;

/**
 * The resolved pre-order state of a target for the current visitor, as templates render it.
 *
 * @psalm-suppress PossiblyUnusedProperty read by templates
 */
final class PreorderView
{
    public function __construct(
        public readonly PreorderState $state,
        public readonly ?SubscriptionInterface $subscription = null,
        public readonly ?\DateTimeImmutable $holdUntil = null,
        /** Queue position of a waiting pre-order (1 = next), null otherwise. */
        public readonly ?int $position = null,
    ) {
    }
}
