<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Restock;

use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Model\ChannelInterface as BaseChannelInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Inventory\Checker\AvailabilityChecker;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderChannelSettingsInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderChannelSettingsTrait;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\Subscription;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionStatus;
use Webgriffe\SyliusBackInStockNotificationPlugin\Mailer\SafeSender;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Restock\RestockProcessor;
use Webgriffe\SyliusBackInStockNotificationPlugin\Restock\RestockReport;
use Webgriffe\SyliusBackInStockNotificationPlugin\Restock\SellableUnits;
use Webgriffe\SyliusBackInStockNotificationPlugin\Target\TargetResolver;

final class RestockProcessorTest extends TestCase
{
    private const ALERT = 'webgriffe_back_in_stock_notification_alert';

    private const HOLD_EXPIRED = 'webgriffe_back_in_stock_notification_hold_expired';

    private \DateTimeImmutable $now;

    /** @var list<SubscriptionInterface> */
    private array $subscriptions = [];

    /** @var list<string> */
    private array $events = [];

    /** @var list<array{code: string, recipient: string, data: array<string, mixed>, host: string}> */
    private array $mails = [];

    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    private array $logs = [];

    private ?\Throwable $flushFailure = null;

    /** @var \Closure(ProductInterface): void|null */
    private ?\Closure $targetLookupHook = null;

    private RequestContext $routerContext;

    private ObjectManager&MockObject $manager;

    private RestockProcessor $processor;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-30 12:00:00');
        $this->routerContext = new RequestContext(host: 'cli.local');

        $repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $repository->method('findRestockTargets')->willReturnCallback(fn (): array => $this->representatives());
        $repository->method('findActiveByTarget')->willReturnCallback(
            function (ProductInterface $product, ?ProductVariantInterface $variant, BaseChannelInterface $channel): array {
                if (null !== $this->targetLookupHook) {
                    ($this->targetLookupHook)($product);
                }

                // Deliberately returns every row of the target, whatever its status: the processor must ignore inactive ones.
                return array_values(array_filter(
                    $this->subscriptions,
                    static fn (SubscriptionInterface $s): bool => $s->getProduct() === $product && $s->getProductVariant() === $variant && $s->getChannel() === $channel,
                ));
            },
        );

        $this->manager = $this->createMock(ObjectManager::class);
        $this->manager->method('flush')->willReturnCallback(function (): void {
            $this->events[] = 'flush';
            if (null !== $this->flushFailure) {
                throw $this->flushFailure;
            }
        });

        $sender = $this->createMock(SenderInterface::class);
        $sender->method('send')->willReturnCallback(function (string $code, array $recipients, array $data): void {
            $this->events[] = 'mail';
            $this->mails[] = ['code' => $code, 'recipient' => (string) $recipients[0], 'data' => $data, 'host' => $this->routerContext->getHost()];
        });

        $router = $this->createMock(RouterInterface::class);
        $router->method('getContext')->willReturn($this->routerContext);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
            $this->logs[] = ['level' => 'error', 'message' => $message, 'context' => $context];
        });

        $this->processor = new RestockProcessor(
            $repository,
            $this->manager,
            new TargetResolver('product', $repository),
            new SellableUnits(new AvailabilityChecker()),
            new SafeSender($sender, $logger),
            $router,
            $logger,
        );
    }

    public function testFastestNotifiesEveryoneWithoutHold(): void
    {
        $channel = $this->channel(PreorderChannelSettingsInterface::MODE_FASTEST);
        $product = $this->product($channel);
        $this->variant($product, 1);
        $anna = $this->subscription($product, $channel, 'anna@example.com', '-3 days');
        $bela = $this->subscription($product, $channel, 'bela@example.com', '-2 days');

        $report = $this->processor->process($this->now);

        foreach ([$anna, $bela] as $subscription) {
            self::assertSame(SubscriptionStatus::NOTIFIED, $subscription->getStatus());
            self::assertEquals($this->now, $subscription->getNotifiedAt());
            self::assertNull($subscription->getHeldVariant());
            self::assertNull($subscription->getHoldUntil());
        }
        self::assertSame(['anna@example.com', 'bela@example.com'], $this->recipients(self::ALERT));
        self::assertSame('fastest', $this->mails[0]['data']['mode']);
        self::assertNull($this->mails[0]['data']['holdUntil']);
        self::assertSame([2, 0, 0, 0], $this->counts($report));
    }

    public function testQueueNotifiesFirstAndHoldsForConfiguredHours(): void
    {
        $channel = $this->channel(PreorderChannelSettingsInterface::MODE_QUEUE, 48);
        $product = $this->product($channel);
        $variant = $this->variant($product, 1);
        $anna = $this->subscription($product, $channel, 'anna@example.com', '-3 days');
        $bela = $this->subscription($product, $channel, 'bela@example.com', '-2 days');

        $report = $this->processor->process($this->now);

        self::assertSame(SubscriptionStatus::NOTIFIED, $anna->getStatus());
        self::assertSame($variant, $anna->getHeldVariant());
        self::assertEquals($this->now->modify('+48 hours'), $anna->getHoldUntil());
        self::assertSame(SubscriptionStatus::WAITING, $bela->getStatus());
        self::assertSame(['anna@example.com'], $this->recipients(self::ALERT));
        self::assertSame('queue', $this->mails[0]['data']['mode']);
        self::assertEquals($this->now->modify('+48 hours'), $this->mails[0]['data']['holdUntil']);
        self::assertSame([1, 1, 0, 0], $this->counts($report));
    }

    public function testChannelWithoutSettingsUsesQueueAndDefaultHours(): void
    {
        $channel = new Channel();
        $channel->setCode('WEB');
        $product = $this->product($channel);
        $variant = $this->variant($product, 1);
        $anna = $this->subscription($product, $channel, 'anna@example.com', '-3 days');
        $bela = $this->subscription($product, $channel, 'bela@example.com', '-2 days');

        $this->processor->process($this->now);

        self::assertSame($variant, $anna->getHeldVariant());
        self::assertEquals($this->now->modify('+24 hours'), $anna->getHoldUntil());
        self::assertSame(SubscriptionStatus::WAITING, $bela->getStatus());
    }

    public function testTwoUnitsNotifyTwoInOrder(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel);
        $this->variant($product, 2);
        $cecil = $this->subscription($product, $channel, 'cecil@example.com', '-1 day');
        $anna = $this->subscription($product, $channel, 'anna@example.com', '-3 days');
        $bela = $this->subscription($product, $channel, 'bela@example.com', '-2 days');

        $report = $this->processor->process($this->now);

        self::assertSame(SubscriptionStatus::NOTIFIED, $anna->getStatus());
        self::assertSame(SubscriptionStatus::NOTIFIED, $bela->getStatus());
        self::assertSame(SubscriptionStatus::WAITING, $cecil->getStatus());
        self::assertSame(['anna@example.com', 'bela@example.com'], $this->recipients(self::ALERT));
        self::assertSame([2, 2, 0, 0], $this->counts($report));
    }

    public function testQueueHoldsOneUnitPerVariant(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel);
        $first = $this->variant($product, 1);
        $second = $this->variant($product, 1);
        $anna = $this->subscription($product, $channel, 'anna@example.com', '-3 days');
        $bela = $this->subscription($product, $channel, 'bela@example.com', '-2 days');

        $this->processor->process($this->now);

        self::assertSame($first, $anna->getHeldVariant());
        self::assertSame($second, $bela->getHeldVariant());
    }

    public function testActiveHoldKeepsItsUnit(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel);
        $variant = $this->variant($product, 1);
        $anna = $this->subscription($product, $channel, 'anna@example.com', '-3 days');
        $anna->markNotified($this->now->modify('-1 hour'), $variant, $this->now->modify('+23 hours'));
        $bela = $this->subscription($product, $channel, 'bela@example.com', '-2 days');

        $report = $this->processor->process($this->now);

        self::assertSame($variant, $anna->getHeldVariant());
        self::assertSame(SubscriptionStatus::WAITING, $bela->getStatus());
        self::assertSame([], $this->mails);
        self::assertSame([0, 0, 0, 0], $this->counts($report));
    }

    public function testExpiredHoldPassesWithFreshDeadline(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel);
        $variant = $this->variant($product, 1);
        $anna = $this->subscription($product, $channel, 'anna@example.com', '-10 days');
        $anna->markNotified($this->now->modify('-4 days'), $variant, $this->now->modify('-3 days'));
        $bela = $this->subscription($product, $channel, 'bela@example.com', '-9 days');

        $report = $this->processor->process($this->now);

        self::assertSame(SubscriptionStatus::NOTIFIED, $anna->getStatus());
        self::assertNull($anna->getHeldVariant());
        self::assertNull($anna->getHoldUntil());
        self::assertSame(SubscriptionStatus::NOTIFIED, $bela->getStatus());
        self::assertSame($variant, $bela->getHeldVariant());
        self::assertEquals($this->now->modify('+24 hours'), $bela->getHoldUntil());
        self::assertSame(['anna@example.com'], $this->recipients(self::HOLD_EXPIRED));
        self::assertTrue($this->mailTo(self::HOLD_EXPIRED, 'anna@example.com')['sellable']);
        self::assertSame(['bela@example.com'], $this->recipients(self::ALERT));
        self::assertSame([1, 1, 1, 0], $this->counts($report));
    }

    public function testLastExpiredHoldFreesUnit(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel);
        $variant = $this->variant($product, 1);
        $anna = $this->subscription($product, $channel, 'anna@example.com', '-10 days');
        $anna->markNotified($this->now->modify('-2 days'), $variant, $this->now->modify('-1 day'));

        $report = $this->processor->process($this->now);

        self::assertSame(SubscriptionStatus::NOTIFIED, $anna->getStatus());
        self::assertNull($anna->getHeldVariant());
        self::assertSame(['anna@example.com'], $this->recipients(self::HOLD_EXPIRED));
        self::assertSame([], $this->recipients(self::ALERT));
        self::assertSame([0, 0, 1, 0], $this->counts($report));
    }

    public function testHoldExpiringExactlyNowExpires(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel);
        $variant = $this->variant($product, 1);
        $anna = $this->subscription($product, $channel, 'anna@example.com', '-10 days');
        $anna->markNotified($this->now->modify('-1 day'), $variant, $this->now);

        $this->processor->process($this->now);

        self::assertNull($anna->getHeldVariant());
    }

    public function testEarlierNotifiedStaysNotifiedWhileUnitIsHeldForTheNext(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel);
        $variant = $this->variant($product, 1);
        $anna = $this->subscription($product, $channel, 'anna@example.com', '-10 days');
        $anna->markNotified($this->now->modify('-2 days'), null, null);
        $bela = $this->subscription($product, $channel, 'bela@example.com', '-9 days');
        $bela->markNotified($this->now->modify('-1 hour'), $variant, $this->now->modify('+23 hours'));

        $report = $this->processor->process($this->now);

        // Anna may still buy the copy held for Bela, so she stays notified.
        self::assertSame(SubscriptionStatus::NOTIFIED, $anna->getStatus());
        self::assertSame([0, 0, 0, 0], $this->counts($report));
    }

    public function testNotifiedGoesBackToWaitingWhenSoldOutAgain(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel);
        $variant = $this->variant($product, 0);
        $anna = $this->subscription($product, $channel, 'anna@example.com', '-10 days');
        $anna->markNotified($this->now->modify('-2 days'), $variant, $this->now->modify('-1 day'));
        $bela = $this->subscription($product, $channel, 'bela@example.com', '-9 days');
        $bela->markNotified($this->now->modify('-2 days'), null, null);

        $report = $this->processor->process($this->now);

        foreach ([$anna, $bela] as $subscription) {
            self::assertSame(SubscriptionStatus::WAITING, $subscription->getStatus());
            self::assertNull($subscription->getNotifiedAt());
        }
        self::assertEquals($this->now->modify('-10 days'), $anna->getCreatedAt());
        self::assertSame(['anna@example.com'], $this->recipients(self::HOLD_EXPIRED));
        self::assertFalse($this->mailTo(self::HOLD_EXPIRED, 'anna@example.com')['sellable']);
        self::assertSame([], $this->recipients(self::ALERT));
        self::assertSame([0, 0, 1, 2], $this->counts($report));
    }

    public function testDisabledOrUnpricedVariantIsNotSellable(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel);
        $disabled = $this->variant($product, 3);
        $disabled->setEnabled(false);
        $this->variant($product, 3, null);
        $anna = $this->subscription($product, $channel, 'anna@example.com', '-3 days');

        $this->processor->process($this->now);

        self::assertSame(SubscriptionStatus::WAITING, $anna->getStatus());
        self::assertSame([], $this->mails);
    }

    public function testOtherChannelStockIsIgnored(): void
    {
        $web = $this->channel();
        $other = $this->channel(code: 'OTHER');
        $product = $this->product($web);
        $this->variant($product, 3);
        $anna = $this->subscription($product, $other, 'anna@example.com', '-3 days');

        $this->processor->process($this->now);

        self::assertSame(SubscriptionStatus::WAITING, $anna->getStatus());
        self::assertSame([], $this->mails);
    }

    public function testCancelledAndFulfilledAreIgnored(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel);
        $variant = $this->variant($product, 1);
        $cancelled = $this->subscription($product, $channel, 'anna@example.com', '-5 days');
        $cancelled->markCancelled($this->now->modify('-1 day'));
        $fulfilled = $this->subscription($product, $channel, 'bela@example.com', '-4 days');
        $fulfilled->markNotified($this->now->modify('-3 days'), $variant, $this->now->modify('-2 days'));
        $fulfilled->markFulfilled($this->now->modify('-2 days'));
        $cecil = $this->subscription($product, $channel, 'cecil@example.com', '-3 days');

        $report = $this->processor->process($this->now);

        self::assertSame(SubscriptionStatus::CANCELLED, $cancelled->getStatus());
        self::assertSame(SubscriptionStatus::FULFILLED, $fulfilled->getStatus());
        self::assertSame(SubscriptionStatus::NOTIFIED, $cecil->getStatus());
        self::assertSame(['cecil@example.com'], $this->recipients(self::ALERT));
        self::assertSame([1, 1, 0, 0], $this->counts($report));
    }

    public function testStateIsFlushedBeforeMailsAreSent(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel);
        $this->variant($product, 1);
        $this->subscription($product, $channel, 'anna@example.com', '-3 days');

        $this->processor->process($this->now);

        self::assertSame(['flush', 'mail'], $this->events);
    }

    public function testNothingIsFlushedForAnUnchangedTarget(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel);
        $this->variant($product, 0);
        $this->subscription($product, $channel, 'anna@example.com', '-3 days');

        $this->processor->process($this->now);

        self::assertSame([], $this->events);
    }

    public function testFlushFailureStopsTheRunWithoutMails(): void
    {
        $channel = $this->channel();
        $first = $this->product($channel);
        $this->variant($first, 1);
        $this->subscription($first, $channel, 'anna@example.com', '-3 days');
        $second = $this->product($channel);
        $this->variant($second, 1);
        $bela = $this->subscription($second, $channel, 'bela@example.com', '-3 days');
        $this->flushFailure = new \RuntimeException('Database is gone');

        $report = $this->processor->process($this->now);

        self::assertTrue($report->isAborted());
        self::assertSame([], $this->mails);
        self::assertSame(['flush'], $this->events);
        self::assertSame(SubscriptionStatus::WAITING, $bela->getStatus());
        self::assertSame(['webgriffe_bisn.restock.flush_failed'], array_column($this->logs, 'message'));
    }

    public function testFailingTargetIsLoggedAndTheNextOneIsProcessed(): void
    {
        $channel = $this->channel();
        $broken = $this->product($channel);
        $this->variant($broken, 1);
        $anna = $this->subscription($broken, $channel, 'anna@example.com', '-3 days');
        $working = $this->product($channel);
        $this->variant($working, 1);
        $bela = $this->subscription($working, $channel, 'bela@example.com', '-3 days');
        $this->targetLookupHook = static function (ProductInterface $product) use ($broken): void {
            if ($product === $broken) {
                throw new \RuntimeException('Broken target');
            }
        };

        $report = $this->processor->process($this->now);

        self::assertSame(SubscriptionStatus::WAITING, $anna->getStatus());
        self::assertSame(SubscriptionStatus::NOTIFIED, $bela->getStatus());
        self::assertSame(1, $report->getFailedTargets());
        self::assertFalse($report->isAborted());
        self::assertSame(['webgriffe_bisn.restock.target_failed'], array_column($this->logs, 'message'));
    }

    public function testRouterHostFollowsTheChannelAndIsRestored(): void
    {
        $channel = $this->channel();
        $channel->setHostname('shop.example');
        $product = $this->product($channel);
        $this->variant($product, 1);
        $this->subscription($product, $channel, 'anna@example.com', '-3 days');

        $this->processor->process($this->now);

        self::assertSame('shop.example', $this->mails[0]['host']);
        self::assertSame('cli.local', $this->routerContext->getHost());
    }

    public function testUntrackedVariantNotifiesTheWholeQueue(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel);
        $this->variant($product, 0)->setTracked(false);
        $anna = $this->subscription($product, $channel, 'anna@example.com', '-3 days');
        $bela = $this->subscription($product, $channel, 'bela@example.com', '-2 days');

        $this->processor->process($this->now);

        self::assertSame(SubscriptionStatus::NOTIFIED, $anna->getStatus());
        self::assertSame(SubscriptionStatus::NOTIFIED, $bela->getStatus());
    }

    /** @return list<SubscriptionInterface> */
    private function representatives(): array
    {
        $seen = [];
        $result = [];
        foreach ($this->subscriptions as $subscription) {
            $key = spl_object_id($subscription->getProduct() ?? $subscription) . '-' . spl_object_id($subscription->getChannel() ?? $subscription);
            if ($subscription->isActive() && !isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $subscription;
            }
        }

        return $result;
    }

    private function channel(?string $mode = null, ?int $holdHours = null, string $code = 'WEB'): Channel
    {
        $channel = new class() extends Channel implements PreorderChannelSettingsInterface {
            use PreorderChannelSettingsTrait;
        };
        $channel->setCode($code);
        if (null !== $mode) {
            $channel->setPreorderMode($mode);
        }
        if (null !== $holdHours) {
            $channel->setPreorderHoldHours($holdHours);
        }

        return $channel;
    }

    private function product(Channel $channel): Product
    {
        $product = new Product();
        $product->setCode('BOOK-' . spl_object_id($product));
        $product->addChannel($channel);

        return $product;
    }

    private function variant(Product $product, int $onHand, ?int $price = 1000): ProductVariant
    {
        $variant = new ProductVariant();
        $variant->setCode('COPY-' . spl_object_id($variant));
        $variant->setTracked(true);
        $variant->setOnHand($onHand);
        $product->addVariant($variant);
        foreach ($product->getChannels() as $channel) {
            $pricing = new ChannelPricing();
            $pricing->setChannelCode($channel->getCode());
            $pricing->setPrice($price);
            $variant->addChannelPricing($pricing);
        }

        return $variant;
    }

    private function subscription(Product $product, Channel $channel, string $email, string $createdAgo): Subscription
    {
        $subscription = new Subscription();
        $subscription->setProduct($product);
        $subscription->setChannel($channel);
        $subscription->setEmail($email);
        $subscription->setLocaleCode('en_US');
        $subscription->setCreatedAt($this->now->modify($createdAgo));
        $this->subscriptions[] = $subscription;

        return $subscription;
    }

    /** @return list<string> */
    private function recipients(string $code): array
    {
        return array_values(array_map(
            static fn (array $mail): string => $mail['recipient'],
            array_filter($this->mails, static fn (array $mail): bool => $mail['code'] === $code),
        ));
    }

    /** @return array<string, mixed> */
    private function mailTo(string $code, string $recipient): array
    {
        foreach ($this->mails as $mail) {
            if ($mail['code'] === $code && $mail['recipient'] === $recipient) {
                return $mail['data'];
            }
        }

        self::fail(sprintf('No %s mail to %s.', $code, $recipient));
    }

    /** @return array{int, int, int, int} notified, held, expired, rewaited */
    private function counts(RestockReport $report): array
    {
        return [$report->getNotified(), $report->getHeld(), $report->getExpired(), $report->getRewaited()];
    }
}
