<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Manage;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Mailer\SafeSender;
use Webgriffe\SyliusBackInStockNotificationPlugin\Manage\ManageLinkGenerator;
use Webgriffe\SyliusBackInStockNotificationPlugin\Manage\ManageLinkMailer;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;

final class ManageLinkMailerTest extends TestCase
{
    public function testNothingIsLookedUpOrSentBeforeTerminate(): void
    {
        $repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $repository->expects(self::never())->method('findActiveByEmail');
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::never())->method('send');

        $this->mailer($repository, $sender)->defer('a@b.hu', 'a@b.hu', $this->createMock(ChannelInterface::class), 'hu_HU');
    }

    public function testSendsOnTerminateOnlyWhenTheAddressHasActivePreOrders(): void
    {
        $channel = $this->createMock(ChannelInterface::class);
        $repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $repository->method('findActiveByEmail')->willReturnCallback(
            fn (string $email): array => 'a@b.hu' === $email ? [$this->createMock(SubscriptionInterface::class)] : [],
        );
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::once())->method('send')->with(
            'webgriffe_back_in_stock_notification_manage_link',
            ['A@b.hu'],
            self::callback(static fn (array $data): bool => is_string($data['manageUrl']) && str_contains($data['manageUrl'], '_hash=') && 'hu_HU' === $data['localeCode']),
        );

        $mailer = $this->mailer($repository, $sender);
        $mailer->defer('nobody@b.hu', 'nobody@b.hu', $channel, 'hu_HU');
        $mailer->defer('A@b.hu', 'a@b.hu', $channel, 'hu_HU');
        $mailer->onTerminate();
        $mailer->onTerminate();
    }

    public function testFailureIsLoggedAndTheNextRequestStillGoesOut(): void
    {
        $channel = $this->createMock(ChannelInterface::class);
        $repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $repository->method('findActiveByEmail')->willReturnCallback(
            static fn (string $email): array => 'broken@b.hu' === $email
                ? throw new \RuntimeException('db down')
                : [new \stdClass()],
        );
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::once())->method('send');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('webgriffe_bisn.manage_link_failed', self::anything());

        $mailer = $this->mailer($repository, $sender, $logger);
        $mailer->defer('broken@b.hu', 'broken@b.hu', $channel, 'hu_HU');
        $mailer->defer('a@b.hu', 'a@b.hu', $channel, 'hu_HU');
        $mailer->onTerminate();
    }

    private function mailer(SubscriptionRepositoryInterface $repository, SenderInterface $sender, ?LoggerInterface $logger = null): ManageLinkMailer
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('https://shop.test/hu/manage');

        return new ManageLinkMailer(
            $repository,
            new ManageLinkGenerator($router, new UriSigner('secret')),
            new SafeSender($sender, new NullLogger()),
            $logger ?? new NullLogger(),
        );
    }
}
