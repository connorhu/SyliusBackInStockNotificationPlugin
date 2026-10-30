<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\TestApplication\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\Taxon as BaseTaxon;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderabilityAwareInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\TaxonPreorderabilityTrait;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_taxon')]
class Taxon extends BaseTaxon implements PreorderabilityAwareInterface
{
    use TaxonPreorderabilityTrait;
}
