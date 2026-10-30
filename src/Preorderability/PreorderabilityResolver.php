<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Preorderability;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderabilityAwareInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderChannelSettingsInterface;

final class PreorderabilityResolver implements PreorderabilityResolverInterface
{
    public function isPreorderable(ProductInterface $product, ChannelInterface $channel): bool
    {
        $own = $this->settingOf($product);
        if (Preorderability::Inherit !== $own) {
            return Preorderability::Allowed === $own;
        }

        $allowed = false;
        foreach ($product->getTaxons() as $taxon) {
            $setting = $this->nearestSetting($taxon);
            if (Preorderability::Forbidden === $setting) {
                return false;
            }
            $allowed = $allowed || Preorderability::Allowed === $setting;
        }
        if ($allowed) {
            return true;
        }

        return $channel instanceof PreorderChannelSettingsInterface
            ? $channel->isPreorderEnabledByDefault()
            : PreorderChannelSettingsInterface::DEFAULT_ENABLED_BY_DEFAULT;
    }

    private function nearestSetting(TaxonInterface $taxon): Preorderability
    {
        $seen = [];
        for ($current = $taxon; null !== $current && !isset($seen[spl_object_id($current)]); $current = $current->getParent()) {
            $seen[spl_object_id($current)] = true;
            $setting = $this->settingOf($current);
            if (Preorderability::Inherit !== $setting) {
                return $setting;
            }
        }

        return Preorderability::Inherit;
    }

    private function settingOf(object $entity): Preorderability
    {
        return $entity instanceof PreorderabilityAwareInterface ? $entity->getPreorderability() : Preorderability::Inherit;
    }
}
