<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;
use Webgriffe\SyliusBackInStockNotificationPlugin\Preorderability\Preorderability;

trait PreorderabilityTrait
{
    #[ORM\Column(name: 'preorderability', type: 'string', length: 16, enumType: Preorderability::class, options: ['default' => 'inherit'])]
    protected Preorderability $preorderability = Preorderability::Inherit;

    public function getPreorderability(): Preorderability
    {
        return $this->preorderability;
    }

    public function setPreorderability(Preorderability $preorderability): void
    {
        $this->preorderability = $preorderability;
    }
}
