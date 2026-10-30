<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Controller;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Sylius\Component\User\Canonicalizer\Canonicalizer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Webgriffe\SyliusBackInStockNotificationPlugin\Controller\ManageController;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\Subscription;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionStatus;
use Webgriffe\SyliusBackInStockNotificationPlugin\Mailer\SafeSender;
use Webgriffe\SyliusBackInStockNotificationPlugin\Manage\ManageLinkGenerator;
use Webgriffe\SyliusBackInStockNotificationPlugin\Manage\ManageSession;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Session\CancelUndoSession;

final class ManageControllerTest extends TestCase
{
    private SubscriptionRepositoryInterface&MockObject $repository;

    private SenderInterface&MockObject $mailSender;

    private ManageSession $manageSession;

    private CancelUndoSession $undoSession;

    private RequestStack $requestStack;

    private Session $session;

    private ManageController $controller;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->requestStack = new RequestStack();
        $this->manageSession = new ManageSession($this->requestStack);
        $this->undoSession = new CancelUndoSession($this->requestStack);
        $this->repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $this->mailSender = $this->createMock(SenderInterface::class);
        $this->controller = $this->buildController();
        $this->post();
    }

    public function testDeleteRefusedWithoutGrant(): void
    {
        $this->repository->expects(self::never())->method('add');

        $this->expectException(AccessDeniedHttpException::class);
        $this->controller->deleteAction($this->post(), 'hash');
    }

    public function testDeleteRefusedForOtherEmail(): void
    {
        $this->manageSession->grant('a@b.hu', new \DateTimeImmutable('+1 hour'));
        // The lookup is scoped to the granted e-mail, so another person's pre-order is simply not found.
        $this->repository->expects(self::once())->method('findOneByHashAndEmail')->with('hash', 'a@b.hu')->willReturn(null);
        $this->repository->expects(self::never())->method('add');

        $this->expectException(NotFoundHttpException::class);
        $this->controller->deleteAction($this->post(), 'hash');
    }

    public function testDeleteMarksCancelled(): void
    {
        $this->manageSession->grant('a@b.hu', new \DateTimeImmutable('+1 hour'));
        $subscription = $this->subscription(5);
        $this->repository->method('findOneByHashAndEmail')->willReturn($subscription);
        $this->repository->expects(self::never())->method('remove');
        $this->repository->expects(self::once())->method('add')->with($subscription);

        $response = $this->controller->deleteAction($this->post(), 'hash');

        self::assertSame(SubscriptionStatus::CANCELLED, $subscription->getStatus());
        self::assertSame(302, $response->getStatusCode());
        self::assertSame([5], $this->undoSession->peek(new \DateTimeImmutable()));
    }

    public function testDeleteAllCancelsOnlyActiveOfThatEmail(): void
    {
        $this->manageSession->grant('a@b.hu', new \DateTimeImmutable('+1 hour'));
        $one = $this->subscription(1);
        $two = $this->subscription(2);
        $this->repository->expects(self::once())->method('findActiveByEmail')->with('a@b.hu', self::anything())->willReturn([$one, $two]);
        $this->repository->expects(self::exactly(2))->method('add');

        $this->controller->deleteAllAction($this->post());

        self::assertSame(SubscriptionStatus::CANCELLED, $one->getStatus());
        self::assertSame(SubscriptionStatus::CANCELLED, $two->getStatus());
        self::assertSame([1, 2], $this->undoSession->peek(new \DateTimeImmutable()));
    }

    public function testRestoreBringsBackDeleteAllWithin10Minutes(): void
    {
        $this->manageSession->grant('a@b.hu', new \DateTimeImmutable('+1 hour'));
        $one = $this->subscription(1);
        $one->markCancelled(new \DateTimeImmutable());
        $two = $this->subscription(2);
        $two->markCancelled(new \DateTimeImmutable());
        $this->push($this->post());
        $this->undoSession->remember([1, 2], new \DateTimeImmutable('-9 minutes'));
        $this->repository->expects(self::once())->method('findCancelledByIds')->with([1, 2], 'a@b.hu')->willReturn([$one, $two]);
        $this->repository->expects(self::exactly(2))->method('add');

        $this->controller->restoreAction($this->post());

        self::assertSame(SubscriptionStatus::WAITING, $one->getStatus());
        self::assertSame(SubscriptionStatus::WAITING, $two->getStatus());
    }

    public function testRestoreAfter10MinutesDoesNothing(): void
    {
        $this->manageSession->grant('a@b.hu', new \DateTimeImmutable('+1 hour'));
        $this->push($this->post());
        $this->undoSession->remember([1], new \DateTimeImmutable('-11 minutes'));
        $this->repository->expects(self::never())->method('add');
        $this->repository->method('findCancelledByIds')->willReturn([]);

        $this->controller->restoreAction($this->post());
    }

    public function testNewLinkRequestAlwaysAnswersSent(): void
    {
        // Unknown e-mail: no pre-orders, no mail, same redirect.
        $this->repository->method('findActiveByEmail')->willReturnOnConsecutiveCalls([], [$this->subscription(1)], [$this->subscription(1)]);
        $this->mailSender->expects(self::once())->method('send');

        $unknown = $this->controller->requestAction($this->post(['email' => 'nobody@b.hu']));
        $known = $this->controller->requestAction($this->post(['email' => 'a@b.hu']));
        // The e-mail limiter (1 per minute) is used up: same redirect, no second mail.
        $limited = $this->controller->requestAction($this->post(['email' => 'a@b.hu']));

        self::assertSame('/sent', $unknown->headers->get('Location'));
        self::assertSame('/sent', $known->headers->get('Location'));
        self::assertSame('/sent', $limited->headers->get('Location'));
    }

    public function testIpLimiterBlocksMailButStillAnswersSent(): void
    {
        $this->repository->method('findActiveByEmail')->willReturn([$this->subscription(1)]);
        $this->mailSender->expects(self::exactly(10))->method('send');

        for ($i = 0; $i < 12; ++$i) {
            $response = $this->controller->requestAction($this->post(['email' => "user$i@b.hu"]));
            self::assertSame('/sent', $response->headers->get('Location'));
        }
    }

    private function subscription(int $id): Subscription
    {
        $subscription = new Subscription();
        $property = new \ReflectionProperty(Subscription::class, 'id');
        $property->setValue($subscription, $id);

        return $subscription;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function post(array $parameters = []): Request
    {
        $request = Request::create('/hu/back-in-stock-notification/manage', 'POST', $parameters + ['_token' => 'ok'], [], [], ['REMOTE_ADDR' => '10.0.0.1']);
        $request->setSession($this->session);
        $request->setLocale('hu_HU');
        $this->push($request);

        return $request;
    }

    private function push(Request $request): void
    {
        if ($this->requestStack->getCurrentRequest() !== $request) {
            $this->requestStack->push($request);
        }
    }

    private function buildController(): ManageController
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($channel);
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $name): string => str_ends_with($name, 'link_sent') ? '/sent' : '/x',
        );
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('');

        return new ManageController(
            $twig,
            $router,
            $csrf,
            $this->repository,
            new ManageLinkGenerator($router, new UriSigner('secret')),
            $this->manageSession,
            $this->undoSession,
            $this->createMock(CustomerContextInterface::class),
            $channelContext,
            new Canonicalizer(),
            new SafeSender($this->mailSender, new NullLogger()),
            new RateLimiterFactory(['id' => 'email', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '60 seconds'], new InMemoryStorage()),
            new RateLimiterFactory(['id' => 'ip', 'policy' => 'sliding_window', 'limit' => 10, 'interval' => '1 hour'], new InMemoryStorage()),
        );
    }
}
