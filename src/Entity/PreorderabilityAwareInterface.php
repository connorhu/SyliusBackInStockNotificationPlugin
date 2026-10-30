<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Entity;

use Webgriffe\SyliusBackInStockNotificationPlugin\Preorderability\Preorderability;

/**
 * Implemented by the host's Product and Taxon entities (see ProductPreorderabilityTrait and TaxonPreorderabilityTrait).
 */
interface PreorderabilityAwareInterface
{
    public function getPreorderability(): Preorderability;

    public function setPreorderability(Preorderability $preorderability): void;
}
