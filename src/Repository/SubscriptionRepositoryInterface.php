<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Repository;

if (!interface_exists(\Sylius\Resource\Doctrine\Persistence\RepositoryInterface::class)) {
    class_alias(\Sylius\Component\Resource\Repository\RepositoryInterface::class, \Sylius\Resource\Doctrine\Persistence\RepositoryInterface::class);
}
use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;

/**
 * @extends RepositoryInterface<SubscriptionInterface>
 */
interface SubscriptionRepositoryInterface extends RepositoryInterface
{
    public function createByCustomerIdAndChannelIdQueryBuilder(int $customerId, int $channelId): QueryBuilder;

    /**
     * Active (waiting or notified) subscriptions of a target, oldest first. A null variant means the product target.
     *
     * @return list<SubscriptionInterface>
     */
    public function findActiveByTarget(ProductInterface $product, ?ProductVariantInterface $variant, ChannelInterface $channel): array;

    /**
     * The active subscription of an e-mail address (canonical form) for a target, if any.
     */
    public function findActiveOne(string $emailCanonical, ProductInterface $product, ?ProductVariantInterface $variant, ChannelInterface $channel): ?SubscriptionInterface;
}
