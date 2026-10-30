<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\RateLimit;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderChannelSettingsInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;

/**
 * Two limits on new pre-orders: one per e-mail / customer per channel interval (checked in the database, so the
 * admin can edit it) and a sliding window per client IP (bundle configuration).
 */
final class PreorderRateLimiter
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private RateLimiterFactory $ipLimiterFactory,
    ) {
    }

    /**
     * False when a new pre-order must be refused. The IP token is only consumed when the e-mail check passed, so a
     * refused attempt does not eat into the shared-network allowance.
     */
    public function allow(
        string $emailCanonical,
        ?CustomerInterface $customer,
        ?string $clientIp,
        ChannelInterface $channel,
        \DateTimeImmutable $now,
    ): bool {
        $interval = self::intervalSeconds($channel);
        if ($interval > 0) {
            $newest = $this->subscriptionRepository->findNewestByEmailOrCustomer($emailCanonical, $customer, $channel);
            $createdAt = $newest?->getCreatedAt();
            if (null !== $createdAt && $createdAt->getTimestamp() + $interval > $now->getTimestamp()) {
                return false;
            }
        }

        if (null === $clientIp || '' === $clientIp) {
            return true;
        }

        return $this->ipLimiterFactory->create($clientIp)->consume()->isAccepted();
    }

    public static function intervalSeconds(ChannelInterface $channel): int
    {
        return $channel instanceof PreorderChannelSettingsInterface
            ? max(0, $channel->getPreorderRateLimitSeconds())
            : PreorderChannelSettingsInterface::DEFAULT_RATE_LIMIT_SECONDS;
    }
}
