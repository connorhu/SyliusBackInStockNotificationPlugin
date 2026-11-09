<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Customer;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Customer\SubscriptionRestorer;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\Subscription;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionStatus;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;

final class SubscriptionRestorerTest extends TestCase
{
    /** @var list<SubscriptionInterface> */
    private array $subscriptions = [];

    private SubscriptionRepositoryInterface&MockObject $repository;

    private Channel $channel;

    private Product $product;

    protected function setUp(): void
    {
        $this->channel = new Channel();
        $this->channel->setCode('WEB');
        $this->product = new Product();
        $this->product->setCode('BOOK');
        $this->repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $this->repository->method('findActiveOne')->willReturnCallback(
            fn (string $email, ProductInterface $product, ?ProductVariantInterface $variant, ChannelInterface $channel): ?SubscriptionInterface => array_values(array_filter(
                $this->subscriptions,
                static fn (SubscriptionInterface $s): bool => $s->isActive() && $s->getEmailCanonical() === $email && $s->getProduct() === $product && $s->getProductVariant() === $variant && $s->getChannel() === $channel,
            ))[0] ?? null,
        );
        $this->repository->method('findActiveByVisitor')->willReturnCallback(
            fn (?CustomerInterface $customer): array => array_values(array_filter(
                $this->subscriptions,
                static fn (SubscriptionInterface $s): bool => $s->isActive() && null !== $customer && $s->getCustomer() === $customer,
            )),
        );
    }

    public function testRestoresACancelledPreorder(): void
    {
        $cancelled = $this->subscription('anna@example.com');
        $cancelled->markCancelled(new \DateTimeImmutable());
        $this->repository->expects(self::once())->method('add')->with($cancelled);

        self::assertTrue((new SubscriptionRestorer($this->repository))->restore($cancelled));
        self::assertSame(SubscriptionStatus::WAITING, $cancelled->getStatus());
    }

    public function testRefusesWhenTheSameEmailHasAnotherActivePreorderForTheTarget(): void
    {
        $cancelled = $this->subscription('anna@example.com');
        $cancelled->markCancelled(new \DateTimeImmutable());
        $this->subscription('anna@example.com');
        $this->repository->expects(self::never())->method('add');

        self::assertFalse((new SubscriptionRestorer($this->repository))->restore($cancelled));
        self::assertSame(SubscriptionStatus::CANCELLED, $cancelled->getStatus());
    }

    public function testRefusesWhenTheSameCustomerHasAnotherActivePreorderUnderAnotherEmail(): void
    {
        $customer = new Customer();
        $cancelled = $this->subscription('old@example.com', $customer);
        $cancelled->markCancelled(new \DateTimeImmutable());
        $this->subscription('new@example.com', $customer);

        self::assertFalse((new SubscriptionRestorer($this->repository))->restore($cancelled));
        self::assertSame(SubscriptionStatus::CANCELLED, $cancelled->getStatus());
    }

    public function testAnotherPersonsPreorderOrAnotherProductDoesNotBlock(): void
    {
        $cancelled = $this->subscription('anna@example.com');
        $cancelled->markCancelled(new \DateTimeImmutable());
        $this->subscription('bela@example.com');
        $other = $this->subscription('anna@example.com');
        $otherProduct = new Product();
        $other->setProduct($otherProduct);

        self::assertTrue((new SubscriptionRestorer($this->repository))->restore($cancelled));
    }

    public function testDoesNotTouchANonCancelledPreorder(): void
    {
        $active = $this->subscription('anna@example.com');
        $this->repository->expects(self::never())->method('add');

        self::assertFalse((new SubscriptionRestorer($this->repository))->restore($active));
    }

    private function subscription(string $email, ?CustomerInterface $customer = null): Subscription
    {
        $subscription = new Subscription();
        $subscription->setProduct($this->product);
        $subscription->setChannel($this->channel);
        $subscription->setEmail($email);
        $subscription->setEmailCanonical($email);
        $subscription->setCustomer($customer);
        $this->subscriptions[] = $subscription;

        return $subscription;
    }
}
