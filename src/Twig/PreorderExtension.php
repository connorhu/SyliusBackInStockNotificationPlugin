<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Twig;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\State\PreorderStateResolver;
use Webgriffe\SyliusBackInStockNotificationPlugin\State\PreorderView;
use Webmozart\Assert\Assert;

/**
 * Template helpers for themes that render the pre-order state themselves (e.g. on product cards and in the header).
 */
final class PreorderExtension extends AbstractExtension
{
    public function __construct(
        private PreorderStateResolver $stateResolver,
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private ChannelContextInterface $channelContext,
        private CustomerContextInterface $customerContext,
        private ?string $displayTimezone = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('webgriffe_bisn_view', $this->view(...)),
            new TwigFunction('webgriffe_bisn_account_summary', $this->accountSummary(...)),
            new TwigFunction('webgriffe_bisn_hold_deadlines', $this->holdDeadlines(...)),
            new TwigFunction('webgriffe_bisn_timezone', $this->timezone(...)),
        ];
    }

    /**
     * The pre-order state of a product (or variant, under the `variant` target) for the current visitor.
     */
    public function view(ProductInterface $product, ?ProductVariantInterface $variant = null): PreorderView
    {
        return $this->stateResolver->resolve($product, $variant, $this->channel());
    }

    /**
     * Active pre-order count and whether any is actionable, for the logged-in customer; null for a guest.
     *
     * @return array{active: int, actionable: bool}|null
     */
    public function accountSummary(): ?array
    {
        $customer = $this->customerContext->getCustomer();
        if (null === $customer) {
            return null;
        }

        return $this->subscriptionRepository->accountSummary($customer, $this->channel());
    }

    /**
     * The latest active hold deadline per product id (one query); products without an active hold are left out.
     *
     * @param iterable<ProductInterface> $products
     *
     * @return array<int, \DateTimeImmutable>
     */
    public function holdDeadlines(iterable $products): array
    {
        $ids = [];
        foreach ($products as $product) {
            /** @psalm-suppress MixedAssignment */
            $id = $product->getId();
            if (is_int($id)) {
                $ids[] = $id;
            }
        }

        return $this->subscriptionRepository->activeHoldDeadlinesByProduct($ids, $this->channel());
    }

    /**
     * The configured `display_timezone` (null = the PHP default), to pass to `format_datetime()`.
     */
    public function timezone(): ?string
    {
        return $this->displayTimezone;
    }

    private function channel(): ChannelInterface
    {
        $channel = $this->channelContext->getChannel();
        Assert::isInstanceOf($channel, ChannelInterface::class);

        return $channel;
    }
}
