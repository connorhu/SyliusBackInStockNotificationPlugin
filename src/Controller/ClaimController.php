<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Claim\ClaimLinkGenerator;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Hold\ClaimSession;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;

/**
 * Opens the claim link of the "it arrived" mail: a valid link marks the pre-order as claimed in this session (its held
 * unit becomes buyable here) and goes to the product page; an invalid or expired one goes there with an info flash.
 * Nothing but the session changes.
 */
final class ClaimController
{
    public const EXPIRED_FLASH = 'webgriffe_bisn.claim.expired';

    public function __construct(
        private UrlGeneratorInterface $router,
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private ClaimLinkGenerator $claimLinkGenerator,
        private ClaimSession $claimSession,
    ) {
    }

    public function claimAction(Request $request, string $hash): Response
    {
        $subscription = $this->subscriptionRepository->findOneBy(['hash' => $hash]);
        $product = $subscription instanceof SubscriptionInterface ? $subscription->getProduct() : null;
        if (!$subscription instanceof SubscriptionInterface || null === $product) {
            throw new NotFoundHttpException();
        }

        /** @psalm-suppress MixedAssignment */
        $id = $subscription->getId();
        if (is_int($id) && $subscription->isActive() && $this->claimLinkGenerator->verify($request, $hash)) {
            $this->claimSession->claim($id);
        } else {
            $session = $request->hasSession() ? $request->getSession() : null;
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('info', self::EXPIRED_FLASH);
            }
        }

        $localeCode = $subscription->getLocaleCode() ?? $request->getLocale();

        return new RedirectResponse($this->router->generate('sylius_shop_product_show', [
            'slug' => (string) $product->getTranslation($localeCode)->getSlug(),
            '_locale' => $localeCode,
        ]));
    }
}
