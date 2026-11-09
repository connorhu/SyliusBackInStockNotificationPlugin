<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Preorderability;

enum Preorderability: string
{
    case Inherit = 'inherit';
    case Allowed = 'allowed';
    case Forbidden = 'forbidden';
}
