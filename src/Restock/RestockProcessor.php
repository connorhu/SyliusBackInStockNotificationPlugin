<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Restock;

use Doctrine\Persistence\ObjectManager;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Component\Routing\RouterInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Claim\ClaimLinkGenerator;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderChannelSettingsInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionStatus;
use Webgriffe\SyliusBackInStockNotificationPlugin\Mailer\SafeSender;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Target\TargetResolverInterface;

/**
 * One restock run: for every target with active pre-orders it expires lapsed holds, moves notified pre-orders of a
 * sold-out target back to waiting and notifies waiting ones while there are free units (sellable units minus active
 * holds). Each target's changes are flushed before its mails are sent, so a crash loses a mail at worst and never
 * repeats one.
 *
 * @psalm-type RestockPlan = array{
 *     now: \DateTimeImmutable,
 *     mode: string,
 *     sellable: bool,
 *     expire: list<SubscriptionInterface>,
 *     rewait: list<SubscriptionInterface>,
 *     notify: list<array{SubscriptionInterface, ?ProductVariantInterface, ?\DateTimeImmutable}>,
 * }
 */
final class RestockProcessor
{
    public const ALERT_MAIL = 'webgriffe_back_in_stock_notification_alert';

    public const HOLD_EXPIRED_MAIL = 'webgriffe_back_in_stock_notification_hold_expired';

    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private ObjectManager $subscriptionManager,
        private TargetResolverInterface $targetResolver,
        private SellableUnits $sellableUnits,
        private SafeSender $sender,
        private RouterInterface $router,
        private LoggerInterface $logger,
        private ClaimLinkGenerator $claimLinkGenerator,
    ) {
    }

    public function process(\DateTimeImmutable $now): RestockReport
    {
        $report = new RestockReport();

        foreach ($this->subscriptionRepository->findRestockTargets() as $representative) {
            $channel = $representative->getChannel();
            $product = $representative->getProduct();
            if (!$channel instanceof ChannelInterface || null === $product) {
                continue;
            }
            $hasVariant = null !== $representative->getProductVariant();
            if ($hasVariant !== (TargetResolverInterface::TARGET_VARIANT === $this->targetResolver->target())) {
                // A row from before a target switch (or an upstream row without a variant under the variant target):
                // processing it would open a second queue over the same units, so it is left alone.
                $this->logger->warning('webgriffe_bisn.restock.target_mismatch', [
                    'product' => $product->getCode(),
                    'variant' => $representative->getProductVariant()?->getCode(),
                    'channel' => $channel->getCode(),
                    'target' => $this->targetResolver->target(),
                ]);

                continue;
            }

            try {
                $plan = $this->plan($representative, $product, $channel, $now);
            } catch (\Throwable $exception) {
                $report->addFailedTarget();
                $this->logger->error('webgriffe_bisn.restock.target_failed', [
                    'product' => $product->getCode(),
                    'variant' => $representative->getProductVariant()?->getCode(),
                    'channel' => $channel->getCode(),
                    'exception' => $exception,
                ]);

                continue;
            }

            if ([] === $plan['expire'] && [] === $plan['rewait'] && [] === $plan['notify']) {
                continue;
            }

            try {
                $this->apply($plan);
                $this->subscriptionManager->flush();
            } catch (\Throwable $exception) {
                $report->abort();
                $this->logger->error('webgriffe_bisn.restock.flush_failed', [
                    'product' => $product->getCode(),
                    'channel' => $channel->getCode(),
                    'exception' => $exception,
                ]);

                return $report;
            }

            $report->addExpired(count($plan['expire']));
            $report->addRewaited(count($plan['rewait']));
            foreach ($plan['notify'] as [, $heldVariant]) {
                $report->addNotified(null !== $heldVariant);
            }

            $this->sendMails($plan, $product, $channel);
        }

        return $report;
    }

    /**
     * Decides what happens to a target without changing anything.
     *
     * @return RestockPlan
     */
    private function plan(SubscriptionInterface $representative, ProductInterface $product, ChannelInterface $channel, \DateTimeImmutable $now): array
    {
        $subscriptions = array_values(array_filter(
            $this->subscriptionRepository->findActiveByTarget($product, $representative->getProductVariant(), $channel),
            static fn (SubscriptionInterface $subscription): bool => $subscription->isActive(),
        ));
        usort($subscriptions, static fn (SubscriptionInterface $a, SubscriptionInterface $b): int => [$a->getCreatedAt(), (int) $a->getId()] <=> [$b->getCreatedAt(), (int) $b->getId()]);

        $variants = $this->targetResolver->variantsOf($representative);
        usort($variants, static fn (ProductVariantInterface $a, ProductVariantInterface $b): int => (int) $a->getId() <=> (int) $b->getId());

        $sellable = [];
        $targetSellable = false;
        foreach ($variants as $variant) {
            $units = $this->sellableUnits->of($variant, $channel);
            $sellable[self::key($variant)] = $units;
            $targetSellable = $targetSellable || $units > 0;
        }

        // 1. Expire: a hold that ran out releases its unit; the holder stays notified and may still buy.
        $expire = [];
        $holders = [];
        foreach ($subscriptions as $subscription) {
            if (SubscriptionStatus::NOTIFIED !== $subscription->getStatus() || null === $subscription->getHeldVariant()) {
                continue;
            }
            $holdUntil = $subscription->getHoldUntil();
            if (null === $holdUntil || $holdUntil <= $now) {
                $expire[] = $subscription;

                continue;
            }
            $holders[spl_object_id($subscription)] = true;
        }

        // Active holds on the target's variants in every channel: stock is shared, so a unit held anywhere is not free.
        // Nothing is pending in memory (each target is flushed before the next one), so the database count is current.
        $activeHolds = [];
        foreach ($this->subscriptionRepository->countActiveHoldsByVariant($variants, $now) as $variantId => $holds) {
            $activeHolds['id-' . $variantId] = $holds;
        }

        // 2. Re-wait: a notified pre-order without an active hold whose target sold out goes back to waiting,
        //    keeping its createdAt (queue position). Units held for others do not count as sold out: a notified
        //    pre-order may still buy them.
        $rewait = [];
        if (!$targetSellable) {
            foreach ($subscriptions as $subscription) {
                if (SubscriptionStatus::NOTIFIED === $subscription->getStatus() && !isset($holders[spl_object_id($subscription)])) {
                    $rewait[] = $subscription;
                }
            }
        }

        // 3. Allocate free units (sellable minus active holds) to waiting pre-orders.
        $waiting = array_values(array_filter(
            $subscriptions,
            static fn (SubscriptionInterface $subscription): bool => SubscriptionStatus::WAITING === $subscription->getStatus(),
        ));
        $settings = $channel instanceof PreorderChannelSettingsInterface ? $channel : null;
        $mode = $settings?->getPreorderMode() ?? PreorderChannelSettingsInterface::DEFAULT_MODE;
        $holdHours = max(1, $settings?->getPreorderHoldHours() ?? PreorderChannelSettingsInterface::DEFAULT_HOLD_HOURS);

        $notify = [];
        if (PreorderChannelSettingsInterface::MODE_FASTEST === $mode) {
            $anyFree = false;
            foreach ($variants as $variant) {
                $key = self::key($variant);
                $anyFree = $anyFree || $sellable[$key] - ($activeHolds[$key] ?? 0) > 0;
            }
            if ($anyFree) {
                foreach ($waiting as $subscription) {
                    $notify[] = [$subscription, null, null];
                }
            }
        } else {
            $holdUntil = $now->add(new \DateInterval(sprintf('PT%dH', $holdHours)));
            foreach ($variants as $variant) {
                $key = self::key($variant);
                $free = max(0, $sellable[$key] - ($activeHolds[$key] ?? 0));
                while ($free > 0 && [] !== $waiting) {
                    $notify[] = [array_shift($waiting), $variant, $holdUntil];
                    --$free;
                }
            }
        }

        return [
            'mode' => $mode,
            'sellable' => $targetSellable,
            'expire' => $expire,
            'rewait' => $rewait,
            'notify' => $notify,
            'now' => $now,
        ];
    }

    /**
     * @param RestockPlan $plan
     */
    private function apply(array $plan): void
    {
        foreach ($plan['expire'] as $subscription) {
            $subscription->releaseHold();
        }
        foreach ($plan['rewait'] as $subscription) {
            $subscription->markWaiting();
        }
        foreach ($plan['notify'] as [$subscription, $heldVariant, $holdUntil]) {
            $subscription->markNotified($plan['now'], $heldVariant, $holdUntil);
        }
    }

    /**
     * @param RestockPlan $plan
     */
    private function sendMails(array $plan, ProductInterface $product, ChannelInterface $channel): void
    {
        $context = $this->router->getContext();
        $previousHost = $context->getHost();
        $context->setHost($channel->getHostname() ?? $previousHost);

        try {
            foreach ($plan['expire'] as $subscription) {
                $this->send(self::HOLD_EXPIRED_MAIL, $subscription, $channel, [
                    'subscription' => $subscription,
                    'product' => $product,
                    'sellable' => $plan['sellable'],
                ]);
            }
            foreach ($plan['notify'] as [$subscription, $heldVariant, $holdUntil]) {
                $this->send(self::ALERT_MAIL, $subscription, $channel, [
                    'subscription' => $subscription,
                    'product' => $product,
                    'variant' => $heldVariant,
                    'mode' => $plan['mode'],
                    'holdUntil' => $holdUntil,
                    'claimUrl' => $this->claimUrl($subscription),
                ]);
            }
        } finally {
            $context->setHost($previousHost);
        }
    }

    /**
     * The signed claim link while the unit is held (queue mode), the product page otherwise; null (the template falls back
     * to the product page) when it cannot be built, so a broken link never stops the run.
     */
    private function claimUrl(SubscriptionInterface $subscription): ?string
    {
        try {
            return $this->claimLinkGenerator->generate($subscription);
        } catch (\Throwable $exception) {
            $this->logger->warning('webgriffe_bisn.restock.claim_link_failed', [
                'subscription' => $subscription->getId(),
                'exception' => $exception,
            ]);

            return null;
        }
    }

    /** Identifies a variant by id, or by object for one not persisted yet. */
    private static function key(ProductVariantInterface $variant): string
    {
        /** @var mixed $id */
        $id = $variant->getId();
        if (is_int($id)) {
            return 'id-' . $id;
        }

        return 'object-' . spl_object_id($variant);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function send(string $code, SubscriptionInterface $subscription, ChannelInterface $channel, array $data): void
    {
        $email = $subscription->getEmail();
        if (null === $email) {
            return;
        }
        $localeCode = $subscription->getLocaleCode() ?? $channel->getDefaultLocale()?->getCode() ?? 'en_US';

        $this->sender->send($code, $email, $data, $channel, $localeCode);
    }
}
