<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Processor;

enum ProcessResult: string
{
    case Created = 'created';
    case AlreadySubscribed = 'already_subscribed';
    case NotPreorderable = 'not_preorderable';
    case RateLimited = 'rate_limited';
}
