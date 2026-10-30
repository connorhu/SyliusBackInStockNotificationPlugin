<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Entity;

final class SubscriptionStatus
{
    public const WAITING = 'waiting';

    public const NOTIFIED = 'notified';

    public const FULFILLED = 'fulfilled';

    public const CANCELLED = 'cancelled';

    /** Statuses of a subscription that still expects something to happen. */
    public const ACTIVE = [self::WAITING, self::NOTIFIED];
}
