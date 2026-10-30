<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\EventListener;

use Doctrine\Persistence\ObjectManager;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\User\Canonicalizer\CanonicalizerInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Hold\HoldVisitor;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Target\TargetResolverInterface;

/**
 * A placed order fulfils the active pre-orders of its customer (or, for pre-orders without a customer, of its canonical
 * e-mail) whose target contains an ordered variant; their holds are released. It never breaks the checkout: any
 * failure is logged.
 */
final class OrderCompletedListener
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private ObjectManager $subscriptionManager,
        private TargetResolverInterface $targetResolver,
        private CanonicalizerInterface $canonicalizer,
        private LoggerInterface $logger,
    ) {
    }

    public function onOrderPostComplete(GenericEvent $event): void
    {
        $order = $event->getSubject();
        if (!$order instanceof OrderInterface) {
            return;
        }

        try {
            $this->fulfil($order);
        } catch (\Throwable $exception) {
            $this->logger->error('webgriffe_bisn.order_fulfilment_failed', [
                'order' => $order->getNumber(),
                'exception' => $exception,
            ]);
        }
    }

    private function fulfil(OrderInterface $order): void
    {
        $variants = [];
        foreach ($order->getItems() as $item) {
            $variant = $item->getVariant();
            if ($variant instanceof ProductVariantInterface) {
                $variants[] = $variant;
            }
        }
        $customer = $order->getCustomer();
        $email = $customer?->getEmail();
        if ([] === $variants || null === $customer) {
            return;
        }

        $emailCanonical = null === $email ? null : $this->canonicalizer->canonicalize($email);
        $now = new \DateTimeImmutable();
        $changed = false;
        foreach ($this->subscriptionRepository->findActiveByVisitor($customer, $emailCanonical, []) as $subscription) {
            if (!$subscription->isActive() || !$this->targetContainsAny($subscription, $variants)) {
                continue;
            }
            $subscription->markFulfilled($now);
            $changed = true;
        }

        if ($changed) {
            $this->subscriptionManager->flush();
        }
    }

    /**
     * @param list<ProductVariantInterface> $variants
     */
    private function targetContainsAny(SubscriptionInterface $subscription, array $variants): bool
    {
        foreach ($this->targetResolver->variantsOf($subscription) as $targetVariant) {
            foreach ($variants as $variant) {
                if (HoldVisitor::sameVariant($targetVariant, $variant)) {
                    return true;
                }
            }
        }

        return false;
    }
}
