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
 * canonical e-mail) plus the pre-orders claimed in this session. The lookup is memoised per request.
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
            foreach ($this->targetResolver->variantsOf($subscription) as $targetVariant) {
                if (self::sameVariant($targetVariant, $variant)) {
                    return true;
                }
            }
        }

        return false;
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
