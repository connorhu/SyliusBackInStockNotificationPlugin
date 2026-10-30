<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderChannelSettingsInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionStatus;
use Webgriffe\SyliusBackInStockNotificationPlugin\State\QueuePosition;

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

    public function countActiveHoldsByVariant(array $variants, \DateTimeImmutable $now): array
    {
        if ([] === $variants) {
            return [];
        }

        return $this->countHolds(
            $this->createActiveHoldsQueryBuilder($now)
                ->andWhere('subscription.heldVariant IN (:variants)')
                ->setParameter('variants', $variants),
        );
    }

    public function countAllActiveHolds(\DateTimeImmutable $now): array
    {
        return $this->countHolds($this->createActiveHoldsQueryBuilder($now));
    }

    private function createActiveHoldsQueryBuilder(\DateTimeImmutable $now): QueryBuilder
    {
        return $this
            ->createQueryBuilder('subscription')
            ->select('IDENTITY(subscription.heldVariant) AS variantId', 'COUNT(subscription.id) AS holds')
            ->andWhere('subscription.heldVariant IS NOT NULL')
            ->andWhere('subscription.status = :notified')
            ->andWhere('subscription.holdUntil > :now')
            ->setParameter('notified', SubscriptionStatus::NOTIFIED)
            ->setParameter('now', $now)
            ->groupBy('subscription.heldVariant')
        ;
    }

    /**
     * @return array<int, int>
     */
    private function countHolds(QueryBuilder $queryBuilder): array
    {
        /** @var list<array{variantId: int|string, holds: int|string}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['variantId']] = (int) $row['holds'];
        }

        return $counts;
    }

    public function findActiveByVisitor(?CustomerInterface $customer, ?string $emailCanonical, array $ids): array
    {
        $conditions = [];
        $queryBuilder = $this
            ->createQueryBuilder('subscription')
            ->andWhere('subscription.status IN (:statuses)')
            ->setParameter('statuses', SubscriptionStatus::ACTIVE)
            ->addOrderBy('subscription.createdAt', 'ASC')
            ->addOrderBy('subscription.id', 'ASC')
        ;
        if (null !== $customer) {
            $conditions[] = 'subscription.customer = :customer';
            $queryBuilder->setParameter('customer', $customer);
        }
        if (null !== $emailCanonical && '' !== $emailCanonical) {
            $conditions[] = '(subscription.customer IS NULL AND subscription.emailCanonical = :emailCanonical)';
            $queryBuilder->setParameter('emailCanonical', $emailCanonical);
        }
        if ([] !== $ids) {
            $conditions[] = 'subscription.id IN (:ids)';
            $queryBuilder->setParameter('ids', $ids);
        }
        if ([] === $conditions) {
            return [];
        }

        /** @var list<SubscriptionInterface> $result */
        $result = $queryBuilder->andWhere(implode(' OR ', $conditions))->getQuery()->getResult();

        return $result;
    }

    public function queuePosition(SubscriptionInterface $subscription): ?int
    {
        $product = $subscription->getProduct();
        $channel = $subscription->getChannel();
        if (SubscriptionStatus::WAITING !== $subscription->getStatus() || null === $product || null === $channel) {
            return null;
        }

        return QueuePosition::among($subscription, $this->findActiveByTarget($product, $subscription->getProductVariant(), $channel));
    }

    public function activeHoldDeadlinesByProduct(array $productIds, ChannelInterface $channel): array
    {
        if ([] === $productIds) {
            return [];
        }

        /** @var list<array{productId: int|string, holdUntil: \DateTimeInterface|string|null}> $rows */
        $rows = $this
            ->createQueryBuilder('subscription')
            ->select('IDENTITY(subscription.product) AS productId', 'MAX(subscription.holdUntil) AS holdUntil')
            ->andWhere('subscription.product IN (:productIds)')
            ->andWhere('subscription.channel = :channel')
            ->andWhere('subscription.status = :notified')
            ->andWhere('subscription.heldVariant IS NOT NULL')
            ->andWhere('subscription.holdUntil > :now')
            ->setParameter('productIds', $productIds)
            ->setParameter('channel', $channel)
            ->setParameter('notified', SubscriptionStatus::NOTIFIED)
            ->setParameter('now', new \DateTimeImmutable())
            ->groupBy('subscription.product')
            ->getQuery()
            ->getArrayResult()
        ;

        $deadlines = [];
        foreach ($rows as $row) {
            $holdUntil = $row['holdUntil'];
            if (null === $holdUntil) {
                continue;
            }
            $deadlines[(int) $row['productId']] = $holdUntil instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($holdUntil)
                : new \DateTimeImmutable($holdUntil);
        }

        return $deadlines;
    }

    public function accountSummary(CustomerInterface $customer, ChannelInterface $channel): array
    {
        $fastest = $channel instanceof PreorderChannelSettingsInterface && PreorderChannelSettingsInterface::MODE_FASTEST === $channel->getPreorderMode();
        $actionable = $fastest
            ? 'subscription.status = :notified'
            : 'subscription.status = :notified AND subscription.heldVariant IS NOT NULL AND subscription.holdUntil > :now';

        $queryBuilder = $this
            ->createQueryBuilder('subscription')
            ->select('COUNT(subscription.id) AS active', sprintf('SUM(CASE WHEN %s THEN 1 ELSE 0 END) AS actionable', $actionable))
            ->andWhere('subscription.channel = :channel')
            ->andWhere('subscription.status IN (:statuses)')
            ->setParameter('channel', $channel)
            ->setParameter('statuses', SubscriptionStatus::ACTIVE)
            ->setParameter('notified', SubscriptionStatus::NOTIFIED)
        ;
        if (!$fastest) {
            $queryBuilder->setParameter('now', new \DateTimeImmutable());
        }
        $emailCanonical = $customer->getEmailCanonical();
        if (null === $emailCanonical || '' === $emailCanonical) {
            $queryBuilder->andWhere('subscription.customer = :customer');
        } else {
            $queryBuilder
                ->andWhere('subscription.customer = :customer OR (subscription.customer IS NULL AND subscription.emailCanonical = :emailCanonical)')
                ->setParameter('emailCanonical', $emailCanonical)
            ;
        }
        $queryBuilder->setParameter('customer', $customer);

        /** @var array{active: int|string|null, actionable: int|string|null} $row */
        $row = $queryBuilder->getQuery()->getSingleResult();

        return ['active' => (int) $row['active'], 'actionable' => (int) $row['actionable'] > 0];
    }

    public function createForAccountQueryBuilder(CustomerInterface $customer, ChannelInterface $channel): QueryBuilder
    {
        $queryBuilder = $this
            ->createQueryBuilder('subscription')
            ->andWhere('subscription.channel = :channel')
            ->andWhere('subscription.status != :cancelled')
            ->andWhere('subscription.dismissedAt IS NULL')
            ->setParameter('channel', $channel)
            ->setParameter('cancelled', SubscriptionStatus::CANCELLED)
            ->addOrderBy('subscription.createdAt', 'DESC')
            ->addOrderBy('subscription.id', 'DESC')
        ;
        $emailCanonical = $customer->getEmailCanonical();
        if (null === $emailCanonical || '' === $emailCanonical) {
            $queryBuilder->andWhere('subscription.customer = :customer');
        } else {
            $queryBuilder
                ->andWhere('subscription.customer = :customer OR (subscription.customer IS NULL AND subscription.emailCanonical = :emailCanonical)')
                ->setParameter('emailCanonical', $emailCanonical)
            ;
        }

        return $queryBuilder->setParameter('customer', $customer);
    }

    public function queuePositions(array $subscriptions): array
    {
        $ids = [];
        foreach ($subscriptions as $subscription) {
            /** @psalm-suppress MixedAssignment */
            $id = $subscription->getId();
            if (is_int($id) && SubscriptionStatus::WAITING === $subscription->getStatus()) {
                $ids[] = $id;
            }
        }
        if ([] === $ids) {
            return [];
        }

        // The same rule as QueuePosition::among(): earlier (createdAt, then id) waiting pre-orders of the same target.
        $earlier = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(earlier.id)')
            ->from($this->getEntityName(), 'earlier')
            ->andWhere('earlier.status = :waiting')
            ->andWhere('earlier.channel = subscription.channel')
            ->andWhere('earlier.product = subscription.product')
            ->andWhere('(earlier.productVariant IS NULL AND subscription.productVariant IS NULL) OR earlier.productVariant = subscription.productVariant')
            ->andWhere('earlier.createdAt < subscription.createdAt OR (earlier.createdAt = subscription.createdAt AND earlier.id < subscription.id)')
        ;

        /** @var list<array{id: int|string, earlierCount: int|string}> $rows */
        $rows = $this
            ->createQueryBuilder('subscription')
            ->select('subscription.id AS id', sprintf('(%s) AS earlierCount', $earlier->getDQL()))
            ->andWhere('subscription.id IN (:ids)')
            ->andWhere('subscription.status = :waiting')
            ->setParameter('ids', $ids)
            ->setParameter('waiting', SubscriptionStatus::WAITING)
            ->getQuery()
            ->getArrayResult()
        ;

        $positions = [];
        foreach ($rows as $row) {
            $positions[(int) $row['id']] = (int) $row['earlierCount'] + 1;
        }

        return $positions;
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
