<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;

/**
 * The admin grid's "Cancel" action: cancels an active pre-order (the customer can still see that it was cancelled in
 * the admin grid; it is gone from their own lists).
 */
final class AdminCancelController
{
    public const CSRF_ID = 'webgriffe_bisn_admin_cancel';

    public function __construct(
        private UrlGeneratorInterface $router,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private SubscriptionRepositoryInterface $subscriptionRepository,
    ) {
    }

    public function cancelAction(Request $request, int $id): Response
    {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_ID, $token))) {
            throw new AccessDeniedHttpException();
        }

        $subscription = $this->subscriptionRepository->find($id);
        if (!$subscription instanceof SubscriptionInterface) {
            throw new NotFoundHttpException();
        }

        if ($subscription->isActive()) {
            $subscription->markCancelled(new \DateTimeImmutable());
            $this->subscriptionRepository->add($subscription);

            $session = $request->hasSession() ? $request->getSession() : null;
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('success', 'webgriffe_bisn.admin.cancelled');
            }
        }

        return new RedirectResponse($this->router->generate('webgriffe_admin_back_in_stock_notification_subscription_index'));
    }
}
