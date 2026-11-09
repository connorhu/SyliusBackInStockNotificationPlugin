<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Customer;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\Subscription;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepository;

final class AccountQueryTest extends TestCase
{
    public function testAccountQueryFetchJoinsWhatTheRowsRender(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('createQueryBuilder')->willReturnCallback(static fn (): QueryBuilder => new QueryBuilder($entityManager));
        $repository = new SubscriptionRepository($entityManager, new ClassMetadata(Subscription::class));

        $customer = new Customer();
        $customer->setEmailCanonical('anna@example.com');
        $queryBuilder = $repository->createForAccountQueryBuilder($customer, new Channel());
        $dql = $queryBuilder->getDQL();

        self::assertStringContainsString('SELECT subscription, product, translation, variant', $dql);
        self::assertStringContainsString('INNER JOIN subscription.product product', $dql);
        self::assertStringContainsString('LEFT JOIN product.translations translation', $dql);
        self::assertStringContainsString('LEFT JOIN subscription.productVariant variant', $dql);
        self::assertStringContainsString('subscription.dismissedAt IS NULL', $dql);
        self::assertStringContainsString('subscription.status != :cancelled', $dql);
    }
}
