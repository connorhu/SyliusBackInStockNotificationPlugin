<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\RateLimit;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderChannelSettingsInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderChannelSettingsTrait;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\Subscription;
use Webgriffe\SyliusBackInStockNotificationPlugin\RateLimit\PreorderRateLimiter;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;

final class PreorderRateLimiterTest extends TestCase
{
    private SubscriptionRepositoryInterface&MockObject $repository;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $this->now = new \DateTimeImmutable('2026-10-30 12:00:00');
    }

    public function testSecondPreorderWithin120SecondsIsRefused(): void
    {
        $this->newestCreatedAt('-119 seconds');

        self::assertFalse($this->limiter()->allow('anna@pelda.hu', null, '1.2.3.4', $this->channel(120), $this->now));
    }

    public function testAllowedAfterInterval(): void
    {
        $this->newestCreatedAt('-120 seconds');

        self::assertTrue($this->limiter()->allow('anna@pelda.hu', null, '1.2.3.4', $this->channel(120), $this->now));
    }

    public function testAllowedWhenThereIsNoEarlierPreorder(): void
    {
        $this->repository->method('findNewestByEmailOrCustomer')->willReturn(null);

        self::assertTrue($this->limiter()->allow('anna@pelda.hu', null, '1.2.3.4', $this->channel(120), $this->now));
    }

    public function testZeroIntervalDisablesEmailCheck(): void
    {
        $this->repository->expects(self::never())->method('findNewestByEmailOrCustomer');

        self::assertTrue($this->limiter()->allow('anna@pelda.hu', null, '1.2.3.4', $this->channel(0), $this->now));
    }

    public function testIpLimitRefusesEleventhWithinHour(): void
    {
        $this->repository->method('findNewestByEmailOrCustomer')->willReturn(null);
        $limiter = $this->limiter();
        $channel = $this->channel(120);

        for ($i = 0; $i < 10; ++$i) {
            self::assertTrue($limiter->allow('anna' . $i . '@pelda.hu', null, '1.2.3.4', $channel, $this->now));
        }
        self::assertFalse($limiter->allow('anna11@pelda.hu', null, '1.2.3.4', $channel, $this->now));
        // Another address on the network is not affected.
        self::assertTrue($limiter->allow('anna12@pelda.hu', null, '5.6.7.8', $channel, $this->now));
    }

    public function testRefusedByEmailDoesNotConsumeIpToken(): void
    {
        $this->newestCreatedAt('-10 seconds');
        $limiter = $this->limiter();
        $channel = $this->channel(120);

        for ($i = 0; $i < 20; ++$i) {
            self::assertFalse($limiter->allow('anna@pelda.hu', null, '1.2.3.4', $channel, $this->now));
        }

        // The twenty refusals left the whole allowance of the IP untouched.
        $fresh = $this->createMock(SubscriptionRepositoryInterface::class);
        $fresh->method('findNewestByEmailOrCustomer')->willReturn(null);
        $second = new PreorderRateLimiter($fresh, $this->ipFactory());
        for ($i = 0; $i < 10; ++$i) {
            self::assertTrue($second->allow('other' . $i . '@pelda.hu', null, '1.2.3.4', $channel, $this->now));
        }
    }

    public function testChannelWithoutSettingsUsesDefaultInterval(): void
    {
        $this->newestCreatedAt('-60 seconds');

        self::assertFalse($this->limiter()->allow('anna@pelda.hu', null, null, new Channel(), $this->now));
    }

    public function testMissingIpSkipsIpLimit(): void
    {
        $this->repository->method('findNewestByEmailOrCustomer')->willReturn(null);

        for ($i = 0; $i < 12; ++$i) {
            self::assertTrue($this->limiter()->allow('anna@pelda.hu', null, null, $this->channel(120), $this->now));
        }
    }

    private ?RateLimiterFactory $factory = null;

    private function ipFactory(): RateLimiterFactory
    {
        return $this->factory ??= new RateLimiterFactory(
            ['id' => 'test_ip', 'policy' => 'sliding_window', 'limit' => 10, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
    }

    private function limiter(): PreorderRateLimiter
    {
        return new PreorderRateLimiter($this->repository, $this->ipFactory());
    }

    private function newestCreatedAt(string $modifier): void
    {
        $subscription = new Subscription();
        $subscription->setCreatedAt($this->now->modify($modifier));
        $this->repository->method('findNewestByEmailOrCustomer')->willReturn($subscription);
    }

    private function channel(int $seconds): Channel&PreorderChannelSettingsInterface
    {
        $channel = new class() extends Channel implements PreorderChannelSettingsInterface {
            use PreorderChannelSettingsTrait;
        };
        $channel->setPreorderRateLimitSeconds($seconds);

        return $channel;
    }
}
