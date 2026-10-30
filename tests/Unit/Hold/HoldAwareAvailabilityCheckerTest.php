<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Hold;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\CoreBundle\SectionResolver\SectionInterface;
use Sylius\Bundle\CoreBundle\SectionResolver\SectionProviderInterface;
use Sylius\Bundle\ShopBundle\SectionResolver\ShopSection;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Product;
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
use Webgriffe\SyliusBackInStockNotificationPlugin\Hold\HoldAwareAvailabilityChecker;
use Webgriffe\SyliusBackInStockNotificationPlugin\Hold\HoldVisitor;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Target\TargetResolver;

final class HoldAwareAvailabilityCheckerTest extends TestCase
{
    private ?SectionInterface $section;

    private ?CustomerInterface $customer = null;

    /** @var list<SubscriptionInterface> */
    private array $subscriptions = [];

    private int $holdCountQueries = 0;

    private RequestStack $requestStack;

    private Channel $channel;

    private Product $product;

    protected function setUp(): void
    {
        $this->section = new ShopSection();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack = new RequestStack();
        $this->requestStack->push($request);
        $this->channel = new Channel();
        $this->channel->setCode('WEB');
        $this->product = new Product();
        $this->product->setCode('BOOK');
    }

    public function testOutsideShopSectionDelegates(): void
    {
        $variant = $this->variant(1);
        $this->hold($this->subscription('anna@example.com'), $variant);

        foreach ([null, $this->createMock(SectionInterface::class)] as $section) {
            $this->section = $section;
            $checker = $this->checker();

            self::assertTrue($checker->isStockAvailable($variant));
            self::assertTrue($checker->isStockSufficient($variant, 1));
        }
        self::assertSame(0, $this->holdCountQueries);
    }

    public function testStrangerSeesHeldUnitsAsUnavailable(): void
    {
        $variant = $this->variant(1);
        $this->hold($this->subscription('anna@example.com'), $variant);
        $this->customer = $this->customer('stranger@example.com');

        $checker = $this->checker();

        self::assertFalse($checker->isStockAvailable($variant));
        self::assertFalse($checker->isStockSufficient($variant, 1));
    }

    public function testHolderMayBuyHeldUnit(): void
    {
        $variant = $this->variant(1);
        $this->customer = $this->customer('anna@example.com');
        $this->hold($this->subscription('anna@example.com', $this->customer), $variant);

        self::assertTrue($this->checker()->isStockAvailable($variant));
    }

    public function testHolderIsRecognisedByCanonicalEmail(): void
    {
        $variant = $this->variant(1);
        $this->hold($this->subscription('anna@example.com'), $variant);
        $this->customer = $this->customer('anna@example.com');

        self::assertTrue($this->checker()->isStockAvailable($variant));
    }

    public function testEarlierNotifiedMayBuyHeldUnit(): void
    {
        $variant = $this->variant(1);
        $this->hold($this->subscription('bela@example.com'), $variant);
        $this->customer = $this->customer('anna@example.com');
        // Anna's hold lapsed: she stays notified and may still buy any unit of the target.
        $anna = $this->subscription('anna@example.com', $this->customer);
        $anna->markNotified(new \DateTimeImmutable('-2 days'), null, null);

        self::assertTrue($this->checker()->isStockAvailable($variant));
    }

    public function testWaitingPreorderDoesNotUnlockHeldUnits(): void
    {
        $variant = $this->variant(1);
        $this->hold($this->subscription('bela@example.com'), $variant);
        $this->customer = $this->customer('anna@example.com');
        $this->subscription('anna@example.com', $this->customer);

        self::assertFalse($this->checker()->isStockAvailable($variant));
    }

    public function testClaimedGuestMayBuyHeldUnit(): void
    {
        $variant = $this->variant(1);
        $holder = $this->subscription('anna@example.com');
        $this->hold($holder, $variant);
        $checker = $this->checker();

        self::assertFalse($checker->isStockAvailable($variant));

        (new ClaimSession($this->requestStack))->claim(7);
        $checker->reset();

        self::assertTrue($checker->isStockAvailable($variant));
    }

    public function testQuantityCheckSubtractsOnlyForeignHolds(): void
    {
        $variant = $this->variant(4);
        $variant->setOnHold(1);
        $this->hold($this->subscription('anna@example.com'), $variant);

        $stranger = $this->checker();
        self::assertTrue($stranger->isStockSufficient($variant, 2));
        self::assertFalse($stranger->isStockSufficient($variant, 3));

        $this->customer = $this->customer('anna@example.com');
        $holder = $this->checker();
        self::assertTrue($holder->isStockSufficient($variant, 3));
        self::assertFalse($holder->isStockSufficient($variant, 4));
    }

    public function testUntrackedVariantDelegates(): void
    {
        $variant = $this->variant(0);
        $variant->setTracked(false);
        $this->hold($this->subscription('anna@example.com'), $variant);

        self::assertTrue($this->checker()->isStockSufficient($variant, 5));
        self::assertSame(0, $this->holdCountQueries);
    }

    public function testHoldCountsAreMemoisedUntilReset(): void
    {
        $variant = $this->variant(2);
        $this->hold($this->subscription('anna@example.com'), $variant);
        $checker = $this->checker();

        $checker->isStockAvailable($variant);
        $checker->isStockSufficient($variant, 1);
        self::assertSame(1, $this->holdCountQueries);

        $checker->reset();
        $checker->isStockAvailable($variant);
        self::assertSame(2, $this->holdCountQueries);
    }

    private function checker(): HoldAwareAvailabilityChecker
    {
        $repository = $this->repository();
        $sectionProvider = $this->createMock(SectionProviderInterface::class);
        $sectionProvider->method('getSection')->willReturnCallback(fn (): ?SectionInterface => $this->section);
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->method('getCustomer')->willReturnCallback(fn (): ?CustomerInterface => $this->customer);

        return new HoldAwareAvailabilityChecker(
            new AvailabilityChecker(),
            $sectionProvider,
            $repository,
            new HoldVisitor($customerContext, new ClaimSession($this->requestStack), $repository, new TargetResolver('product', $repository)),
        );
    }

    private function repository(): SubscriptionRepositoryInterface&MockObject
    {
        $repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $repository->method('countActiveHoldsByVariant')->willReturnCallback(function (array $variants, \DateTimeImmutable $now): array {
            ++$this->holdCountQueries;
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
            return array_values(array_filter($this->subscriptions, static function (SubscriptionInterface $subscription) use ($customer, $emailCanonical, $ids): bool {
                if (!$subscription->isActive()) {
                    return false;
                }

                return (null !== $customer && $subscription->getCustomer() === $customer) ||
                    (null !== $emailCanonical && null === $subscription->getCustomer() && $subscription->getEmailCanonical() === $emailCanonical) ||
                    in_array($subscription->getId(), $ids, true);
            }));
        });

        return $repository;
    }

    private function variant(int $onHand): ProductVariant
    {
        $variant = new ProductVariant();
        (new \ReflectionProperty(ProductVariant::class, 'id'))->setValue($variant, 100 + count($this->product->getVariants()));
        $variant->setTracked(true);
        $variant->setOnHand($onHand);
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

    private function subscription(string $email, ?CustomerInterface $customer = null): Subscription
    {
        $subscription = new Subscription();
        (new \ReflectionProperty(Subscription::class, 'id'))->setValue($subscription, 7 + count($this->subscriptions));
        $subscription->setProduct($this->product);
        $subscription->setChannel($this->channel);
        $subscription->setEmail($email);
        $subscription->setEmailCanonical($email);
        $subscription->setCustomer($customer);
        $subscription->setCreatedAt(new \DateTimeImmutable('-3 days'));
        $this->subscriptions[] = $subscription;

        return $subscription;
    }

    private function hold(Subscription $subscription, ProductVariant $variant): void
    {
        $subscription->markNotified(new \DateTimeImmutable('-1 hour'), $variant, new \DateTimeImmutable('+1 day'));
    }
}
