<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Listener;

use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Customer\Model\CustomerInterface;
use Sylius\Component\User\Canonicalizer\Canonicalizer;
use Symfony\Component\EventDispatcher\GenericEvent;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\Subscription;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionStatus;
use Webgriffe\SyliusBackInStockNotificationPlugin\EventListener\OrderCompletedListener;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Target\TargetResolver;

final class OrderCompletedListenerTest extends TestCase
{
    /** @var list<SubscriptionInterface> */
    private array $subscriptions = [];

    /** @var list<string> */
    private array $logs = [];

    private int $flushes = 0;

    private ?\Throwable $lookupFailure = null;

    private Channel $channel;

    protected function setUp(): void
    {
        $this->channel = new Channel();
        $this->channel->setCode('WEB');
    }

    public function testFulfilsMatchingPreorderByEmail(): void
    {
        [$product, $variant] = $this->productWithVariant();
        $preorder = $this->subscription($product, 'anna@example.com');
        $preorder->markNotified(new \DateTimeImmutable('-1 hour'), $variant, new \DateTimeImmutable('+1 day'));

        $this->listener('product')->onOrderPostComplete(new GenericEvent($this->order('Anna@Example.com', $variant)));

        self::assertSame(SubscriptionStatus::FULFILLED, $preorder->getStatus());
        self::assertNotNull($preorder->getFulfilledAt());
        self::assertNull($preorder->getHeldVariant());
        self::assertNull($preorder->getHoldUntil());
        self::assertSame(1, $this->flushes);
    }

    public function testFulfilsWaitingPreorderOfTheCustomer(): void
    {
        [$product, $variant] = $this->productWithVariant();
        $order = $this->order('anna@example.com', $variant);
        $preorder = $this->subscription($product, 'old-address@example.com', $order->getCustomer());

        $this->listener('product')->onOrderPostComplete(new GenericEvent($order));

        self::assertSame(SubscriptionStatus::FULFILLED, $preorder->getStatus());
    }

    public function testDoesNotTouchOtherProducts(): void
    {
        [$product] = $this->productWithVariant();
        [, $otherVariant] = $this->productWithVariant();
        $preorder = $this->subscription($product, 'anna@example.com');

        $this->listener('product')->onOrderPostComplete(new GenericEvent($this->order('anna@example.com', $otherVariant)));

        self::assertSame(SubscriptionStatus::WAITING, $preorder->getStatus());
        self::assertSame(0, $this->flushes);
    }

    public function testVariantTargetNeedsTheSameVariant(): void
    {
        [$product, $variant] = $this->productWithVariant();
        $sibling = new ProductVariant();
        (new \ReflectionProperty(ProductVariant::class, 'id'))->setValue($sibling, 999);
        $product->addVariant($sibling);
        $preorder = $this->subscription($product, 'anna@example.com');
        $preorder->setProductVariant($variant);

        $this->listener('variant')->onOrderPostComplete(new GenericEvent($this->order('anna@example.com', $sibling)));
        self::assertSame(SubscriptionStatus::WAITING, $preorder->getStatus());

        $this->listener('variant')->onOrderPostComplete(new GenericEvent($this->order('anna@example.com', $variant)));
        self::assertSame(SubscriptionStatus::FULFILLED, $preorder->getStatus());
    }

    public function testFailureIsLoggedAndSwallowed(): void
    {
        [, $variant] = $this->productWithVariant();
        $this->lookupFailure = new \RuntimeException('database down');

        $this->listener('product')->onOrderPostComplete(new GenericEvent($this->order('anna@example.com', $variant)));

        self::assertSame(['webgriffe_bisn.order_fulfilment_failed'], $this->logs);
    }

    private function listener(string $target): OrderCompletedListener
    {
        $repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $repository->method('findActiveByVisitor')->willReturnCallback(function (?CustomerInterface $customer, ?string $emailCanonical, array $ids): array {
            if (null !== $this->lookupFailure) {
                throw $this->lookupFailure;
            }

            return array_values(array_filter($this->subscriptions, static fn (SubscriptionInterface $s): bool => $s->isActive() && (
                (null !== $customer && $s->getCustomer() === $customer) ||
                (null === $s->getCustomer() && null !== $emailCanonical && $s->getEmailCanonical() === $emailCanonical)
            )));
        });
        /** @var ObjectManager&MockObject $manager */
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message): void {
            $this->logs[] = $message;
        });

        return new OrderCompletedListener($repository, $manager, new TargetResolver($target, $repository), new Canonicalizer(), $logger);
    }

    /** @return array{Product, ProductVariant} */
    private function productWithVariant(): array
    {
        $product = new Product();
        $product->setCode('BOOK-' . spl_object_id($product));
        $variant = new ProductVariant();
        (new \ReflectionProperty(ProductVariant::class, 'id'))->setValue($variant, spl_object_id($variant));
        $product->addVariant($variant);

        return [$product, $variant];
    }

    private function order(string $email, ProductVariant $variant): Order
    {
        $customer = new Customer();
        $customer->setEmail($email);
        $order = new Order();
        $order->setChannel($this->channel);
        $order->setCustomer($customer);
        $item = new OrderItem();
        $item->setVariant($variant);
        $order->addItem($item);

        return $order;
    }

    private function subscription(Product $product, string $email, ?CustomerInterface $customer = null): Subscription
    {
        $subscription = new Subscription();
        $subscription->setProduct($product);
        $subscription->setChannel($this->channel);
        $subscription->setEmail($email);
        $subscription->setEmailCanonical(strtolower($email));
        $subscription->setCustomer($customer);
        $subscription->setCreatedAt(new \DateTimeImmutable('-3 days'));
        $this->subscriptions[] = $subscription;

        return $subscription;
    }
}
