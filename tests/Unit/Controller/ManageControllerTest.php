<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Controller;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Sylius\Component\User\Canonicalizer\Canonicalizer;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
use Webgriffe\SyliusBackInStockNotificationPlugin\Customer\SubscriptionRestorer;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\Subscription;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionStatus;
use Webgriffe\SyliusBackInStockNotificationPlugin\Mailer\SafeSender;
use Webgriffe\SyliusBackInStockNotificationPlugin\Manage\ManageLinkGenerator;
use Webgriffe\SyliusBackInStockNotificationPlugin\Manage\ManageLinkMailer;
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

    private ChannelInterface&MockObject $channel;

    private CsrfTokenManagerInterface&MockObject $csrf;

    private CustomerContextInterface&MockObject $customerContext;

    private bool $csrfValid = true;

    private ManageLinkGenerator $links;

    private ManageLinkMailer $linkMailer;

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
        $this->repository->expects(self::once())->method('findOneByHashAndEmail')->with('hash', 'a@b.hu', $this->channel)->willReturn(null);
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
        $this->repository->expects(self::once())->method('findActiveByEmail')->with('a@b.hu', $this->channel)->willReturn([$one, $two]);
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
        $this->repository->expects(self::once())->method('findCancelledByIds')->with([1, 2], 'a@b.hu', $this->channel)->willReturn([$one, $two]);
        $this->repository->expects(self::exactly(2))->method('add');

        $this->controller->restoreAction($this->post());

        self::assertSame(SubscriptionStatus::WAITING, $one->getStatus());
        self::assertSame(SubscriptionStatus::WAITING, $two->getStatus());
    }

    public function testRestoreKeepsCancelledWhenTheSameTargetIsPreorderedAgain(): void
    {
        $this->manageSession->grant('a@b.hu', new \DateTimeImmutable('+1 hour'));
        $product = new Product();
        $one = $this->subscription(1);
        $one->setProduct($product);
        $one->setChannel($this->channel);
        $one->setEmailCanonical('a@b.hu');
        $one->markCancelled(new \DateTimeImmutable());
        $this->push($this->post());
        $this->undoSession->remember([1], new \DateTimeImmutable('-1 minute'));
        $this->repository->method('findCancelledByIds')->willReturn([$one]);
        $this->repository->method('findActiveOne')->with('a@b.hu', $product, null, $this->channel)->willReturn($this->subscription(9));
        $this->repository->expects(self::never())->method('add');

        $this->controller->restoreAction($this->post());

        self::assertSame(SubscriptionStatus::CANCELLED, $one->getStatus());
        self::assertSame(['webgriffe_bisn.restore_refused'], $this->session->getFlashBag()->get('info'));
    }

    public function testRestoreAfter10MinutesDoesNothing(): void
    {
        $this->manageSession->grant('a@b.hu', new \DateTimeImmutable('+1 hour'));
        $this->push($this->post());
        $this->undoSession->remember([1], new \DateTimeImmutable('-11 minutes'));
        $this->repository->expects(self::never())->method('add');
        $this->repository->expects(self::once())->method('findCancelledByIds')->with([], 'a@b.hu', $this->channel)->willReturn([]);

        $this->controller->restoreAction($this->post());
    }

    public function testNewLinkRequestAnswersTheSameAndSendsNothingInTheRequest(): void
    {
        // The lookup and the mail run on kernel.terminate: during the request the repository is never asked.
        $this->repository->expects(self::never())->method('findActiveByEmail');
        $this->mailSender->expects(self::never())->method('send');

        $unknown = $this->controller->requestAction($this->post(['email' => 'nobody@b.hu']));
        $known = $this->controller->requestAction($this->post(['email' => 'a@b.hu']));
        // The e-mail limiter (1 per minute) is used up: same redirect again.
        $limited = $this->controller->requestAction($this->post(['email' => 'a@b.hu']));

        foreach ([$unknown, $known, $limited] as $response) {
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('/sent', $response->headers->get('Location'));
        }
    }

    public function testNewLinkMailGoesOutOnTerminateOnlyForEligibleAddresses(): void
    {
        $this->repository->method('findActiveByEmail')->willReturnCallback(
            fn (string $email): array => 'a@b.hu' === $email ? [$this->subscription(1)] : [],
        );
        $this->mailSender->expects(self::once())->method('send');

        $this->controller->requestAction($this->post(['email' => 'nobody@b.hu']));
        $this->controller->requestAction($this->post(['email' => 'a@b.hu']));
        // Limited by the e-mail limiter: not deferred.
        $this->controller->requestAction($this->post(['email' => 'a@b.hu']));
        $this->linkMailer->onTerminate();
        $this->linkMailer->onTerminate();
    }

    public function testIpLimiterStopsDeferredMailsButStillAnswersSent(): void
    {
        $this->repository->method('findActiveByEmail')->willReturn([$this->subscription(1)]);
        $this->mailSender->expects(self::exactly(10))->method('send');

        for ($i = 0; $i < 12; ++$i) {
            $response = $this->controller->requestAction($this->post(['email' => "user$i@b.hu"]));
            self::assertSame('/sent', $response->headers->get('Location'));
        }
        $this->linkMailer->onTerminate();
    }

    public function testRequestWithBadCsrfTokenIsRefused(): void
    {
        $this->csrfValid = false;
        $this->mailSender->expects(self::never())->method('send');

        $response = $this->controller->requestAction($this->post(['email' => 'a@b.hu']));
        $this->linkMailer->onTerminate();

        self::assertSame(422, $response->getStatusCode());
    }

    public function testDeleteRefusedWithBadCsrfToken(): void
    {
        $this->manageSession->grant('a@b.hu', new \DateTimeImmutable('+1 hour'));
        $this->csrfValid = false;
        $this->repository->expects(self::never())->method('add');

        $this->expectException(AccessDeniedHttpException::class);
        $this->controller->deleteAction($this->post(), 'hash');
    }

    public function testRestoreAndDeleteAllRefusedWithoutGrant(): void
    {
        $this->repository->expects(self::never())->method('add');

        foreach ([[$this->controller, 'restoreAction'], [$this->controller, 'deleteAllAction']] as $callable) {
            try {
                $callable($this->post());
                self::fail('The action must be refused');
            } catch (AccessDeniedHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testValidLinkGrantsUntilItsExpiryAndRedirectsToTheCleanUrl(): void
    {
        $issued = new \DateTimeImmutable('-1 hour');
        $url = $this->links->generate('a@b.hu', $issued, 'hu_HU');
        $request = $this->getWithSession($url);

        $response = $this->controller->manageAction($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/x', $response->headers->get('Location'));
        $expires = $issued->modify('+24 hours');
        self::assertSame('a@b.hu', $this->manageSession->grantedEmail($expires->modify('-1 minute')));
        self::assertNull($this->manageSession->grantedEmail($expires->modify('+1 second')));
    }

    /**
     * @dataProvider badLinks
     */
    public function testInvalidTamperedOrExpiredLinkAnswers410WithoutGrant(string $kind): void
    {
        $now = new \DateTimeImmutable();
        $url = match ($kind) {
            'expired' => $this->links->generate('a@b.hu', $now->modify('-25 hours'), 'hu_HU'),
            'tampered' => str_replace('a%40b.hu', 'c%40d.hu', $this->links->generate('a@b.hu', $now, 'hu_HU')),
            default => 'https://shop.test/hu/back-in-stock-notification/manage?_hash=garbage&email=a%40b.hu&expires=' . ($now->getTimestamp() + 3600),
        };

        $response = $this->controller->manageAction($this->getWithSession($url));

        self::assertSame(410, $response->getStatusCode());
        self::assertNull($this->manageSession->grantedEmail($now));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badLinks(): iterable
    {
        yield 'expired' => ['expired'];
        yield 'tampered' => ['tampered'];
        yield 'garbage signature' => ['garbage'];
    }

    public function testLoggedInCustomerOpeningOwnLinkGoesToTheAccountListWithoutGrant(): void
    {
        $customer = $this->createMock(\Sylius\Component\Core\Model\CustomerInterface::class);
        $customer->method('getEmail')->willReturn('A@B.hu');
        $this->customerContext->method('getCustomer')->willReturn($customer);
        $url = $this->links->generate('a@b.hu', new \DateTimeImmutable(), 'hu_HU');

        $response = $this->controller->manageAction($this->getWithSession($url));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/account', $response->headers->get('Location'));
        self::assertNull($this->manageSession->grantedEmail(new \DateTimeImmutable()));
    }

    public function testBareVisitWithoutGrantAnswers403(): void
    {
        self::assertSame(403, $this->controller->manageAction($this->getWithSession('https://shop.test/hu/back-in-stock-notification/manage'))->getStatusCode());
    }

    private function getWithSession(string $url): Request
    {
        $request = Request::create($url);
        $request->setSession($this->session);
        $request->setLocale('hu_HU');
        $this->push($request);

        return $request;
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
        $this->channel = $this->createMock(ChannelInterface::class);
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($this->channel);
        $this->csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $this->csrf->method('isTokenValid')->willReturnCallback(fn (): bool => $this->csrfValid);
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $name, array $parameters = []): string => match (true) {
                str_ends_with($name, 'link_sent') => '/sent',
                str_ends_with($name, 'account_list_subscriptions') => '/account',
                str_ends_with($name, '_manage') && isset($parameters['email']) => 'https://shop.test/hu/back-in-stock-notification/manage?' . http_build_query($parameters),
                default => '/x',
            },
        );
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('');
        $this->customerContext = $this->createMock(CustomerContextInterface::class);
        $this->links = new ManageLinkGenerator($router, new UriSigner('secret'));
        $this->linkMailer = new ManageLinkMailer($this->repository, $this->links, new SafeSender($this->mailSender, new NullLogger()), new NullLogger());

        return new ManageController(
            $twig,
            $router,
            $this->csrf,
            $this->repository,
            $this->links,
            $this->manageSession,
            $this->undoSession,
            $this->customerContext,
            $channelContext,
            new Canonicalizer(),
            $this->linkMailer,
            new RateLimiterFactory(['id' => 'email', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '60 seconds'], new InMemoryStorage()),
            new RateLimiterFactory(['id' => 'ip', 'policy' => 'sliding_window', 'limit' => 10, 'interval' => '1 hour'], new InMemoryStorage()),
            new SubscriptionRestorer($this->repository),
        );
    }
}
