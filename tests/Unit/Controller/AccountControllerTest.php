<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Controller;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Webgriffe\SyliusBackInStockNotificationPlugin\Account\AccountRow;
use Webgriffe\SyliusBackInStockNotificationPlugin\Controller\AccountController;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\Subscription;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionStatus;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Session\CancelUndoSession;

final class AccountControllerTest extends TestCase
{
    private SubscriptionRepositoryInterface&MockObject $repository;

    private CancelUndoSession $undo;

    private RequestStack $requestStack;

    private ?CustomerInterface $customer;

    private bool $csrfValid = true;

    private AccountController $controller;

    protected function setUp(): void
    {
        $this->requestStack = new RequestStack();
        $this->requestStack->push($this->post());
        $this->undo = new CancelUndoSession($this->requestStack);
        $this->repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $this->customer = $this->customer(3, 'anna@example.com');

        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->method('getCustomer')->willReturnCallback(fn (): ?CustomerInterface => $this->currentCustomer());
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn(new Channel());
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(fn (): bool => $this->csrfValid);
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/hu/account/back-in-stock-notification');

        $this->controller = new AccountController(
            $this->createMock(Environment::class),
            $router,
            $csrf,
            $this->repository,
            $this->undo,
            $customerContext,
            $channelContext,
        );
    }

    public function testCancelMarksOwnSubscriptionAndRemembersIt(): void
    {
        $subscription = $this->subscription(5, $this->customer);
        $this->repository->method('find')->with(5)->willReturn($subscription);
        $this->repository->expects(self::once())->method('add')->with($subscription);

        $response = $this->controller->cancelAction($this->post(), 5);

        self::assertSame(SubscriptionStatus::CANCELLED, $subscription->getStatus());
        self::assertSame(302, $response->getStatusCode());
        self::assertSame([5], $this->undo->peek(new \DateTimeImmutable()));
    }

    public function testCancelWorksForAGuestRowWithMyCanonicalEmail(): void
    {
        $subscription = $this->subscription(5, null);
        $this->repository->method('find')->willReturn($subscription);
        $this->repository->expects(self::once())->method('add');

        $this->controller->cancelAction($this->post(), 5);

        self::assertSame(SubscriptionStatus::CANCELLED, $subscription->getStatus());
    }

    public function testCancelOfSomeoneElsesRowIs404(): void
    {
        $subscription = $this->subscription(5, $this->customer(9, 'other@example.com'));
        $this->repository->method('find')->willReturn($subscription);
        $this->repository->expects(self::never())->method('add');

        $this->expectException(NotFoundHttpException::class);
        $this->controller->cancelAction($this->post(), 5);
    }

    public function testInvalidCsrfIsRefused(): void
    {
        $this->csrfValid = false;
        $this->repository->expects(self::never())->method('add');

        $this->expectException(AccessDeniedHttpException::class);
        $this->controller->cancelAction($this->post(), 5);
    }

    public function testGuestVisitorIsRefused(): void
    {
        $this->customer = null;

        $this->expectException(AccessDeniedHttpException::class);
        $this->controller->dismissAction($this->post(), 5);
    }

    public function testDismissOnlyHidesFulfilledRows(): void
    {
        $waiting = $this->subscription(5, $this->customer);
        $fulfilled = $this->subscription(6, $this->customer);
        $fulfilled->markFulfilled(new \DateTimeImmutable());
        $this->repository->method('find')->willReturnCallback(static fn (int $id): ?Subscription => [5 => $waiting, 6 => $fulfilled][$id] ?? null);
        $this->repository->expects(self::once())->method('add')->with($fulfilled);

        $this->controller->dismissAction($this->post(), 5);
        $this->controller->dismissAction($this->post(), 6);

        self::assertNull($waiting->getDismissedAt());
        self::assertNotNull($fulfilled->getDismissedAt());
    }

    public function testRestoreBringsBackOnlyRememberedOwnRows(): void
    {
        $subscription = $this->subscription(5, $this->customer);
        $subscription->markCancelled(new \DateTimeImmutable());
        $this->undo->remember([5], new \DateTimeImmutable());
        $this->repository->method('findCancelledByIds')->with([5], 'anna@example.com')->willReturn([$subscription]);
        $this->repository->expects(self::once())->method('add')->with($subscription);

        $this->controller->restoreAction($this->post());

        self::assertSame(SubscriptionStatus::WAITING, $subscription->getStatus());
        self::assertSame([], $this->undo->peek(new \DateTimeImmutable()));
    }

    public function testRowStates(): void
    {
        $now = new \DateTimeImmutable();
        $variant = new ProductVariant();

        $waiting = $this->subscription(1, $this->customer);
        self::assertSame(['waiting', 3], $this->rowOf($waiting, $now, [1 => 3]));

        $held = $this->subscription(2, $this->customer);
        $held->markNotified($now, $variant, $now->modify('+2 hours'));
        self::assertSame(['held', null], $this->rowOf($held, $now, []));

        $expired = $this->subscription(3, $this->customer);
        $expired->markNotified($now->modify('-3 hours'), $variant, $now->modify('-1 hour'));
        self::assertSame(['arrived', null], $this->rowOf($expired, $now, []));

        $fastest = $this->subscription(4, $this->customer);
        $fastest->markNotified($now, null, null);
        self::assertSame(['arrived', null], $this->rowOf($fastest, $now, []));

        $done = $this->subscription(5, $this->customer);
        $done->markFulfilled($now);
        self::assertSame(['fulfilled', null], $this->rowOf($done, $now, []));
    }

    /**
     * @param array<int, int> $positions
     *
     * @return array{string, int|null}
     */
    private function rowOf(Subscription $subscription, \DateTimeImmutable $now, array $positions): array
    {
        $row = AccountRow::create($subscription, $now, $positions);

        return [$row->state, $row->position];
    }

    private function currentCustomer(): ?CustomerInterface
    {
        return $this->customer;
    }

    private function post(): Request
    {
        $request = Request::create('/hu/account/back-in-stock-notification/5/cancel', 'POST', ['_token' => 'valid']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function customer(int $id, string $email): Customer
    {
        $customer = new Customer();
        (new \ReflectionProperty(Customer::class, 'id'))->setValue($customer, $id);
        $customer->setEmail($email);
        $customer->setEmailCanonical($email);

        return $customer;
    }

    private function subscription(int $id, ?CustomerInterface $customer): Subscription
    {
        $subscription = new Subscription();
        (new \ReflectionProperty(Subscription::class, 'id'))->setValue($subscription, $id);
        $subscription->setEmail('anna@example.com');
        $subscription->setEmailCanonical('anna@example.com');
        $subscription->setCustomer($customer);

        return $subscription;
    }
}
