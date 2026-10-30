<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Controller;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\User\Canonicalizer\CanonicalizerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Webgriffe\SyliusBackInStockNotificationPlugin\Mailer\SafeSender;
use Webgriffe\SyliusBackInStockNotificationPlugin\Manage\ManageLinkGenerator;
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
        private SafeSender $sender,
        private RateLimiterFactory $emailLimiterFactory,
        private RateLimiterFactory $ipLimiterFactory,
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

        return $this->render('index', [
            'email' => $email,
            'subscriptions' => $this->subscriptionRepository->findManageableByEmail($email, $this->channelContext->getChannel()),
            'undoCount' => count($this->cancelUndoSession->peek($now)),
        ]);
    }

    public function deleteAction(Request $request, string $hash): Response
    {
        $now = new \DateTimeImmutable();
        $email = $this->grantedEmailForPost($request, $now);

        $subscription = $this->subscriptionRepository->findOneByHashAndEmail($hash, $email);
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

        foreach ($this->subscriptionRepository->findCancelledByIds($this->cancelUndoSession->take($now), $email) as $subscription) {
            $subscription->restore();
            $this->subscriptionRepository->add($subscription);
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
        // Both limiters are consumed whether or not the address has pre-orders, so the answer never depends on it.
        $allowed = $this->emailLimiterFactory->create($emailCanonical)->consume()->isAccepted() &&
            $this->ipLimiterFactory->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted();

        $channel = $this->channelContext->getChannel();
        if ($allowed && [] !== $this->subscriptionRepository->findActiveByEmail($emailCanonical, $channel)) {
            $localeCode = $request->getLocale();
            $this->sender->send(
                'webgriffe_back_in_stock_notification_manage_link',
                $email,
                [
                    'manageUrl' => $this->linkGenerator->generate($emailCanonical, new \DateTimeImmutable(), $localeCode),
                    'ttlHours' => 24,
                ],
                $channel,
                $localeCode,
            );
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
}
