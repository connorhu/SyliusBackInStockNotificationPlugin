<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Mailer;

use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;

/**
 * Sends a Sylius e-mail without ever throwing: a mailer outage must not break the pre-order flow.
 */
final class SafeSender
{
    public function __construct(
        private SenderInterface $sender,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function send(string $code, string $recipient, array $data, ChannelInterface $channel, string $localeCode): bool
    {
        try {
            /** @psalm-suppress DeprecatedMethod */
            $this->sender->send(
                $code,
                [$recipient],
                $data + ['channel' => $channel, 'localeCode' => $localeCode],
                [],
                [],
            );
        } catch (\Throwable $exception) {
            $this->logger->error('webgriffe_bisn.mail_failed', [
                'code' => $code,
                'recipient' => $recipient,
                'exception' => $exception,
            ]);

            return false;
        }

        return true;
    }
}
