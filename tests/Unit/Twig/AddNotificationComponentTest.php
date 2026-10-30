<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Twig;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
use Sylius\Component\Inventory\Checker\AvailabilityChecker;
use Sylius\Component\Product\Resolver\ProductVariantResolverInterface;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Validator\Validation;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\Subscription;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionStatus;
use Webgriffe\SyliusBackInStockNotificationPlugin\Hold\ClaimSession;
use Webgriffe\SyliusBackInStockNotificationPlugin\Hold\HoldVisitor;
use Webgriffe\SyliusBackInStockNotificationPlugin\Preorderability\PreorderabilityResolverInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Processor\ProcessResult;
use Webgriffe\SyliusBackInStockNotificationPlugin\Processor\SubscriptionProcessorInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Restock\SellableUnits;
use Webgriffe\SyliusBackInStockNotificationPlugin\Session\CancelUndoSession;
use Webgriffe\SyliusBackInStockNotificationPlugin\Session\GuestPreorderSession;
use Webgriffe\SyliusBackInStockNotificationPlugin\State\PreorderState;
use Webgriffe\SyliusBackInStockNotificationPlugin\State\PreorderStateResolver;
use Webgriffe\SyliusBackInStockNotificationPlugin\Target\TargetResolver;
use Webgriffe\SyliusBackInStockNotificationPlugin\Twig\Component\AddNotificationComponent;

final class AddNotificationComponentTest extends TestCase
{
    private SubscriptionProcessorInterface&MockObject $processor;

    private SubscriptionRepositoryInterface&MockObject $repository;

    private ?CustomerInterface $customer = null;

    /** @var list<SubscriptionInterface> */
    private array $subscriptions = [];

    private RequestStack $requestStack;

    private Product $product;

    private Channel $channel;

    protected function setUp(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack = new RequestStack();
        $this->requestStack->push($request);
        $this->channel = new Channel();
        $this->channel->setCode('WEB');
        $this->product = new Product();
        $this->product->setCode('BOOK');
        $this->processor = $this->createMock(SubscriptionProcessorInterface::class);
        $this->repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $this->repository->method('findActiveByVisitor')->willReturnCallback(
            fn (): array => array_values(array_filter($this->subscriptions, static fn (SubscriptionInterface $s): bool => $s->isActive())),
        );
        $this->repository->method('countActiveHoldsByVariant')->willReturn([]);
    }

    public function testGuestAddCallsProcessorWithNameAndEmail(): void
    {
        $this->processor->expects(self::once())->method('process')
            ->with($this->product, null, 'anna@example.com', 'Anna', null, '203.0.113.9')
            ->willReturn(ProcessResult::Created)
        ;
        $component = $this->component();
        $component->formValues = ['name' => 'Anna', 'email' => 'anna@example.com', 'website' => ''];

        $component->addNotification();

        self::assertTrue($component->notificationSent);
        self::assertNull($component->rateLimitedMinutes);
    }

    public function testLoggedInCustomerUsesTheirOwnAddress(): void
    {
        $this->customer = $this->customer('anna@example.com');
        $this->processor->expects(self::once())->method('process')
            ->with($this->product, null, 'anna@example.com', null, $this->customer, '203.0.113.9')
            ->willReturn(ProcessResult::Created)
        ;
        $component = $this->component();
        $component->formValues = ['website' => ''];

        $component->addNotification();

        self::assertTrue($component->notificationSent);
    }

    public function testHoneypotFakesSuccess(): void
    {
        $this->processor->expects(self::never())->method('process');
        $component = $this->component();
        $component->formValues = ['name' => 'Bot', 'email' => 'bot@example.com', 'website' => 'http://spam.example'];

        $component->addNotification();

        self::assertTrue($component->notificationSent);
        self::assertNull($component->rateLimitedMinutes);
    }

    public function testRateLimitedShowsMessageAndKeepsFormData(): void
    {
        $this->processor->method('process')->willReturn(ProcessResult::RateLimited);
        $component = $this->component();
        $component->formValues = ['name' => 'Anna', 'email' => 'anna@example.com', 'website' => ''];

        $component->addNotification();

        self::assertFalse($component->notificationSent);
        self::assertSame(2, $component->rateLimitedMinutes);
        self::assertSame('Anna', $component->formValues['name']);
        self::assertSame('anna@example.com', $component->formValues['email']);
    }

    public function testCancelMarksCancelledAndRemembersForUndo(): void
    {
        $this->customer = $this->customer('anna@example.com');
        $subscription = $this->subscription($this->customer);
        $this->repository->expects(self::once())->method('add')->with($subscription);
        $component = $this->component();

        self::assertTrue($component->canCancel());
        $component->cancel();

        self::assertSame(SubscriptionStatus::CANCELLED, $subscription->getStatus());
        self::assertTrue($component->cancelled);
        self::assertSame([7], (new CancelUndoSession($this->requestStack))->peek(new \DateTimeImmutable()));
    }

    public function testGuestCannotCancel(): void
    {
        $subscription = $this->subscription(null);
        $this->requestStack->getCurrentRequest()?->getSession()->set(GuestPreorderSession::KEY, [7]);
        $this->repository->expects(self::never())->method('add');
        $component = $this->component();

        self::assertSame(PreorderState::Preordered, $component->getView()->state);
        self::assertFalse($component->canCancel());
        $component->cancel();

        self::assertSame(SubscriptionStatus::WAITING, $subscription->getStatus());
        self::assertFalse($component->cancelled);
    }

    public function testRestoreWithinTenMinutesPutsItBackToWaiting(): void
    {
        $this->customer = $this->customer('anna@example.com');
        $subscription = $this->subscription($this->customer);
        $subscription->markCancelled(new \DateTimeImmutable());
        (new CancelUndoSession($this->requestStack))->remember([7], new \DateTimeImmutable());
        $this->repository->method('findCancelledByIds')->with([7], 'anna@example.com', $this->channel)->willReturn([$subscription]);
        $this->repository->expects(self::once())->method('add')->with($subscription);
        $component = $this->component();
        $component->cancelled = true;

        $component->restore();

        self::assertSame(SubscriptionStatus::WAITING, $subscription->getStatus());
        self::assertFalse($component->cancelled);
    }

    public function testRestoreAfterTenMinutesDoesNothing(): void
    {
        $this->customer = $this->customer('anna@example.com');
        $subscription = $this->subscription($this->customer);
        $subscription->markCancelled(new \DateTimeImmutable('-20 minutes'));
        (new CancelUndoSession($this->requestStack))->remember([7], new \DateTimeImmutable('-11 minutes'));
        $this->repository->method('findCancelledByIds')->with([], 'anna@example.com', $this->channel)->willReturn([]);
        $this->repository->expects(self::never())->method('add');

        $this->component()->restore();

        self::assertSame(SubscriptionStatus::CANCELLED, $subscription->getStatus());
    }

    private function component(): AddNotificationComponent
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->method('getCustomer')->willReturnCallback(fn (): ?CustomerInterface => $this->customer);
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($this->channel);
        $preorderability = $this->createMock(PreorderabilityResolverInterface::class);
        $preorderability->method('isPreorderable')->willReturn(true);
        $targetResolver = new TargetResolver('product', $this->repository);
        $stateResolver = new PreorderStateResolver(
            $this->repository,
            $targetResolver,
            new SellableUnits(new AvailabilityChecker()),
            new HoldVisitor($customerContext, new ClaimSession($this->requestStack), $this->repository, $targetResolver),
            new GuestPreorderSession($this->requestStack),
            $preorderability,
        );

        $component = new AddNotificationComponent(
            Forms::createFormFactoryBuilder()->addExtension(new ValidatorExtension(Validation::createValidator()))->getFormFactory(),
            $this->createMock(ProductVariantResolverInterface::class),
            $customerContext,
            $this->processor,
            $this->createMock(ProductRepositoryInterface::class),
            $this->createMock(ProductVariantRepositoryInterface::class),
            $stateResolver,
            $preorderability,
            $this->repository,
            new CancelUndoSession($this->requestStack),
            $channelContext,
            $this->requestStack,
            new NullLogger(),
        );
        $component->product = $this->product;

        return $component;
    }

    private function customer(string $email): Customer
    {
        $customer = new Customer();
        $customer->setEmail($email);
        $customer->setEmailCanonical($email);

        return $customer;
    }

    private function subscription(?CustomerInterface $customer): Subscription
    {
        $subscription = new Subscription();
        (new \ReflectionProperty(Subscription::class, 'id'))->setValue($subscription, 7);
        $subscription->setProduct($this->product);
        $subscription->setChannel($this->channel);
        $subscription->setEmail('anna@example.com');
        $subscription->setEmailCanonical('anna@example.com');
        $subscription->setCustomer($customer);
        $subscription->setCreatedAt(new \DateTimeImmutable('-1 day'));
        $this->subscriptions[] = $subscription;

        return $subscription;
    }
}
