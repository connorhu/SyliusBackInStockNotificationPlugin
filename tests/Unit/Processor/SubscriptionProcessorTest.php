<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Processor;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Sylius\Component\User\Canonicalizer\Canonicalizer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Customer\AccountCustomerFinder;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\Subscription;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Factory\SubscriptionFactory;
use Webgriffe\SyliusBackInStockNotificationPlugin\Mailer\SafeSender;
use Webgriffe\SyliusBackInStockNotificationPlugin\Manage\ManageLinkGenerator;
use Webgriffe\SyliusBackInStockNotificationPlugin\Preorderability\PreorderabilityResolverInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Processor\ProcessResult;
use Webgriffe\SyliusBackInStockNotificationPlugin\Processor\SubscriptionProcessor;
use Webgriffe\SyliusBackInStockNotificationPlugin\RateLimit\PreorderRateLimiter;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Session\GuestPreorderSession;
use Webgriffe\SyliusBackInStockNotificationPlugin\Target\TargetResolverInterface;

final class SubscriptionProcessorTest extends TestCase
{
    private SubscriptionRepositoryInterface&MockObject $repository;

    private SenderInterface&MockObject $sender;

    private TargetResolverInterface&MockObject $targetResolver;

    private PreorderabilityResolverInterface&MockObject $preorderability;

    private \Sylius\Component\Core\Repository\CustomerRepositoryInterface&MockObject $customerRepository;

    private GuestPreorderSession $guestSession;

    private ChannelInterface&MockObject $channel;

    private ProductInterface&MockObject $product;

    private RequestContext $routerContext;

    private PreorderRateLimiter $rateLimiter;

    private SubscriptionProcessor $processor;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $this->sender = $this->createMock(SenderInterface::class);
        $this->targetResolver = $this->createMock(TargetResolverInterface::class);
        $this->targetResolver->method('target')->willReturn('product');
        $this->preorderability = $this->createMock(PreorderabilityResolverInterface::class);
        $this->preorderability->method('isPreorderable')->willReturn(true);
        $this->customerRepository = $this->createMock(\Sylius\Component\Core\Repository\CustomerRepositoryInterface::class);

        $this->channel = $this->createMock(ChannelInterface::class);
        $this->channel->method('getHostname')->willReturn('shop.example');
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($this->channel);
        $localeContext = $this->createMock(LocaleContextInterface::class);
        $localeContext->method('getLocaleCode')->willReturn('hu_HU');

        $this->product = $this->createMock(ProductInterface::class);

        $this->routerContext = new RequestContext('', 'GET', 'original.host');
        $router = $this->createMock(RouterInterface::class);
        $router->method('getContext')->willReturn($this->routerContext);
        $router->method('generate')->willReturnCallback(
            fn (string $name): string => 'https://' . $this->routerContext->getHost() . '/' . $name,
        );

        $requestStack = new RequestStack();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);
        $this->guestSession = new GuestPreorderSession($requestStack);

        $canonicalizer = new Canonicalizer();
        $this->rateLimiter = new PreorderRateLimiter($this->repository, new RateLimiterFactory(
            ['id' => 'test_ip', 'policy' => 'sliding_window', 'limit' => 10, 'interval' => '1 hour'],
            new InMemoryStorage(),
        ));
        $this->processor = new SubscriptionProcessor(
            new SubscriptionFactory(Subscription::class, $canonicalizer),
            $this->repository,
            $channelContext,
            $localeContext,
            new SafeSender($this->sender, new NullLogger()),
            $this->targetResolver,
            $this->preorderability,
            new AccountCustomerFinder($this->customerRepository),
            $this->guestSession,
            $router,
            new ManageLinkGenerator($router, new UriSigner('secret')),
            $canonicalizer,
            new NullLogger(),
            $this->rateLimiter,
        );
    }

    public function testGuestEmailWithAccountIsAttachedToCustomer(): void
    {
        $customer = $this->accountCustomer('anna@pelda.hu');
        $this->customerRepository->method('findOneBy')->with(['emailCanonical' => 'anna@pelda.hu'])->willReturn($customer);

        $created = $this->captureAdded();
        $result = $this->processor->process($this->product, null, 'Anna@Pelda.hu', 'Anna', null);

        self::assertSame(ProcessResult::Created, $result);
        self::assertSame($customer, $this->only($created)->getCustomer());
    }

    public function testGuestEmailWithoutAccountStaysGuest(): void
    {
        $this->customerRepository->method('findOneBy')->willReturn(null);

        $created = $this->captureAdded();
        $this->processor->process($this->product, null, 'anna@pelda.hu', 'Anna', null);

        self::assertNull($this->only($created)->getCustomer());
    }

    public function testCustomerWithoutShopUserIsNotAttached(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getUser')->willReturn(null);
        $this->customerRepository->method('findOneBy')->willReturn($customer);

        $created = $this->captureAdded();
        $this->processor->process($this->product, null, 'anna@pelda.hu', 'Anna', null);

        self::assertNull($this->only($created)->getCustomer());
    }

    public function testLoggedInCustomerEmailWins(): void
    {
        $customer = $this->accountCustomer('real@pelda.hu');

        $created = $this->captureAdded();
        $this->processor->process($this->product, null, 'typed@other.hu', null, $customer);

        self::assertSame('real@pelda.hu', $this->only($created)->getEmail());
        self::assertSame($customer, $this->only($created)->getCustomer());
    }

    public function testDuplicateWithDifferentCaseIsAlreadySubscribed(): void
    {
        $this->customerRepository->method('findOneBy')->willReturn(null);
        $this->targetResolver->expects(self::once())->method('findActive')
            ->with('anna@pelda.hu', $this->product, null, $this->channel)
            ->willReturn(new Subscription());
        $this->repository->expects(self::never())->method('add');
        $this->sender->expects(self::never())->method('send');

        $result = $this->processor->process($this->product, null, ' ANNA@Pelda.hu ', 'Anna', null);

        self::assertSame(ProcessResult::AlreadySubscribed, $result);
    }

    public function testRateLimitedStoresNothing(): void
    {
        $this->customerRepository->method('findOneBy')->willReturn(null);
        $recent = new Subscription();
        $recent->setCreatedAt(new \DateTimeImmutable('-30 seconds'));
        $this->repository->method('findNewestByEmailOrCustomer')->willReturn($recent);
        $this->repository->expects(self::never())->method('add');
        $this->sender->expects(self::never())->method('send');

        $result = $this->processor->process($this->product, null, 'anna@pelda.hu', 'Anna', null, '1.2.3.4');

        self::assertSame(ProcessResult::RateLimited, $result);
        self::assertSame([], $this->guestSession->ids());
    }

    public function testDuplicateIsAnsweredBeforeTheRateLimit(): void
    {
        $this->customerRepository->method('findOneBy')->willReturn(null);
        $this->targetResolver->method('findActive')->willReturn(new Subscription());
        $this->repository->expects(self::never())->method('findNewestByEmailOrCustomer');

        $result = $this->processor->process($this->product, null, 'anna@pelda.hu', 'Anna', null, '1.2.3.4');

        self::assertSame(ProcessResult::AlreadySubscribed, $result);
    }

    public function testNotPreorderableProductIsRefused(): void
    {
        $preorderability = $this->createMock(PreorderabilityResolverInterface::class);
        $preorderability->method('isPreorderable')->willReturn(false);
        $this->preorderability = $preorderability;
        $this->rebuildWith($preorderability);

        $this->repository->expects(self::never())->method('add');
        $this->sender->expects(self::never())->method('send');

        $result = $this->processor->process($this->product, null, 'anna@pelda.hu', 'Anna', null);

        self::assertSame(ProcessResult::NotPreorderable, $result);
    }

    public function testConfirmationMailSentOnCreate(): void
    {
        $this->customerRepository->method('findOneBy')->willReturn(null);
        $this->repository->method('add');
        $this->sender->expects(self::once())->method('send')->with(
            'webgriffe_back_in_stock_notification_success_subscription',
            ['anna@pelda.hu'],
            self::callback(function (array $data): bool {
                return $data['subscription'] instanceof SubscriptionInterface &&
                    $data['product'] === $this->product &&
                    $data['channel'] === $this->channel &&
                    'hu_HU' === $data['localeCode'] &&
                    is_string($data['manageUrl']) &&
                    str_starts_with($data['manageUrl'], 'https://shop.example/webgriffe_back_in_stock_notification_manage?_hash=') &&
                    null === $data['accountUrl'] &&
                    'queue' === $data['mode'] &&
                    24 === $data['holdHours'];
            }),
        );

        $this->processor->process($this->product, null, 'anna@pelda.hu', 'Anna', null);
    }

    public function testAccountPreorderMailLinksAccountAndRestoresRouterHost(): void
    {
        $customer = $this->accountCustomer('anna@pelda.hu');
        $this->sender->expects(self::once())->method('send')->with(
            self::anything(),
            self::anything(),
            self::callback(static fn (array $data): bool => 'https://shop.example/webgriffe_back_in_stock_notification_account_list_subscriptions' === $data['accountUrl'] &&
                null === $data['manageUrl']),
        );

        $this->processor->process($this->product, null, 'anna@pelda.hu', null, $customer);

        self::assertSame('original.host', $this->routerContext->getHost());
    }

    public function testRoutingFailureKeepsThePreorderAndSkipsTheMail(): void
    {
        $customer = $this->accountCustomer('anna@pelda.hu');
        $router = $this->createMock(RouterInterface::class);
        $router->method('getContext')->willReturn($this->routerContext);
        $router->method('generate')->willThrowException(new RouteNotFoundException('no route'));
        $canonicalizer = new Canonicalizer();
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($this->channel);
        $localeContext = $this->createMock(LocaleContextInterface::class);
        $localeContext->method('getLocaleCode')->willReturn('hu_HU');
        $processor = new SubscriptionProcessor(
            new SubscriptionFactory(Subscription::class, $canonicalizer),
            $this->repository,
            $channelContext,
            $localeContext,
            new SafeSender($this->sender, new NullLogger()),
            $this->targetResolver,
            $this->preorderability,
            new AccountCustomerFinder($this->customerRepository),
            $this->guestSession,
            $router,
            new ManageLinkGenerator($router, new UriSigner('secret')),
            $canonicalizer,
            new NullLogger(),
            $this->rateLimiter,
        );
        $this->repository->expects(self::once())->method('add');
        $this->sender->expects(self::never())->method('send');

        $result = $processor->process($this->product, null, 'anna@pelda.hu', null, $customer);

        self::assertSame(ProcessResult::Created, $result);
        self::assertSame('original.host', $this->routerContext->getHost());
    }

    public function testGuestCreateIsRememberedInSession(): void
    {
        $this->customerRepository->method('findOneBy')->willReturn(null);
        $this->repository->method('add')->willReturnCallback(static function (SubscriptionInterface $s): void {
            (new \ReflectionProperty(Subscription::class, 'id'))->setValue($s, 42);
        });

        $this->processor->process($this->product, null, 'anna@pelda.hu', 'Anna', null);

        self::assertSame([42], $this->guestSession->ids());
    }

    public function testLoggedInCreateIsNotRemembered(): void
    {
        $customer = $this->accountCustomer('anna@pelda.hu');
        $this->repository->method('add')->willReturnCallback(static function (SubscriptionInterface $s): void {
            (new \ReflectionProperty(Subscription::class, 'id'))->setValue($s, 42);
        });

        $this->processor->process($this->product, null, 'anna@pelda.hu', null, $customer);

        self::assertSame([], $this->guestSession->ids());
    }

    public function testVariantTargetUsesTheVariant(): void
    {
        $targetResolver = $this->createMock(TargetResolverInterface::class);
        $targetResolver->method('target')->willReturn('variant');
        $variant = $this->createMock(ProductVariantInterface::class);
        $this->customerRepository->method('findOneBy')->willReturn(null);
        $targetResolver->expects(self::once())->method('findActive')->with('anna@pelda.hu', $this->product, $variant, $this->channel)->willReturn(null);
        $this->rebuildWith($this->preorderability, $targetResolver);

        $created = $this->captureAdded();
        $this->processor->process($this->product, $variant, 'anna@pelda.hu', 'Anna', null);

        self::assertSame($variant, $this->only($created)->getProductVariant());
    }

    /**
     * @return \ArrayObject<int, SubscriptionInterface>
     */
    private function captureAdded(): \ArrayObject
    {
        /** @var \ArrayObject<int, SubscriptionInterface> $created */
        $created = new \ArrayObject();
        $this->repository->method('add')->willReturnCallback(static function (SubscriptionInterface $s) use ($created): void {
            $created[] = $s;
        });

        return $created;
    }

    /**
     * @param \ArrayObject<int, SubscriptionInterface> $created
     */
    private function only(\ArrayObject $created): SubscriptionInterface
    {
        $subscription = $created[0] ?? null;
        self::assertInstanceOf(SubscriptionInterface::class, $subscription);

        return $subscription;
    }

    private function accountCustomer(string $email): CustomerInterface&MockObject
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn($email);
        $customer->method('getUser')->willReturn($this->createMock(\Sylius\Component\Core\Model\ShopUserInterface::class));

        return $customer;
    }

    private function rebuildWith(PreorderabilityResolverInterface $preorderability, ?TargetResolverInterface $targetResolver = null): void
    {
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($this->channel);
        $localeContext = $this->createMock(LocaleContextInterface::class);
        $localeContext->method('getLocaleCode')->willReturn('hu_HU');
        $router = $this->createMock(RouterInterface::class);
        $router->method('getContext')->willReturn($this->routerContext);
        $router->method('generate')->willReturn('https://x/y');
        $canonicalizer = new Canonicalizer();

        $this->processor = new SubscriptionProcessor(
            new SubscriptionFactory(Subscription::class, $canonicalizer),
            $this->repository,
            $channelContext,
            $localeContext,
            new SafeSender($this->sender, new NullLogger()),
            $targetResolver ?? $this->targetResolver,
            $preorderability,
            new AccountCustomerFinder($this->customerRepository),
            $this->guestSession,
            $router,
            new ManageLinkGenerator($router, new UriSigner('secret')),
            $canonicalizer,
            new NullLogger(),
            $this->rateLimiter,
        );
    }
}
