<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Factory;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\User\Canonicalizer\Canonicalizer;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\Subscription;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionStatus;
use Webgriffe\SyliusBackInStockNotificationPlugin\Factory\SubscriptionFactory;

final class SubscriptionFactoryTest extends TestCase
{
    public function testCanonicalEmailIsStored(): void
    {
        $factory = new SubscriptionFactory(Subscription::class, new Canonicalizer());

        $subscription = $factory->createWithData(
            $this->createMock(ChannelInterface::class),
            $this->createMock(ProductInterface::class),
            null,
            ' Anna@Pelda.hu ',
            'Anna',
            'hu_HU',
        );

        self::assertSame('Anna@Pelda.hu', $subscription->getEmail());
        self::assertSame('anna@pelda.hu', $subscription->getEmailCanonical());
        self::assertSame('Anna', $subscription->getName());
        self::assertNull($subscription->getProductVariant());
        self::assertSame(SubscriptionStatus::WAITING, $subscription->getStatus());
        self::assertNotEmpty($subscription->getHash());
    }

    public function testVariantIsStored(): void
    {
        $factory = new SubscriptionFactory(Subscription::class, new Canonicalizer());
        $variant = $this->createMock(ProductVariantInterface::class);

        $subscription = $factory->createWithData(
            $this->createMock(ChannelInterface::class),
            $this->createMock(ProductInterface::class),
            $variant,
            'a@b.hu',
            null,
            'hu_HU',
        );

        self::assertSame($variant, $subscription->getProductVariant());
    }
}
