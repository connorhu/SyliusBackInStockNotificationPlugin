<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Entity;

/**
 * Use in the host's Product entity (which must implement PreorderabilityAwareInterface).
 */
trait ProductPreorderabilityTrait
{
    use PreorderabilityTrait;
}
