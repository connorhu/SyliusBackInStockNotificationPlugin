<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Factory;

use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
use Sylius\Component\User\Canonicalizer\CanonicalizerInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webmozart\Assert\Assert;

final readonly class SubscriptionFactory implements SubscriptionFactoryInterface
{
    /**
     * @param class-string $className
     */
    public function __construct(private string $className, private CanonicalizerInterface $canonicalizer)
    {
    }

    public function createNew(): SubscriptionInterface
    {
        throw new \InvalidArgumentException('Default creation method is forbidden for this object. Use `createWithData` instead.');
    }

    public function createWithData(
        ChannelInterface $channel,
        ProductInterface $product,
        ?ProductVariantInterface $productVariant,
        string $email,
        ?string $name,
        string $localeCode,
        ?CustomerInterface $customer = null,
    ): SubscriptionInterface {
        /** @psalm-suppress MixedMethodCall */
        $subscription = new $this->className();
        Assert::isInstanceOf($subscription, SubscriptionInterface::class);

        $subscription->setChannel($channel);
        $subscription->setProduct($product);
        $subscription->setProductVariant($productVariant);
        $email = trim($email);
        $subscription->setEmail($email);
        $subscription->setEmailCanonical($this->canonicalizer->canonicalize($email));
        $subscription->setName($name);
        $subscription->setLocaleCode($localeCode);
        $subscription->setCustomer($customer);

        //I generate a random string to handle the delete action of the subscription using a GET
        //This way is easier and does not send sensible information
        //see: https://paragonie.com/blog/2015/09/comprehensive-guide-url-parameter-encryption-in-php
        $hash = strtr(base64_encode(random_bytes(9)), '+/', '-_');
        $subscription->setHash($hash);

        return $subscription;
    }
}
