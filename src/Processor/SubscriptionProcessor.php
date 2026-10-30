<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Processor;

use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\User\Canonicalizer\CanonicalizerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Customer\AccountCustomerFinder;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderChannelSettingsInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Factory\SubscriptionFactoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Mailer\SafeSender;
use Webgriffe\SyliusBackInStockNotificationPlugin\Manage\ManageLinkGenerator;
use Webgriffe\SyliusBackInStockNotificationPlugin\Preorderability\PreorderabilityResolverInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\RateLimit\PreorderRateLimiter;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Session\GuestPreorderSession;
use Webgriffe\SyliusBackInStockNotificationPlugin\Target\TargetResolverInterface;
use Webmozart\Assert\Assert;

final class SubscriptionProcessor implements SubscriptionProcessorInterface
{
    public function __construct(
        private SubscriptionFactoryInterface $subscriptionFactory,
        private SubscriptionRepositoryInterface $backInStockNotificationRepository,
        private ChannelContextInterface $channelContext,
        private LocaleContextInterface $localeContext,
        private SafeSender $sender,
        private TargetResolverInterface $targetResolver,
        private PreorderabilityResolverInterface $preorderabilityResolver,
        private AccountCustomerFinder $accountCustomerFinder,
        private GuestPreorderSession $guestPreorderSession,
        private RouterInterface $router,
        private ManageLinkGenerator $manageLinkGenerator,
        private CanonicalizerInterface $canonicalizer,
        private LoggerInterface $logger,
        private PreorderRateLimiter $rateLimiter,
    ) {
    }

    public function process(
        ProductInterface $product,
        ?ProductVariantInterface $variant,
        string $email,
        ?string $name,
        ?CustomerInterface $customer,
        ?string $clientIp = null,
    ): ProcessResult {
        $channel = $this->channelContext->getChannel();
        Assert::isInstanceOf($channel, ChannelInterface::class);
        $localeCode = $this->localeContext->getLocaleCode();

        if (!$this->preorderabilityResolver->isPreorderable($product, $channel)) {
            return ProcessResult::NotPreorderable;
        }

        $target = TargetResolverInterface::TARGET_VARIANT === $this->targetResolver->target() ? $variant : null;
        if (TargetResolverInterface::TARGET_VARIANT === $this->targetResolver->target() && null === $target) {
            return ProcessResult::NotPreorderable;
        }

        $isGuest = null === $customer;
        $customerEmail = $customer?->getEmail();
        if (null !== $customerEmail && '' !== $customerEmail) {
            $email = $customerEmail;
        }
        $email = trim($email);
        $emailCanonical = $this->canonicalizer->canonicalize($email) ?? $email;

        if (null !== $this->targetResolver->findActive($emailCanonical, $product, $target, $channel)) {
            return ProcessResult::AlreadySubscribed;
        }

        // A guest e-mail that belongs to an account attaches the pre-order to that customer.
        $customer ??= $this->accountCustomerFinder->find($emailCanonical);

        // A duplicate never consumes a rate-limit token; a refused attempt stores and sends nothing.
        if (!$this->rateLimiter->allow($emailCanonical, $customer, $clientIp, $channel, new \DateTimeImmutable())) {
            return ProcessResult::RateLimited;
        }

        $subscription = $this->subscriptionFactory->createWithData(
            $channel,
            $product,
            $target,
            $email,
            $name,
            $localeCode,
            $customer,
        );
        $this->backInStockNotificationRepository->add($subscription);

        /** @psalm-suppress MixedAssignment */
        $id = $subscription->getId();
        if ($isGuest && is_int($id)) {
            $this->guestPreorderSession->remember($id);
        }

        $this->sendConfirmation($subscription, $product, $channel, $localeCode);

        return ProcessResult::Created;
    }

    private function sendConfirmation(SubscriptionInterface $subscription, ProductInterface $product, ChannelInterface $channel, string $localeCode): void
    {
        $settings = $channel instanceof PreorderChannelSettingsInterface ? $channel : null;
        $mode = $settings?->getPreorderMode() ?? PreorderChannelSettingsInterface::DEFAULT_MODE;
        $holdHours = $settings?->getPreorderHoldHours() ?? PreorderChannelSettingsInterface::DEFAULT_HOLD_HOURS;

        $context = $this->router->getContext();
        $previousHost = $context->getHost();
        $context->setHost($channel->getHostname() ?? 'localhost');

        try {
            $accountUrl = null;
            $manageUrl = null;
            if (null === $subscription->getCustomer()) {
                $manageUrl = $this->manageLinkGenerator->generate(
                    (string) $subscription->getEmailCanonical(),
                    new \DateTimeImmutable(),
                    $localeCode,
                );
            } else {
                $accountUrl = $this->router->generate(
                    'webgriffe_back_in_stock_notification_account_list_subscriptions',
                    ['_locale' => $localeCode],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                );
            }

            $this->sender->send(
                'webgriffe_back_in_stock_notification_success_subscription',
                (string) $subscription->getEmail(),
                [
                    'subscription' => $subscription,
                    'product' => $product,
                    'manageUrl' => $manageUrl,
                    'accountUrl' => $accountUrl,
                    'mode' => $mode,
                    'holdHours' => $holdHours,
                ],
                $channel,
                $localeCode,
            );
        } catch (\Throwable $exception) {
            // The pre-order is stored; a failure while building the mail must not break the request.
            $this->logger->error('webgriffe_bisn.confirmation_mail_failed', ['exception' => $exception]);
        } finally {
            $context->setHost($previousHost);
        }
    }
}
