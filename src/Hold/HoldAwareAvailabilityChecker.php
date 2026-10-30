<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Hold;

use Sylius\Bundle\CoreBundle\SectionResolver\SectionProviderInterface;
use Sylius\Bundle\ShopBundle\SectionResolver\ShopSection;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Sylius\Component\Inventory\Model\StockableInterface;
use Symfony\Contracts\Service\ResetInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;

/**
 * Decorates Sylius' availability checker for shop requests: units held for other pre-orders are not available to the
 * visitor. Admin, API, CLI (and so the restock run) get the plain answer. Every active hold count is loaded with one
 * query on first use and memoised for the request (listing pages check many variants).
 */
final class HoldAwareAvailabilityChecker implements AvailabilityCheckerInterface, ResetInterface
{
    /** @var array<int, int>|null variant id => active holds; null until loaded */
    private ?array $holds = null;

    public function __construct(
        private AvailabilityCheckerInterface $decoratedChecker,
        private SectionProviderInterface $sectionProvider,
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private HoldVisitor $holdVisitor,
    ) {
    }

    public function isStockAvailable(StockableInterface $stockable): bool
    {
        return $this->isStockSufficient($stockable, 1);
    }

    public function isStockSufficient(StockableInterface $stockable, int $quantity): bool
    {
        if (!$this->decoratedChecker->isStockSufficient($stockable, $quantity)) {
            return false;
        }
        if (!$stockable instanceof ProductVariantInterface || !$stockable->isTracked() || !$this->sectionProvider->getSection() instanceof ShopSection) {
            return true;
        }

        $now = new \DateTimeImmutable();
        $holds = $this->holdsOn($stockable, $now);
        if (0 === $holds || $this->holdVisitor->mayBuyHeld($stockable, $now)) {
            return true;
        }

        return (int) $stockable->getOnHand() - (int) $stockable->getOnHold() - $holds >= $quantity;
    }

    public function reset(): void
    {
        $this->holds = null;
    }

    private function holdsOn(ProductVariantInterface $variant, \DateTimeImmutable $now): int
    {
        /** @psalm-suppress MixedAssignment */
        $id = $variant->getId();
        if (!is_int($id)) {
            return 0;
        }
        $this->holds ??= $this->subscriptionRepository->countAllActiveHolds($now);

        return $this->holds[$id] ?? 0;
    }
}
