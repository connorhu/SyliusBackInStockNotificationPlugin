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
use Sylius\Component\Customer\Model\CustomerInterface;
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

    /**
     * Pre-orders of an e-mail address (canonical form) on a channel that are neither cancelled nor dismissed, oldest first.
     *
     * @return list<SubscriptionInterface>
     */
    public function findManageableByEmail(string $emailCanonical, ChannelInterface $channel): array;

    /**
     * Active (waiting or notified) pre-orders of an e-mail address (canonical form) on a channel, oldest first.
     *
     * @return list<SubscriptionInterface>
     */
    public function findActiveByEmail(string $emailCanonical, ChannelInterface $channel): array;

    public function findOneByHashAndEmail(string $hash, string $emailCanonical, ChannelInterface $channel): ?SubscriptionInterface;

    /**
     * @param list<int> $ids
     *
     * @return list<SubscriptionInterface>
     */
    public function findCancelledByIds(array $ids, string $emailCanonical, ChannelInterface $channel): array;

    /**
     * The newest pre-order of an e-mail address (canonical form) or of a customer on a channel, in any status.
     */
    public function findNewestByEmailOrCustomer(string $emailCanonical, ?CustomerInterface $customer, ChannelInterface $channel): ?SubscriptionInterface;

    /**
     * One active (waiting or notified) subscription per target (channel, product and, for the variant target, variant),
     * lowest id first; the restock run loads each target's subscriptions with findActiveByTarget().
     *
     * @return list<SubscriptionInterface>
     */
    public function findRestockTargets(): array;

    /**
     * Active holds (notified, holdUntil > now) on the given variants in every channel, keyed by variant id; stock is shared
     * by all channels, so a unit held in one channel is not free in another. Variants without a hold are left out.
     *
     * @param list<ProductVariantInterface> $variants
     *
     * @return array<int, int>
     */
    public function countActiveHoldsByVariant(array $variants, \DateTimeImmutable $now): array;
}
