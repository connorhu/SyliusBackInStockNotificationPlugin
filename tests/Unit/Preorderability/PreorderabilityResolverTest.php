<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Preorderability;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductTaxon;
use Sylius\Component\Core\Model\Taxon;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderabilityAwareInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderabilityTrait;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderChannelSettingsInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\PreorderChannelSettingsTrait;
use Webgriffe\SyliusBackInStockNotificationPlugin\Preorderability\Preorderability;
use Webgriffe\SyliusBackInStockNotificationPlugin\Preorderability\PreorderabilityResolver;

final class PreorderabilityResolverTest extends TestCase
{
    public function testProductAllowedBeatsForbiddenTaxon(): void
    {
        $product = $this->product(Preorderability::Allowed, [$this->taxon(Preorderability::Forbidden)]);

        self::assertTrue($this->resolver()->isPreorderable($product, $this->channel(false)));
    }

    public function testProductForbiddenBeatsChannelDefault(): void
    {
        $product = $this->product(Preorderability::Forbidden, [$this->taxon(Preorderability::Allowed)]);

        self::assertFalse($this->resolver()->isPreorderable($product, $this->channel(true)));
    }

    public function testInheritingProductUsesNearestTaxonAncestor(): void
    {
        $parent = $this->taxon(Preorderability::Forbidden);
        $child = $this->taxon(Preorderability::Inherit, $parent);

        self::assertFalse($this->resolver()->isPreorderable($this->product(Preorderability::Inherit, [$child]), $this->channel(true)));
    }

    public function testNearestAncestorBeatsFartherOne(): void
    {
        $grandParent = $this->taxon(Preorderability::Forbidden);
        $parent = $this->taxon(Preorderability::Allowed, $grandParent);
        $child = $this->taxon(Preorderability::Inherit, $parent);

        self::assertTrue($this->resolver()->isPreorderable($this->product(Preorderability::Inherit, [$child]), $this->channel(false)));
    }

    public function testAnyForbiddenTaxonWins(): void
    {
        $product = $this->product(Preorderability::Inherit, [$this->taxon(Preorderability::Allowed), $this->taxon(Preorderability::Forbidden)]);

        self::assertFalse($this->resolver()->isPreorderable($product, $this->channel(true)));
    }

    public function testAllowedTaxonBeatsChannelDefault(): void
    {
        $product = $this->product(Preorderability::Inherit, [$this->taxon(Preorderability::Inherit), $this->taxon(Preorderability::Allowed)]);

        self::assertTrue($this->resolver()->isPreorderable($product, $this->channel(false)));
    }

    public function testAllInheritUsesChannelDefault(): void
    {
        $product = $this->product(Preorderability::Inherit, [$this->taxon(Preorderability::Inherit, $this->taxon(Preorderability::Inherit))]);

        self::assertTrue($this->resolver()->isPreorderable($product, $this->channel(true)));
        self::assertFalse($this->resolver()->isPreorderable($product, $this->channel(false)));
    }

    public function testHostEntitiesWithoutInterfacesInherit(): void
    {
        $product = new Product();
        $taxon = new Taxon();
        $productTaxon = new ProductTaxon();
        $productTaxon->setTaxon($taxon);
        $product->addProductTaxon($productTaxon);

        self::assertTrue($this->resolver()->isPreorderable($product, new Channel()));
        self::assertTrue($this->resolver()->isPreorderable(new Product(), new Channel()));
    }

    private function resolver(): PreorderabilityResolver
    {
        return new PreorderabilityResolver();
    }

    private function channel(bool $enabledByDefault): Channel
    {
        $channel = new class() extends Channel implements PreorderChannelSettingsInterface {
            use PreorderChannelSettingsTrait;
        };
        $channel->setPreorderEnabledByDefault($enabledByDefault);

        return $channel;
    }

    /**
     * @param list<Taxon> $taxons
     */
    private function product(Preorderability $setting, array $taxons): Product
    {
        $product = new class() extends Product implements PreorderabilityAwareInterface {
            use PreorderabilityTrait;
        };
        $product->setPreorderability($setting);
        foreach ($taxons as $taxon) {
            $productTaxon = new ProductTaxon();
            $productTaxon->setTaxon($taxon);
            $product->addProductTaxon($productTaxon);
        }

        return $product;
    }

    private function taxon(Preorderability $setting, ?Taxon $parent = null): Taxon
    {
        $taxon = new class() extends Taxon implements PreorderabilityAwareInterface {
            use PreorderabilityTrait;
        };
        $taxon->setPreorderability($setting);
        $taxon->setParent($parent);

        return $taxon;
    }
}
