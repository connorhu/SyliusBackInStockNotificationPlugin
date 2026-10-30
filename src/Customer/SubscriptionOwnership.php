<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Customer;

use Sylius\Component\Customer\Model\CustomerInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;

/**
 * Whose a pre-order is: the customer's own, or one made as a guest with the customer's canonical e-mail address (call 23).
 */
final class SubscriptionOwnership
{
    public static function isOwnedBy(SubscriptionInterface $subscription, ?CustomerInterface $customer): bool
    {
        if (null === $customer) {
            return false;
        }

        $owner = $subscription->getCustomer();
        if (null !== $owner) {
            return $owner === $customer || (null !== $owner->getId() && $owner->getId() === $customer->getId());
        }

        $emailCanonical = (string) $customer->getEmailCanonical();

        return '' !== $emailCanonical && $subscription->getEmailCanonical() === $emailCanonical;
    }
}
