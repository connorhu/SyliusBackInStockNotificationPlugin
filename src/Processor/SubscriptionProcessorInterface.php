<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Processor;

use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Model\CustomerInterface;

interface SubscriptionProcessorInterface
{
    /**
     * Creates a pre-order for the current channel (unless the product cannot be pre-ordered or the e-mail already has
     * an active one) and sends the confirmation e-mail. A logged-in customer's own e-mail wins over the typed one.
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function process(
        ProductInterface $product,
        ?ProductVariantInterface $variant,
        string $email,
        ?string $name,
        ?CustomerInterface $customer,
        ?string $clientIp = null,
    ): ProcessResult;
}
