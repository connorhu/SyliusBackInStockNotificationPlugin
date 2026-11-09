<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Entity;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\Subscription;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionStatus;

final class SubscriptionTest extends TestCase
{
    public function testLifecycleTransitions(): void
    {
        $now = new \DateTimeImmutable('2026-10-30 10:00:00');
        $until = $now->modify('+24 hours');
        $variant = $this->createMock(ProductVariantInterface::class);

        $subscription = new Subscription();
        self::assertSame(SubscriptionStatus::WAITING, $subscription->getStatus());
        self::assertTrue($subscription->isActive());

        $subscription->markNotified($now, $variant, $until);
        self::assertSame(SubscriptionStatus::NOTIFIED, $subscription->getStatus());
        self::assertSame($now, $subscription->getNotifiedAt());
        self::assertSame($variant, $subscription->getHeldVariant());
        self::assertSame($until, $subscription->getHoldUntil());
        self::assertTrue($subscription->isActive());

        $subscription->releaseHold();
        self::assertNull($subscription->getHoldUntil());
        self::assertNull($subscription->getHeldVariant());
        self::assertSame(SubscriptionStatus::NOTIFIED, $subscription->getStatus());

        $subscription->markWaiting();
        self::assertSame(SubscriptionStatus::WAITING, $subscription->getStatus());
        self::assertNull($subscription->getNotifiedAt());
        self::assertNull($subscription->getHoldUntil());
        self::assertNull($subscription->getHeldVariant());

        $subscription->markNotified($now, $variant, $until);
        $subscription->markFulfilled($now);
        self::assertSame(SubscriptionStatus::FULFILLED, $subscription->getStatus());
        self::assertFalse($subscription->isActive());
        self::assertNull($subscription->getHoldUntil());

        $other = new Subscription();
        $other->markNotified($now, $variant, $until);
        $other->markCancelled($now);
        self::assertSame(SubscriptionStatus::CANCELLED, $other->getStatus());
        self::assertFalse($other->isActive());
        self::assertNull($other->getHoldUntil());
    }

    /**
     * @dataProvider illegalTransitions
     */
    public function testIllegalTransitionsThrow(string $from, string $transition): void
    {
        $now = new \DateTimeImmutable('2026-10-30 10:00:00');
        $subscription = new Subscription();
        match ($from) {
            SubscriptionStatus::NOTIFIED => $subscription->markNotified($now, null, null),
            SubscriptionStatus::FULFILLED => $subscription->markFulfilled($now),
            SubscriptionStatus::CANCELLED => $subscription->markCancelled($now),
            default => null,
        };

        $this->expectException(\LogicException::class);
        match ($transition) {
            'markNotified' => $subscription->markNotified($now, null, null),
            'markWaiting' => $subscription->markWaiting(),
            'markFulfilled' => $subscription->markFulfilled($now),
            'markCancelled' => $subscription->markCancelled($now),
            default => throw new \InvalidArgumentException($transition),
        };
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function illegalTransitions(): iterable
    {
        yield 'notify a notified one' => [SubscriptionStatus::NOTIFIED, 'markNotified'];
        yield 'notify a fulfilled one' => [SubscriptionStatus::FULFILLED, 'markNotified'];
        yield 'notify a cancelled one' => [SubscriptionStatus::CANCELLED, 'markNotified'];
        yield 'wait a waiting one' => [SubscriptionStatus::WAITING, 'markWaiting'];
        yield 'wait a fulfilled one' => [SubscriptionStatus::FULFILLED, 'markWaiting'];
        yield 'wait a cancelled one' => [SubscriptionStatus::CANCELLED, 'markWaiting'];
        yield 'fulfil a fulfilled one' => [SubscriptionStatus::FULFILLED, 'markFulfilled'];
        yield 'fulfil a cancelled one' => [SubscriptionStatus::CANCELLED, 'markFulfilled'];
        yield 'cancel a fulfilled one' => [SubscriptionStatus::FULFILLED, 'markCancelled'];
        yield 'cancel a cancelled one' => [SubscriptionStatus::CANCELLED, 'markCancelled'];
    }

    public function testRestoreAfterCancelKeepsCreatedAt(): void
    {
        $createdAt = new \DateTime('2026-10-01 08:00:00');
        $subscription = new Subscription();
        $subscription->setCreatedAt($createdAt);
        $subscription->markCancelled(new \DateTimeImmutable('2026-10-30 10:00:00'));

        $subscription->restore();

        self::assertSame(SubscriptionStatus::WAITING, $subscription->getStatus());
        self::assertSame($createdAt, $subscription->getCreatedAt());
        self::assertTrue($subscription->isActive());
    }

    public function testRestoreOnlyAllowedAfterCancel(): void
    {
        $this->expectException(\LogicException::class);

        (new Subscription())->restore();
    }

    public function testDismissOnlyAllowedOnFulfilled(): void
    {
        $now = new \DateTimeImmutable('2026-10-30 10:00:00');
        $subscription = new Subscription();
        self::assertFalse($subscription->isDismissed());

        $subscription->markFulfilled($now);
        $subscription->dismiss($now);
        self::assertTrue($subscription->isDismissed());

        $this->expectException(\LogicException::class);
        (new Subscription())->dismiss($now);
    }
}
