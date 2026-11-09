<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Processor;

enum ProcessResult: string
{
    case Created = 'created';
    case AlreadySubscribed = 'already_subscribed';
    case NotPreorderable = 'not_preorderable';
    case RateLimited = 'rate_limited';

    /**
     * The form error to show for this outcome, or null for the success state. A guest never learns that an address
     * already has a pre-order: for them a duplicate looks exactly like a created one.
     */
    public function errorKey(bool $isGuest): ?string
    {
        return match ($this) {
            self::Created => null,
            self::AlreadySubscribed => $isGuest ? null : 'webgriffe_bisn.subscription.already_saved',
            self::NotPreorderable => 'webgriffe_bisn.subscription.not_preorderable',
            self::RateLimited => 'webgriffe_bisn.form_submission.rate_limited',
        };
    }
}
