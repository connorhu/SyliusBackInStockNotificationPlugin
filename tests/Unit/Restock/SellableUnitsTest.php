<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Restock;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Inventory\Checker\AvailabilityChecker;
use Webgriffe\SyliusBackInStockNotificationPlugin\Restock\SellableUnits;

final class SellableUnitsTest extends TestCase
{
    private Channel $channel;

    private Product $product;

    private SellableUnits $sellableUnits;

    protected function setUp(): void
    {
        $this->channel = new Channel();
        $this->channel->setCode('WEB');
        $this->product = new Product();
        $this->product->setCode('BOOK');
        $this->product->addChannel($this->channel);
        $this->sellableUnits = new SellableUnits(new AvailabilityChecker());
    }

    public function testTrackedVariantHasOnHandMinusOnHold(): void
    {
        $variant = $this->variant(onHand: 5, onHold: 2);

        self::assertSame(3, $this->sellableUnits->of($variant, $this->channel));
    }

    public function testSoldOutVariantHasNoUnits(): void
    {
        self::assertSame(0, $this->sellableUnits->of($this->variant(onHand: 2, onHold: 2), $this->channel));
        self::assertSame(0, $this->sellableUnits->of($this->variant(onHand: 1, onHold: 3), $this->channel));
    }

    public function testUntrackedVariantIsUnlimited(): void
    {
        $variant = $this->variant(onHand: 0);
        $variant->setTracked(false);

        self::assertSame(\PHP_INT_MAX, $this->sellableUnits->of($variant, $this->channel));
    }

    public function testDisabledOrUnpricedVariantIsNotSellable(): void
    {
        $disabled = $this->variant(onHand: 3);
        $disabled->setEnabled(false);
        self::assertSame(0, $this->sellableUnits->of($disabled, $this->channel));

        $unpriced = $this->variant(onHand: 3, price: null);
        self::assertSame(0, $this->sellableUnits->of($unpriced, $this->channel));

        $withoutPricing = $this->variant(onHand: 3, pricingChannelCode: null);
        self::assertSame(0, $this->sellableUnits->of($withoutPricing, $this->channel));
    }

    public function testDisabledProductIsNotSellable(): void
    {
        $variant = $this->variant(onHand: 3);
        $this->product->setEnabled(false);

        self::assertSame(0, $this->sellableUnits->of($variant, $this->channel));
    }

    public function testOtherChannelStockIsIgnored(): void
    {
        $other = new Channel();
        $other->setCode('OTHER');

        // Priced and in stock, but the product is not in the OTHER channel.
        self::assertSame(0, $this->sellableUnits->of($this->variant(onHand: 3), $other));

        // The product is in the OTHER channel, but the variant is priced only in WEB.
        $this->product->addChannel($other);
        self::assertSame(0, $this->sellableUnits->of($this->variant(onHand: 3), $other));
    }

    private function variant(int $onHand, int $onHold = 0, ?int $price = 1000, ?string $pricingChannelCode = 'WEB'): ProductVariant
    {
        $variant = new ProductVariant();
        $variant->setCode('BOOK-' . spl_object_id($variant));
        $variant->setTracked(true);
        $variant->setOnHand($onHand);
        $variant->setOnHold($onHold);
        $this->product->addVariant($variant);
        if (null !== $pricingChannelCode) {
            $pricing = new ChannelPricing();
            $pricing->setChannelCode($pricingChannelCode);
            $pricing->setPrice($price);
            $variant->addChannelPricing($pricing);
        }

        return $variant;
    }
}
