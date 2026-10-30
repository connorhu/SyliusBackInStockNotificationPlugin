<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Controller;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\User\Canonicalizer\CanonicalizerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Webgriffe\SyliusBackInStockNotificationPlugin\Customer\SubscriptionRestorer;
use Webgriffe\SyliusBackInStockNotificationPlugin\Manage\ManageLinkGenerator;
use Webgriffe\SyliusBackInStockNotificationPlugin\Manage\ManageLinkMailer;
use Webgriffe\SyliusBackInStockNotificationPlugin\Manage\ManageSession;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Session\CancelUndoSession;

/**
 * The guest manage page: opened from a signed e-mail link, it lists the pre-orders of that e-mail address and lets
 * the visitor delete them (soft, with a ten-minute undo). Requesting a new link never reveals whether an address has
 * pre-orders.
 */
final class ManageController
{
    public const CSRF_ID = 'webgriffe_bisn_manage';

    private const TEMPLATE_PREFIX = '@WebgriffeSyliusBackInStockNotificationPlugin/shop/manage/';

    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $router,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private ManageLinkGenerator $linkGenerator,
        private ManageSession $manageSession,
        private CancelUndoSession $cancelUndoSession,
        private CustomerContextInterface $customerContext,
        private ChannelContextInterface $channelContext,
        private CanonicalizerInterface $canonicalizer,
        private ManageLinkMailer $linkMailer,
        private RateLimiterFactory $emailLimiterFactory,
        private RateLimiterFactory $ipLimiterFactory,
        private SubscriptionRestorer $subscriptionRestorer,
    ) {
    }

    public function manageAction(Request $request): Response
    {
        $now = new \DateTimeImmutable();

        if ($request->query->has('_hash')) {
            $email = $this->linkGenerator->verify($request, $now);
            $expiresAt = $this->linkGenerator->expiresAt($request);
            if (null === $email || null === $expiresAt) {
                return $this->render('expired', [], Response::HTTP_GONE);
            }

            $customerEmail = $this->customerContext->getCustomer()?->getEmail();
            if (null !== $customerEmail && $this->canonical($customerEmail) === $this->canonical($email)) {
                return new RedirectResponse($this->router->generate(
                    'webgriffe_back_in_stock_notification_account_list_subscriptions',
                    ['_locale' => $request->getLocale()],
                ));
            }

            $this->manageSession->grant($this->canonical($email), $expiresAt);

            // Drop the signature from the address bar.
            return new RedirectResponse($this->router->generate(ManageLinkGenerator::ROUTE, ['_locale' => $request->getLocale()]));
        }

        $email = $this->manageSession->grantedEmail($now);
        if (null === $email) {
            return $this->render('expired', [], Response::HTTP_FORBIDDEN);
        }

        $channel = $this->channelContext->getChannel();

        return $this->render('index', [
            'email' => $email,
            'subscriptions' => $this->subscriptionRepository->findManageableByEmail($email, $channel),
            // Only ids that are still cancelled and belong to this e-mail count; the memory is shared with other pages.
            'undoCount' => count($this->subscriptionRepository->findCancelledByIds($this->cancelUndoSession->peek($now), $email, $channel)),
        ]);
    }

    public function deleteAction(Request $request, string $hash): Response
    {
        $now = new \DateTimeImmutable();
        $email = $this->grantedEmailForPost($request, $now);

        $subscription = $this->subscriptionRepository->findOneByHashAndEmail($hash, $email, $this->channelContext->getChannel());
        if (null === $subscription) {
            throw new NotFoundHttpException();
        }

        if ($subscription->isActive()) {
            $subscription->markCancelled($now);
            $this->subscriptionRepository->add($subscription);
            /** @psalm-suppress MixedAssignment */
            $id = $subscription->getId();
            if (is_int($id)) {
                $this->cancelUndoSession->remember([$id], $now);
            }
        }

        return $this->redirectToManage($request);
    }

    public function deleteAllAction(Request $request): Response
    {
        $now = new \DateTimeImmutable();
        $email = $this->grantedEmailForPost($request, $now);

        $ids = [];
        foreach ($this->subscriptionRepository->findActiveByEmail($email, $this->channelContext->getChannel()) as $subscription) {
            $subscription->markCancelled($now);
            $this->subscriptionRepository->add($subscription);
            /** @psalm-suppress MixedAssignment */
            $id = $subscription->getId();
            if (is_int($id)) {
                $ids[] = $id;
            }
        }
        $this->cancelUndoSession->remember($ids, $now);

        return $this->redirectToManage($request);
    }

    public function restoreAction(Request $request): Response
    {
        $now = new \DateTimeImmutable();
        $email = $this->grantedEmailForPost($request, $now);

        $refused = false;
        foreach ($this->subscriptionRepository->findCancelledByIds($this->cancelUndoSession->take($now), $email, $this->channelContext->getChannel()) as $subscription) {
            $refused = !$this->subscriptionRestorer->restore($subscription) || $refused;
        }
        if ($refused) {
            self::flashRestoreRefused($request);
        }

        return $this->redirectToManage($request);
    }

    public function requestAction(Request $request): Response
    {
        if (!$request->isMethod(Request::METHOD_POST)) {
            return $this->render('request_link');
        }

        $email = trim((string) $request->request->get('email', ''));
        if (!$this->isCsrfValid($request) || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->render('request_link', ['error' => true, 'email' => $email], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $emailCanonical = $this->canonical($email);
        // Both limiters are consumed whether or not the address has pre-orders. The lookup and the mail itself run
        // after the response (kernel.terminate), so neither the answer nor its timing depends on the address.
        $emailAccepted = $this->emailLimiterFactory->create($emailCanonical)->consume()->isAccepted();
        $ipAccepted = $this->ipLimiterFactory->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted();
        if ($emailAccepted && $ipAccepted) {
            $this->linkMailer->defer($email, $emailCanonical, $this->channelContext->getChannel(), $request->getLocale());
        }

        return new RedirectResponse($this->router->generate('webgriffe_back_in_stock_notification_manage_link_sent', ['_locale' => $request->getLocale()]));
    }

    public function linkSentAction(): Response
    {
        return $this->render('link_sent');
    }

    private function grantedEmailForPost(Request $request, \DateTimeImmutable $now): string
    {
        $email = $this->manageSession->grantedEmail($now);
        if (null === $email || !$this->isCsrfValid($request)) {
            throw new AccessDeniedHttpException();
        }

        return $email;
    }

    private function isCsrfValid(Request $request): bool
    {
        $token = $request->request->get('_token');

        return is_string($token) && $this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_ID, $token));
    }

    private function canonical(string $email): string
    {
        $email = trim($email);

        return $this->canonicalizer->canonicalize($email) ?? $email;
    }

    private function redirectToManage(Request $request): RedirectResponse
    {
        return new RedirectResponse($this->router->generate(ManageLinkGenerator::ROUTE, ['_locale' => $request->getLocale()]));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function render(string $template, array $parameters = [], int $status = Response::HTTP_OK): Response
    {
        return new Response($this->twig->render(self::TEMPLATE_PREFIX . $template . '.html.twig', $parameters), $status);
    }

    /** A restore kept cancelled because the same target is pre-ordered again: say so without any detail. */
    private static function flashRestoreRefused(Request $request): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('info', SubscriptionRestorer::REFUSED_FLASH);
        }
    }
}
