<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\State;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
use Sylius\Component\Inventory\Checker\AvailabilityChecker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\Subscription;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Hold\ClaimSession;
use Webgriffe\SyliusBackInStockNotificationPlugin\Hold\HoldVisitor;
use Webgriffe\SyliusBackInStockNotificationPlugin\Preorderability\PreorderabilityResolverInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Restock\SellableUnits;
use Webgriffe\SyliusBackInStockNotificationPlugin\Session\GuestPreorderSession;
use Webgriffe\SyliusBackInStockNotificationPlugin\State\PreorderState;
use Webgriffe\SyliusBackInStockNotificationPlugin\State\PreorderStateResolver;
use Webgriffe\SyliusBackInStockNotificationPlugin\State\PreorderView;
use Webgriffe\SyliusBackInStockNotificationPlugin\State\QueuePosition;
use Webgriffe\SyliusBackInStockNotificationPlugin\Target\TargetResolver;

final class PreorderStateResolverTest extends TestCase
{
    private ?CustomerInterface $customer = null;

    private bool $preorderable = true;

    /** @var list<SubscriptionInterface> */
    private array $subscriptions = [];

    private RequestStack $requestStack;

    private Channel $channel;

    private Product $product;

    protected function setUp(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack = new RequestStack();
        $this->requestStack->push($request);
        $this->channel = new Channel();
        $this->channel->setCode('WEB');
        $this->product = new Product();
        $this->product->setCode('BOOK');
        $this->product->setEnabled(true);
        $this->product->addChannel($this->channel);
    }

    public function testSellable(): void
    {
        $this->variant(1);

        $view = $this->resolve();

        self::assertSame(PreorderState::Sellable, $view->state);
        self::assertNull($view->subscription);
    }

    public function testNotPreorderable(): void
    {
        $this->variant(0);
        $this->preorderable = false;

        self::assertSame(PreorderState::NotPreorderable, $this->resolve()->state);
    }

    public function testCanPreorder(): void
    {
        $this->variant(0);

        self::assertSame(PreorderState::CanPreorder, $this->resolve()->state);
    }

    public function testPreordered(): void
    {
        $this->variant(0);
        $this->customer = $this->customer('anna@example.com');
        $anna = $this->subscription('anna@example.com', '-1 day', $this->customer);

        $view = $this->resolve();

        self::assertSame(PreorderState::Preordered, $view->state);
        self::assertSame($anna, $view->subscription);
        self::assertSame(1, $view->position);
        self::assertNull($view->holdUntil);
    }

    public function testHeldForYou(): void
    {
        $variant = $this->variant(1);
        $this->customer = $this->customer('anna@example.com');
        $anna = $this->subscription('anna@example.com', '-1 day', $this->customer);
        $until = new \DateTimeImmutable('+5 hours');
        $anna->markNotified(new \DateTimeImmutable('-1 hour'), $variant, $until);

        $view = $this->resolve();

        self::assertSame(PreorderState::HeldForYou, $view->state);
        self::assertSame($anna, $view->subscription);
        self::assertEquals($until, $view->holdUntil);
        self::assertNull($view->position);
    }

    public function testHoldOnASoldUnitIsNotHeldForYou(): void
    {
        $variant = $this->variant(0);
        $this->customer = $this->customer('anna@example.com');
        $anna = $this->subscription('anna@example.com', '-1 day', $this->customer);
        $anna->markNotified(new \DateTimeImmutable('-1 hour'), $variant, new \DateTimeImmutable('+5 hours'));

        $view = $this->resolve();

        self::assertSame(PreorderState::Preordered, $view->state);
        self::assertSame($anna, $view->subscription);
        self::assertNull($view->holdUntil);
    }

    public function testHeldForOthers(): void
    {
        $variant = $this->variant(1);
        $bela = $this->subscription('bela@example.com', '-2 days');
        $bela->markNotified(new \DateTimeImmutable('-1 hour'), $variant, new \DateTimeImmutable('+5 hours'));

        $view = $this->resolve();

        self::assertSame(PreorderState::HeldForOthers, $view->state);
        self::assertNull($view->subscription);
    }

    public function testWaitingVisitorSeesPreorderedWhileUnitsAreHeldForOthers(): void
    {
        $variant = $this->variant(1);
        $bela = $this->subscription('bela@example.com', '-2 days');
        $bela->markNotified(new \DateTimeImmutable('-1 hour'), $variant, new \DateTimeImmutable('+5 hours'));
        $this->customer = $this->customer('anna@example.com');
        $this->subscription('anna@example.com', '-1 day', $this->customer);

        self::assertSame(PreorderState::Preordered, $this->resolve()->state);
    }

    public function testGuestSessionPreorderResolvesAsPreordered(): void
    {
        $this->variant(0);
        $guest = $this->subscription('guest@example.com', '-1 day');
        $this->subscription('other@example.com', '-2 days');

        self::assertSame(PreorderState::CanPreorder, $this->resolve()->state);

        (new GuestPreorderSession($this->requestStack))->remember((int) $guest->getId());
        $view = $this->resolve();

        self::assertSame(PreorderState::Preordered, $view->state);
        self::assertSame($guest, $view->subscription);
        self::assertSame(2, $view->position);
    }

    public function testPositionCountsOnlyEarlierWaiting(): void
    {
        $variant = $this->variant(0);
        $this->subscription('first@example.com', '-5 days');
        $notified = $this->subscription('notified@example.com', '-4 days');
        $notified->markNotified(new \DateTimeImmutable('-1 hour'), null, null);
        $cancelled = $this->subscription('cancelled@example.com', '-3 days');
        $cancelled->markCancelled(new \DateTimeImmutable('-1 hour'));
        $otherVariantTarget = $this->subscription('variant@example.com', '-3 days');
        $otherVariantTarget->setProductVariant($variant);
        $this->customer = $this->customer('anna@example.com');
        $anna = $this->subscription('anna@example.com', '-2 days', $this->customer);
        $this->subscription('later@example.com', '-1 day');

        self::assertSame(2, $this->resolve()->position);

        // Notified pre-orders have no queue position.
        $anna->markNotified(new \DateTimeImmutable('-1 hour'), null, null);
        $view = $this->resolve();
        self::assertSame(PreorderState::Preordered, $view->state);
        self::assertNull($view->position);
    }

    private function resolve(): PreorderView
    {
        $repository = $this->repository();
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->method('getCustomer')->willReturnCallback(fn (): ?CustomerInterface => $this->customer);
        $preorderability = $this->createMock(PreorderabilityResolverInterface::class);
        $preorderability->method('isPreorderable')->willReturnCallback(fn (): bool => $this->preorderable);
        $targetResolver = new TargetResolver('product', $repository);

        $resolver = new PreorderStateResolver(
            $repository,
            $targetResolver,
            new SellableUnits(new AvailabilityChecker()),
            new HoldVisitor($customerContext, new ClaimSession($this->requestStack), $repository, $targetResolver),
            new GuestPreorderSession($this->requestStack),
            $preorderability,
        );

        return $resolver->resolve($this->product, null, $this->channel);
    }

    private function repository(): SubscriptionRepositoryInterface&MockObject
    {
        $repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $repository->method('countActiveHoldsByVariant')->willReturnCallback(function (array $variants, \DateTimeImmutable $now): array {
            $counts = [];
            foreach ($this->subscriptions as $subscription) {
                $held = $subscription->getHeldVariant();
                if ($subscription->isActive() && null !== $held && $subscription->getHoldUntil() > $now && in_array($held, $variants, true)) {
                    $counts[(int) $held->getId()] = ($counts[(int) $held->getId()] ?? 0) + 1;
                }
            }

            return $counts;
        });
        $repository->method('findActiveByVisitor')->willReturnCallback(function (?CustomerInterface $customer, ?string $emailCanonical, array $ids): array {
            return array_values(array_filter($this->subscriptions, static fn (SubscriptionInterface $s): bool => $s->isActive() && (
                (null !== $customer && $s->getCustomer() === $customer) ||
                (null !== $emailCanonical && null === $s->getCustomer() && $s->getEmailCanonical() === $emailCanonical) ||
                in_array($s->getId(), $ids, true)
            )));
        });
        $repository->method('findActiveByTarget')->willReturnCallback(
            fn (ProductInterface $product, mixed $variant, ChannelInterface $channel): array => array_values(array_filter(
                $this->subscriptions,
                static fn (SubscriptionInterface $s): bool => $s->isActive() && $s->getProduct() === $product && $s->getProductVariant() === $variant && $s->getChannel() === $channel,
            )),
        );
        // The repository's own rule, over the same rows its query would load.
        $repository->method('queuePosition')->willReturnCallback(
            fn (SubscriptionInterface $subscription): ?int => QueuePosition::among($subscription, $this->subscriptions),
        );

        return $repository;
    }

    private function variant(int $onHand): ProductVariant
    {
        $variant = new ProductVariant();
        (new \ReflectionProperty(ProductVariant::class, 'id'))->setValue($variant, 100 + count($this->product->getVariants()));
        $variant->setTracked(true);
        $variant->setOnHand($onHand);
        $pricing = new ChannelPricing();
        $pricing->setChannelCode('WEB');
        $pricing->setPrice(1000);
        $variant->addChannelPricing($pricing);
        $this->product->addVariant($variant);

        return $variant;
    }

    private function customer(string $email): Customer
    {
        $customer = new Customer();
        $customer->setEmail($email);
        $customer->setEmailCanonical($email);

        return $customer;
    }

    private function subscription(string $email, string $createdAgo, ?CustomerInterface $customer = null): Subscription
    {
        $subscription = new Subscription();
        (new \ReflectionProperty(Subscription::class, 'id'))->setValue($subscription, 1 + count($this->subscriptions));
        $subscription->setProduct($this->product);
        $subscription->setChannel($this->channel);
        $subscription->setEmail($email);
        $subscription->setEmailCanonical($email);
        $subscription->setCustomer($customer);
        $subscription->setCreatedAt(new \DateTimeImmutable($createdAgo));
        $this->subscriptions[] = $subscription;

        return $subscription;
    }
}
