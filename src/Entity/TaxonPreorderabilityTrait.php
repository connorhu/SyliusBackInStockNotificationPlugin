<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Entity;

/**
 * Use in the host's Taxon entity (which must implement PreorderabilityAwareInterface).
 */
trait TaxonPreorderabilityTrait
{
    use PreorderabilityTrait;
}
