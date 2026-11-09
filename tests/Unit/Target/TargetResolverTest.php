<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Target;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\Subscription;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Target\TargetResolver;

final class TargetResolverTest extends TestCase
{
    public function testProductTargetReturnsAllVariants(): void
    {
        $a = $this->createMock(ProductVariantInterface::class);
        $b = $this->createMock(ProductVariantInterface::class);
        $product = $this->createMock(ProductInterface::class);
        $product->method('getVariants')->willReturn(new ArrayCollection([$a, $b]));
        $subscription = new Subscription();
        $subscription->setProduct($product);

        $resolver = new TargetResolver('product', $this->createMock(SubscriptionRepositoryInterface::class));

        self::assertSame('product', $resolver->target());
        self::assertSame([$a, $b], $resolver->variantsOf($subscription));
    }

    public function testVariantTargetReturnsOnlyThatVariant(): void
    {
        $a = $this->createMock(ProductVariantInterface::class);
        $subscription = new Subscription();
        $subscription->setProduct($this->createMock(ProductInterface::class));
        $subscription->setProductVariant($a);

        $resolver = new TargetResolver('variant', $this->createMock(SubscriptionRepositoryInterface::class));

        self::assertSame([$a], $resolver->variantsOf($subscription));
    }

    public function testFindActiveDelegatesToRepository(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $channel = $this->createMock(ChannelInterface::class);
        $found = $this->createMock(SubscriptionInterface::class);
        $repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findActiveOne')
            ->with('anna@pelda.hu', $product, null, $channel)
            ->willReturn($found)
        ;

        $resolver = new TargetResolver('product', $repository);

        self::assertSame($found, $resolver->findActive('anna@pelda.hu', $product, null, $channel));
    }
}
