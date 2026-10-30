<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\TestApplication\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\Channel as BaseChannel;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderChannelSettingsInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderChannelSettingsTrait;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_channel')]
class Channel extends BaseChannel implements PreorderChannelSettingsInterface
{
    use PreorderChannelSettingsTrait;
}
