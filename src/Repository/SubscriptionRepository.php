<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
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

    public function findManageableByEmail(string $emailCanonical, ChannelInterface $channel): array
    {
        /** @var list<SubscriptionInterface> $result */
        $result = $this->createEmailQueryBuilder($emailCanonical, $channel)
            ->andWhere('subscription.status != :cancelled')
            ->andWhere('subscription.dismissedAt IS NULL')
            ->setParameter('cancelled', SubscriptionStatus::CANCELLED)
            ->getQuery()
            ->getResult()
        ;

        return $result;
    }

    public function findActiveByEmail(string $emailCanonical, ChannelInterface $channel): array
    {
        /** @var list<SubscriptionInterface> $result */
        $result = $this->createEmailQueryBuilder($emailCanonical, $channel)
            ->andWhere('subscription.status IN (:statuses)')
            ->setParameter('statuses', SubscriptionStatus::ACTIVE)
            ->getQuery()
            ->getResult()
        ;

        return $result;
    }

    public function findOneByHashAndEmail(string $hash, string $emailCanonical, ChannelInterface $channel): ?SubscriptionInterface
    {
        /** @var SubscriptionInterface|null $result */
        $result = $this->findOneBy(['hash' => $hash, 'emailCanonical' => $emailCanonical, 'channel' => $channel]);

        return $result;
    }

    public function findCancelledByIds(array $ids, string $emailCanonical, ChannelInterface $channel): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<SubscriptionInterface> $result */
        $result = $this
            ->createQueryBuilder('subscription')
            ->andWhere('subscription.id IN (:ids)')
            ->andWhere('subscription.emailCanonical = :emailCanonical')
            ->andWhere('subscription.status = :cancelled')
            ->andWhere('subscription.channel = :channel')
            ->setParameter('channel', $channel)
            ->setParameter('ids', $ids)
            ->setParameter('emailCanonical', $emailCanonical)
            ->setParameter('cancelled', SubscriptionStatus::CANCELLED)
            ->addOrderBy('subscription.createdAt', 'ASC')
            ->addOrderBy('subscription.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return $result;
    }

    public function findNewestByEmailOrCustomer(string $emailCanonical, ?CustomerInterface $customer, ChannelInterface $channel): ?SubscriptionInterface
    {
        $queryBuilder = $this
            ->createQueryBuilder('subscription')
            ->andWhere('subscription.channel = :channel')
            ->setParameter('channel', $channel)
            ->addOrderBy('subscription.createdAt', 'DESC')
            ->addOrderBy('subscription.id', 'DESC')
            ->setMaxResults(1)
        ;
        if (null === $customer) {
            $queryBuilder->andWhere('subscription.emailCanonical = :emailCanonical');
        } else {
            $queryBuilder
                ->andWhere('subscription.emailCanonical = :emailCanonical OR subscription.customer = :customer')
                ->setParameter('customer', $customer)
            ;
        }
        $queryBuilder->setParameter('emailCanonical', $emailCanonical);

        /** @var SubscriptionInterface|null $result */
        $result = $queryBuilder->getQuery()->getOneOrNullResult();

        return $result;
    }

    public function findRestockTargets(): array
    {
        $representatives = $this
            ->createQueryBuilder('target')
            ->select('MIN(target.id)')
            ->andWhere('target.status IN (:statuses)')
            ->groupBy('target.channel')
            ->addGroupBy('target.product')
            ->addGroupBy('target.productVariant')
        ;

        /** @var list<SubscriptionInterface> $result */
        $result = $this
            ->createQueryBuilder('subscription')
            ->andWhere(sprintf('subscription.id IN (%s)', $representatives->getDQL()))
            ->setParameter('statuses', SubscriptionStatus::ACTIVE)
            ->addOrderBy('subscription.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return $result;
    }

    private function createEmailQueryBuilder(string $emailCanonical, ChannelInterface $channel): QueryBuilder
    {
        return $this
            ->createQueryBuilder('subscription')
            ->andWhere('subscription.emailCanonical = :emailCanonical')
            ->andWhere('subscription.channel = :channel')
            ->setParameter('emailCanonical', $emailCanonical)
            ->setParameter('channel', $channel)
            ->addOrderBy('subscription.createdAt', 'ASC')
            ->addOrderBy('subscription.id', 'ASC')
        ;
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
