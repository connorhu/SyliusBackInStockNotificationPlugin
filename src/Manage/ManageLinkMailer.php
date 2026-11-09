<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Manage;

use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Model\ChannelInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Mailer\SafeSender;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;

/**
 * Sends the "new manage link" mails after the response has been sent (kernel.terminate), so the time the new-link
 * request takes never depends on whether the address has pre-orders. The lookup happens here too.
 */
final class ManageLinkMailer
{
    /** @var list<array{email: string, emailCanonical: string, channel: ChannelInterface, localeCode: string}> */
    private array $pending = [];

    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private ManageLinkGenerator $linkGenerator,
        private SafeSender $sender,
        private LoggerInterface $logger,
    ) {
    }

    public function defer(string $email, string $emailCanonical, ChannelInterface $channel, string $localeCode): void
    {
        $this->pending[] = ['email' => $email, 'emailCanonical' => $emailCanonical, 'channel' => $channel, 'localeCode' => $localeCode];
    }

    public function onTerminate(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $request) {
            // Runs after the response: one failing request must neither escape kernel.terminate nor stop the others.
            try {
                if ([] === $this->subscriptionRepository->findActiveByEmail($request['emailCanonical'], $request['channel'])) {
                    continue;
                }

                $this->sender->send(
                    'webgriffe_back_in_stock_notification_manage_link',
                    $request['email'],
                    [
                        'manageUrl' => $this->linkGenerator->generate($request['emailCanonical'], new \DateTimeImmutable(), $request['localeCode']),
                        'ttlHours' => 24,
                    ],
                    $request['channel'],
                    $request['localeCode'],
                );
            } catch (\Throwable $exception) {
                $this->logger->error('webgriffe_bisn.manage_link_failed', ['exception' => $exception]);
            }
        }
    }
}
