<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Mailer\SafeSender;

final class SafeSenderTest extends TestCase
{
    public function testMailerExceptionIsLoggedAndReturnsFalse(): void
    {
        $sender = $this->createMock(SenderInterface::class);
        $sender->method('send')->willThrowException(new \RuntimeException('smtp down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'webgriffe_bisn.mail_failed',
            self::callback(static fn (array $context): bool => 'some_code' === $context['code'] && 'a@b.hu' === $context['recipient']),
        );

        $result = (new SafeSender($sender, $logger))->send('some_code', 'a@b.hu', [], $this->createMock(ChannelInterface::class), 'hu_HU');

        self::assertFalse($result);
    }

    public function testSuccessfulSendPassesChannelAndLocaleInData(): void
    {
        $channel = $this->createMock(ChannelInterface::class);
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::once())->method('send')->with(
            'some_code',
            ['a@b.hu'],
            ['x' => 1, 'channel' => $channel, 'localeCode' => 'hu_HU'],
        );

        $result = (new SafeSender($sender, $this->createMock(LoggerInterface::class)))->send('some_code', 'a@b.hu', ['x' => 1], $channel, 'hu_HU');

        self::assertTrue($result);
    }
}
