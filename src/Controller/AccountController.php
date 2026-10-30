<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Controller;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Webgriffe\SyliusBackInStockNotificationPlugin\Account\AccountRow;
use Webgriffe\SyliusBackInStockNotificationPlugin\Customer\SubscriptionOwnership;
use Webgriffe\SyliusBackInStockNotificationPlugin\Customer\SubscriptionRestorer;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderChannelSettingsInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionStatus;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Session\CancelUndoSession;

/**
 * The customer's "Pre-orders" account page: the list, cancel (undoable for ten minutes), restore and dismiss of a
 * fulfilled line. Lines are the customer's own, or those of their canonical e-mail address that were made as a guest.
 */
final class AccountController
{
    public const CSRF_ID = 'webgriffe_bisn_account';

    public const INDEX_ROUTE = 'webgriffe_back_in_stock_notification_account_list_subscriptions';

    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $router,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private CancelUndoSession $cancelUndoSession,
        private CustomerContextInterface $customerContext,
        private ChannelContextInterface $channelContext,
        private SubscriptionRestorer $subscriptionRestorer,
    ) {
    }

    public function indexAction(): Response
    {
        $customer = $this->customer();
        $now = new \DateTimeImmutable();
        $channel = $this->channelContext->getChannel();

        /** @var list<SubscriptionInterface> $subscriptions */
        $subscriptions = $this->subscriptionRepository->createForAccountQueryBuilder($customer, $channel)->getQuery()->getResult();
        // A queue position only means something in the queue mode.
        $queue = !$channel instanceof PreorderChannelSettingsInterface || PreorderChannelSettingsInterface::MODE_QUEUE === $channel->getPreorderMode();
        $positions = $queue ? $this->subscriptionRepository->queuePositions($subscriptions) : [];

        $rows = [];
        foreach ($subscriptions as $subscription) {
            $rows[] = AccountRow::create($subscription, $now, $positions);
        }

        return new Response($this->twig->render('@WebgriffeSyliusBackInStockNotificationPlugin/shop/account/subscription/index.html.twig', [
            'rows' => $rows,
            // Only ids that are still cancelled and belong to this customer count; the memory is shared with other pages.
            'undoCount' => count($this->subscriptionRepository->findCancelledByIds($this->cancelUndoSession->peek($now), $this->emailCanonical($customer), $channel)),
        ]));
    }

    public function cancelAction(Request $request, int $id): Response
    {
        $now = new \DateTimeImmutable();
        $subscription = $this->ownedSubscription($request, $id);

        if ($subscription->isActive()) {
            $subscription->markCancelled($now);
            $this->subscriptionRepository->add($subscription);
            $this->cancelUndoSession->remember([$id], $now);
        }

        return $this->redirectToIndex($request);
    }

    public function restoreAction(Request $request): Response
    {
        $customer = $this->customer();
        $this->assertCsrf($request);

        $subscriptions = $this->subscriptionRepository->findCancelledByIds(
            $this->cancelUndoSession->take(new \DateTimeImmutable()),
            $this->emailCanonical($customer),
            $this->channelContext->getChannel(),
        );
        $refused = false;
        foreach ($subscriptions as $subscription) {
            if (SubscriptionOwnership::isOwnedBy($subscription, $customer)) {
                $refused = !$this->subscriptionRestorer->restore($subscription) || $refused;
            }
        }
        if ($refused) {
            $session = $request->hasSession() ? $request->getSession() : null;
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('info', SubscriptionRestorer::REFUSED_FLASH);
            }
        }

        return $this->redirectToIndex($request);
    }

    public function dismissAction(Request $request, int $id): Response
    {
        $subscription = $this->ownedSubscription($request, $id);

        if (SubscriptionStatus::FULFILLED === $subscription->getStatus()) {
            $subscription->dismiss(new \DateTimeImmutable());
            $this->subscriptionRepository->add($subscription);
        }

        return $this->redirectToIndex($request);
    }

    private function ownedSubscription(Request $request, int $id): SubscriptionInterface
    {
        $customer = $this->customer();
        $this->assertCsrf($request);

        $subscription = $this->subscriptionRepository->find($id);
        if (!$subscription instanceof SubscriptionInterface || !SubscriptionOwnership::isOwnedBy($subscription, $customer)) {
            throw new NotFoundHttpException();
        }

        return $subscription;
    }

    private function customer(): CustomerInterface
    {
        $customer = $this->customerContext->getCustomer();
        if (null === $customer) {
            throw new AccessDeniedHttpException();
        }

        return $customer;
    }

    private function emailCanonical(CustomerInterface $customer): string
    {
        return (string) $customer->getEmailCanonical();
    }

    private function assertCsrf(Request $request): void
    {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_ID, $token))) {
            throw new AccessDeniedHttpException();
        }
    }

    private function redirectToIndex(Request $request): RedirectResponse
    {
        return new RedirectResponse($this->router->generate(self::INDEX_ROUTE, ['_locale' => $request->getLocale()]));
    }
}
