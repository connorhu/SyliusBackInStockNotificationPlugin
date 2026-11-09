<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Hold;

use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Symfony\Contracts\Service\ResetInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionStatus;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Target\TargetResolverInterface;

/**
 * The current shop visitor as a pre-order owner: the logged-in customer (its pre-orders, and the guest ones of its
 * canonical e-mail) plus the pre-orders claimed in this session. The lookup is one query, memoised per request.
 *
 * A claim stays in the session after the hold ends: while that pre-order is still `notified` its holder keeps the right
 * every notified pre-order of the target has (spec: earlier holders may buy held units).
 */
final class HoldVisitor implements ResetInterface
{
    /** @var array<string, list<SubscriptionInterface>> */
    private array $subscriptions = [];

    public function __construct(
        private CustomerContextInterface $customerContext,
        private ClaimSession $claimSession,
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private TargetResolverInterface $targetResolver,
    ) {
    }

    /**
     * The visitor's active (waiting or notified) pre-orders in every channel.
     *
     * @return list<SubscriptionInterface>
     */
    public function subscriptions(): array
    {
        $customer = $this->customerContext->getCustomer();
        $emailCanonical = $customer?->getEmailCanonical();
        $claimedIds = $this->claimSession->claimedIds();
        if (null === $customer && [] === $claimedIds) {
            return [];
        }

        /** @psalm-suppress MixedAssignment */
        $customerId = $customer?->getId();
        $key = sprintf('%s|%s|%s', is_scalar($customerId) ? (string) $customerId : (null === $customer ? '' : (string) spl_object_id($customer)), (string) $emailCanonical, implode(',', $claimedIds));
        if (!isset($this->subscriptions[$key])) {
            $this->subscriptions[$key] = $this->subscriptionRepository->findActiveByVisitor($customer, $emailCanonical, $claimedIds);
        }

        return array_values(array_filter(
            $this->subscriptions[$key],
            static fn (SubscriptionInterface $subscription): bool => $subscription->isActive(),
        ));
    }

    /**
     * Whether the visitor may buy a unit of the variant that is held: they hold an active hold on it, or a pre-order of
     * its target has already been notified (an earlier holder, or the fastest mode).
     */
    public function mayBuyHeld(ProductVariantInterface $variant, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        foreach ($this->subscriptions() as $subscription) {
            if (SubscriptionStatus::NOTIFIED !== $subscription->getStatus()) {
                continue;
            }
            if ($this->holdsActively($subscription, $variant, $now)) {
                return true;
            }
            if ($this->targetContains($subscription, $variant)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Same answer as TargetResolverInterface::variantsOf() containing the variant, without loading the product's variants.
     */
    private function targetContains(SubscriptionInterface $subscription, ProductVariantInterface $variant): bool
    {
        if (TargetResolverInterface::TARGET_VARIANT === $this->targetResolver->target()) {
            $targetVariant = $subscription->getProductVariant();

            return null !== $targetVariant && self::sameVariant($targetVariant, $variant);
        }

        $product = $subscription->getProduct();
        $variantProduct = $variant->getProduct();
        if (null === $product || null === $variantProduct) {
            return false;
        }
        /** @psalm-suppress MixedAssignment */
        $productId = $product->getId();

        return $product === $variantProduct || (null !== $productId && $productId === $variantProduct->getId());
    }

    public function holdsActively(SubscriptionInterface $subscription, ProductVariantInterface $variant, \DateTimeImmutable $now): bool
    {
        $heldVariant = $subscription->getHeldVariant();
        $holdUntil = $subscription->getHoldUntil();

        return SubscriptionStatus::NOTIFIED === $subscription->getStatus() &&
            null !== $heldVariant && null !== $holdUntil && $holdUntil > $now &&
            self::sameVariant($heldVariant, $variant);
    }

    public function reset(): void
    {
        $this->subscriptions = [];
    }

    public static function sameVariant(ProductVariantInterface $a, ProductVariantInterface $b): bool
    {
        if ($a === $b) {
            return true;
        }
        /** @psalm-suppress MixedAssignment */
        $id = $a->getId();

        return null !== $id && $id === $b->getId();
    }
}
