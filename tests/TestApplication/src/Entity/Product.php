<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\TestApplication\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\Product as BaseProduct;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderabilityAwareInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\ProductPreorderabilityTrait;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_product')]
class Product extends BaseProduct implements PreorderabilityAwareInterface
{
    use ProductPreorderabilityTrait;
}
