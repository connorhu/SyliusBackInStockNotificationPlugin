<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Processor;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Factory\SubscriptionFactoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webmozart\Assert\Assert;

final class SubscriptionProcessor implements SubscriptionProcessorInterface
{
    public function __construct(
        private SubscriptionFactoryInterface $subscriptionFactory,
        private SubscriptionRepositoryInterface $backInStockNotificationRepository,
        private ChannelContextInterface $channelContext,
        private LocaleContextInterface $localeContext,
        private SenderInterface $sender,
    ) {
    }

    public function process(
        ProductVariantInterface $productVariant,
        string $email,
        ?CustomerInterface $customer = null,
    ): SubscriptionInterface {
        $channel = $this->channelContext->getChannel();
        $localeCode = $this->localeContext->getLocaleCode();
        $product = $productVariant->getProduct();
        Assert::isInstanceOf($product, ProductInterface::class);

        $subscription = $this->subscriptionFactory->createWithData(
            $channel,
            $product,
            $productVariant,
            $email,
            null,
            $localeCode,
            $customer,
        );

        $this->backInStockNotificationRepository->add($subscription);
        /** @psalm-suppress DeprecatedMethod */
        $this->sender->send(
            'webgriffe_back_in_stock_notification_success_subscription',
            [$subscription->getEmail()],
            [
                'subscription' => $subscription,
                'channel' => $subscription->getChannel(),
                'localeCode' => $subscription->getLocaleCode(),
            ],
            [],
            [],
        );

        return $subscription;
    }
}
