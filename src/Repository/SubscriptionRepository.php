<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionStatus;

final class SubscriptionRepository extends EntityRepository implements SubscriptionRepositoryInterface
{
    public function createByCustomerIdAndChannelIdQueryBuilder(int $customerId, int $channelId): QueryBuilder
    {
        return $this
            ->createQueryBuilder('subscription')
            ->andWhere('subscription.customer = :customerId')
            ->andWhere('subscription.channel = :channelId')
            ->setParameter('customerId', $customerId)
            ->setParameter('channelId', $channelId)
        ;
    }

    public function findActiveByTarget(ProductInterface $product, ?ProductVariantInterface $variant, ChannelInterface $channel): array
    {
        /** @var list<SubscriptionInterface> $result */
        $result = $this->createActiveTargetQueryBuilder($product, $variant, $channel)
            ->addOrderBy('subscription.createdAt', 'ASC')
            ->addOrderBy('subscription.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return $result;
    }

    public function findActiveOne(string $emailCanonical, ProductInterface $product, ?ProductVariantInterface $variant, ChannelInterface $channel): ?SubscriptionInterface
    {
        /** @var SubscriptionInterface|null $result */
        $result = $this->createActiveTargetQueryBuilder($product, $variant, $channel)
            ->andWhere('subscription.emailCanonical = :emailCanonical')
            ->setParameter('emailCanonical', $emailCanonical)
            ->addOrderBy('subscription.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $result;
    }

    private function createActiveTargetQueryBuilder(ProductInterface $product, ?ProductVariantInterface $variant, ChannelInterface $channel): QueryBuilder
    {
        $queryBuilder = $this
            ->createQueryBuilder('subscription')
            ->andWhere('subscription.product = :product')
            ->andWhere('subscription.channel = :channel')
            ->andWhere('subscription.status IN (:statuses)')
            ->setParameter('product', $product)
            ->setParameter('channel', $channel)
            ->setParameter('statuses', SubscriptionStatus::ACTIVE)
        ;
        if (null === $variant) {
            $queryBuilder->andWhere('subscription.productVariant IS NULL');
        } else {
            $queryBuilder->andWhere('subscription.productVariant = :variant')->setParameter('variant', $variant);
        }

        return $queryBuilder;
    }
}
